<?php

namespace App\Domain\Google;

use App\Models\GoogleAccount;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The few Google Classroom v1 and Drive v3 REST calls EduVision makes
 * (DESIGN §18.2), through the Laravel HTTP client (no google/apiclient, which
 * is far too big for shared hosting). Every call sends the teacher's access
 * token as a Bearer header; a 401 drops the cached token and tries once more
 * with a fresh one.
 *
 * Errors come out as GoogleApiException (typed by kind). For a connected
 * account, invalid_grant and scope_missing also mark google_accounts.last_error,
 * so GET /google/status reports needs_reconnect.
 */
final class GoogleApi
{
    public const CLASSROOM = 'https://classroom.googleapis.com/v1';

    public const DRIVE = 'https://www.googleapis.com/drive/v3';

    public const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    /** A course roster or a courseWork's submissions never need more pages than this. */
    private const MAX_PAGES = 50;

    /**
     * @param  Closure(): string  $token
     * @param  (Closure(): void)|null  $forgetToken
     * @param  (Closure(GoogleApiException): void)|null  $onError
     */
    private function __construct(
        private readonly Closure $token,
        private readonly ?Closure $forgetToken = null,
        private readonly ?Closure $onError = null,
    ) {}

    /** Calls made with a token the caller already holds (POST /google/connect). */
    public static function withAccessToken(#[\SensitiveParameter] string $token): self
    {
        return new self(fn () => $token);
    }

    public static function forAccount(GoogleAccount $account, ?GoogleAccessTokens $tokens = null): self
    {
        $tokens ??= app(GoogleAccessTokens::class);

        return new self(
            fn () => $tokens->get($account),
            fn () => $tokens->forget($account),
            function (GoogleApiException $e) use ($account) {
                // A 401 that survives the one token retry means the grant is
                // gone, not just the cached access token: report it like a
                // failed refresh so GET /google/status says needs_reconnect.
                $error = match ($e->kind) {
                    GoogleApiException::INVALID_GRANT => GoogleAccount::ERROR_INVALID_GRANT,
                    GoogleApiException::SCOPE_MISSING => GoogleAccount::ERROR_SCOPE_MISSING,
                    default => null,
                };
                if ($error !== null) {
                    GoogleAccessTokens::markNeedsReconnect($account, $error);
                }
            },
        );
    }

    /**
     * userProfiles.get("me"): the Google user id (the OpenID `sub`) and the
     * e-mail address of the token's owner.
     *
     * @return array{id: string, email: string, name: string}
     */
    public function profile(): array
    {
        $body = $this->send('userProfiles.get', fn (PendingRequest $http) => $http->get(self::CLASSROOM.'/userProfiles/me'))->json();

        return [
            'id' => (string) ($body['id'] ?? ''),
            'email' => (string) ($body['emailAddress'] ?? ''),
            'name' => (string) ($body['name']['fullName'] ?? ''),
        ];
    }

    /**
     * courses.list: ACTIVE courses the teacher teaches.
     *
     * @return list<array{course_id: string, name: string, section: string|null}>
     */
    public function teacherCourses(): array
    {
        $courses = [];
        foreach ($this->pages('courses.list', self::CLASSROOM.'/courses', [
            'teacherId' => 'me',
            'courseStates' => 'ACTIVE',
            'pageSize' => 100,
        ], 'courses') as $course) {
            if (! isset($course['id'])) {
                continue;
            }
            $courses[] = [
                'course_id' => (string) $course['id'],
                'name' => (string) ($course['name'] ?? ''),
                'section' => isset($course['section']) && trim((string) $course['section']) !== '' ? (string) $course['section'] : null,
            ];
        }

        return $courses;
    }

    /**
     * courses.students.list with the profile (name, e-mail with the
     * classroom.profile.emails scope).
     *
     * @return list<array{google_user_id: string, name: string, email: string|null}>
     */
    public function courseStudents(string $courseId): array
    {
        $students = [];
        foreach ($this->pages('courses.students.list', self::CLASSROOM.'/courses/'.rawurlencode($courseId).'/students', ['pageSize' => 100], 'students') as $row) {
            $userId = (string) ($row['userId'] ?? $row['profile']['id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $email = $row['profile']['emailAddress'] ?? null;
            $students[] = [
                'google_user_id' => $userId,
                'name' => (string) ($row['profile']['name']['fullName'] ?? ''),
                'email' => is_string($email) && $email !== '' ? $email : null,
            ];
        }

        return $students;
    }

    /**
     * courses.courseWork.create.
     *
     * @param  array<string, mixed>  $courseWork
     * @return array{id: string, alternate_link: string}
     */
    public function createCourseWork(string $courseId, array $courseWork): array
    {
        $body = $this->send(
            'courseWork.create',
            fn (PendingRequest $http) => $http->post(self::CLASSROOM.'/courses/'.rawurlencode($courseId).'/courseWork', $courseWork),
        )->json();

        return [
            'id' => (string) ($body['id'] ?? ''),
            'alternate_link' => (string) ($body['alternateLink'] ?? ''),
        ];
    }

    /**
     * courses.courseWork.studentSubmissions.list, optionally for one state
     * (TURNED_IN) or one student (userId).
     *
     * @return list<array<string, mixed>> raw StudentSubmission resources
     */
    public function studentSubmissions(string $courseId, string $courseWorkId, ?string $state = null, ?string $userId = null): array
    {
        $query = ['pageSize' => 100];
        if ($state !== null) {
            $query['states'] = $state;
        }
        if ($userId !== null) {
            $query['userId'] = $userId;
        }

        return $this->pages('studentSubmissions.list', self::submissionsUrl($courseId, $courseWorkId), $query, 'studentSubmissions');
    }

    /**
     * studentSubmissions.patch with updateMask=assignedGrade. Only courseWork
     * this project created can be graded (else project_permission_denied).
     *
     * @return array<string, mixed> the updated StudentSubmission
     */
    public function setAssignedGrade(string $courseId, string $courseWorkId, string $submissionId, float $grade): array
    {
        return (array) $this->send(
            'studentSubmissions.patch',
            fn (PendingRequest $http) => $http->patch(
                self::submissionsUrl($courseId, $courseWorkId).'/'.rawurlencode($submissionId).'?updateMask=assignedGrade',
                ['assignedGrade' => $grade],
            ),
        )->json();
    }

    /** studentSubmissions.return: the student sees the work returned (and may hand in again). */
    public function returnSubmission(string $courseId, string $courseWorkId, string $submissionId): void
    {
        $this->send(
            'studentSubmissions.return',
            fn (PendingRequest $http) => $http->withBody('{}', 'application/json')
                ->post(self::submissionsUrl($courseId, $courseWorkId).'/'.rawurlencode($submissionId).':return'),
        );
    }

    /**
     * Drive files.create (multipart upload) of a PDF into the teacher's Drive
     * (drive.file scope: the app only sees files it created).
     *
     * @return string the Drive file id
     */
    public function uploadPdf(string $name, string $bytes): string
    {
        $boundary = 'eduvision-'.Str::random(24);
        $metadata = json_encode(['name' => $name, 'mimeType' => 'application/pdf'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: application/pdf\r\n\r\n{$bytes}\r\n--{$boundary}--";

        $response = $this->send(
            'drive.files.create',
            fn (PendingRequest $http) => $http->withBody($body, "multipart/related; boundary={$boundary}")
                ->post(self::DRIVE_UPLOAD.'/files?uploadType=multipart&fields=id'),
        );
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new GoogleApiException(GoogleApiException::BAD_REQUEST, 'drive.files.create answered without an id', $response->status());
        }

        return $id;
    }

    /** Drive files.delete; a file that is already gone counts as deleted. */
    public function deleteDriveFile(string $fileId): void
    {
        try {
            $this->send('drive.files.delete', fn (PendingRequest $http) => $http->delete(self::DRIVE.'/files/'.rawurlencode($fileId)));
        } catch (GoogleApiException $e) {
            if ($e->kind !== GoogleApiException::NOT_FOUND) {
                throw $e;
            }
        }
    }

    /** Drive files.get?fields=mimeType (drive.readonly). */
    public function driveMimeType(string $fileId): ?string
    {
        $mime = $this->send(
            'drive.files.get',
            fn (PendingRequest $http) => $http->get(self::DRIVE.'/files/'.rawurlencode($fileId), ['fields' => 'mimeType', 'supportsAllDrives' => 'true']),
        )->json('mimeType');

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private static function submissionsUrl(string $courseId, string $courseWorkId): string
    {
        return self::CLASSROOM.'/courses/'.rawurlencode($courseId).'/courseWork/'.rawurlencode($courseWorkId).'/studentSubmissions';
    }

    /**
     * Follows nextPageToken.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $what, string $url, array $query, string $key): array
    {
        $items = [];
        $pageToken = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = $pageToken === null ? $query : [...$query, 'pageToken' => $pageToken];
            $body = $this->send($what, fn (PendingRequest $http) => $http->get($url, $params))->json();
            foreach (is_array($body[$key] ?? null) ? $body[$key] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pageToken = is_string($body['nextPageToken'] ?? null) && $body['nextPageToken'] !== '' ? $body['nextPageToken'] : null;
            if ($pageToken === null) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws GoogleApiException
     */
    private function send(string $what, Closure $call): Response
    {
        try {
            $response = $this->attempt($what, $call);
            if ($response->status() === 401 && $this->forgetToken !== null) {
                ($this->forgetToken)();
                $response = $this->attempt($what, $call);
            }
            if (! $response->successful()) {
                throw GoogleApiException::fromApiResponse($response, $what);
            }
        } catch (GoogleApiException $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            throw $e;
        }

        return $response;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function attempt(string $what, Closure $call): Response
    {
        $http = Http::withToken(($this->token)())
            ->acceptJson()
            ->timeout(max(1, (int) config('services.google.timeout', 20)));

        try {
            return $call($http);
        } catch (ConnectionException) {
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, "{$what} failed: Google unreachable");
        }
    }
}
