<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "ดึงงานที่ส่ง" (DESIGN §18.2, §18.6 GET /assignments/{id}/google-submissions):
 * reads the TURNED_IN studentSubmissions of the assignment's courseWork and
 * keeps one classroom_submission_imports row per submission that has Drive
 * attachments. The phone downloads the files itself (§18.1: the pictures
 * never pass through the server).
 *
 * A row goes back to `new` when the student handed in again after being
 * sent back for a retake, or when the attached files changed. updateTime
 * alone is not enough: grading and returning change it too.
 * userId -> student comes from the roster match (classroom_students.google_user_id)
 * while the row is not scanned yet; after that the scans decide.
 */
final class GoogleSubmissionSync
{
    /** Rows whose student follows the roster match (nothing filed under a student yet). */
    public const FOLLOWS_ROSTER_STATES = [
        ClassroomSubmissionImport::STATE_NEW,
        ClassroomSubmissionImport::STATE_NEEDS_RETAKE,
        ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE,
    ];

    public const COURSE_WORK_GONE = 'ไม่พบงานนี้ใน Google Classroom แล้ว (อาจถูกลบในเว็บ Classroom)';

    /** Extensions whose type is certain enough to skip asking Drive. */
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
    ];

    public function __construct(private readonly GoogleAccounts $accounts) {}

    /**
     * @return Collection<int, ClassroomSubmissionImport> every row of the assignment, with `student`
     */
    public function sync(User $teacher, Assignment $assignment): Collection
    {
        [$posted, $link] = self::links($assignment);

        $this->accounts->call($teacher, function (GoogleApi $api) use ($assignment, $posted, $link) {
            $submissions = $api->studentSubmissions($link->course_id, $posted->course_work_id, 'TURNED_IN');
            $this->apply($api, $assignment, $submissions);
        }, self::COURSE_WORK_GONE);

        return self::rows($assignment);
    }

    /**
     * @return array{0: AssignmentGoogleLink, 1: ClassroomGoogleLink}
     *
     * @throws ApiException 409 not_posted, 422 classroom_not_linked
     */
    public static function links(Assignment $assignment): array
    {
        $posted = $assignment->googleLink()->first();
        if ($posted === null) {
            throw new ApiException('การบ้านนี้ยังไม่ได้โพสต์ลง Google Classroom', 'not_posted', 409);
        }

        return [$posted, GoogleRoster::linkOf($assignment->classroom()->firstOrFail())];
    }

    /**
     * @return Collection<int, ClassroomSubmissionImport>
     */
    public static function rows(Assignment $assignment): Collection
    {
        $rows = ClassroomSubmissionImport::query()->with('student')->where('assignment_id', $assignment->id)->get();
        $numbers = ClassroomStudent::query()->where('classroom_id', $assignment->classroom_id)->pluck('student_number', 'student_id');
        foreach ($rows as $row) {
            $row->setAttribute('student_number', $row->student_id !== null ? ($numbers[$row->student_id] ?? null) : null);
        }

        return $rows->sortBy(fn (ClassroomSubmissionImport $r) => [$r->student_number === null ? 1 : 0, (int) $r->student_number, $r->id])->values();
    }

    /**
     * @param  list<array<string, mixed>>  $submissions
     */
    private function apply(GoogleApi $api, Assignment $assignment, array $submissions): void
    {
        $existing = ClassroomSubmissionImport::query()->where('assignment_id', $assignment->id)->get()->keyBy('google_submission_id');
        $matched = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->whereNotNull('google_user_id')
            ->pluck('student_id', 'google_user_id');
        $created = 0;
        $renewed = 0;

        foreach ($submissions as $submission) {
            $id = (string) ($submission['id'] ?? '');
            $userId = (string) ($submission['userId'] ?? '');
            $files = self::driveFiles($submission);
            if ($id === '' || $userId === '' || $files === []) {
                continue;
            }

            /** @var ClassroomSubmissionImport|null $row */
            $row = $existing[$id] ?? null;
            if ($row === null && ClassroomSubmissionImport::query()->where('google_submission_id', $id)->exists()) {
                continue; // belongs to another assignment (cannot happen with Classroom's ids)
            }
            $row ??= new ClassroomSubmissionImport([
                'assignment_id' => $assignment->id,
                'google_submission_id' => $id,
                'google_user_id' => $userId,
                'state' => ClassroomSubmissionImport::STATE_NEW,
                'attachments' => [],
            ]);

            $known = [];
            foreach ($row->attachments ?? [] as $attachment) {
                $known[$attachment['drive_file_id']] = $attachment['mime_type'] ?? '';
            }
            $filesChanged = array_map('strval', array_keys($known)) !== array_column($files, 'id');
            $handedInAgain = $row->exists && $row->state === ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE;

            if (! $row->exists || $filesChanged || $handedInAgain) {
                $row->attachments = array_map(fn (array $file) => [
                    'drive_file_id' => $file['id'],
                    'title' => $file['title'],
                    'mime_type' => $known[$file['id']] ?? $this->mimeType($api, $file),
                ], $files);
                if ($row->exists) {
                    $row->state = ClassroomSubmissionImport::STATE_NEW;
                    $row->retake_reason = null;
                    $row->last_error = null;
                    $renewed++;
                } else {
                    $created++;
                }
            }

            $row->google_user_id = $userId;
            $row->google_update_time = mb_substr((string) ($submission['updateTime'] ?? ''), 0, 40);
            $alternate = $submission['alternateLink'] ?? null;
            $row->alternate_link = is_string($alternate) && $alternate !== '' ? mb_substr($alternate, 0, 512) : $row->alternate_link;
            if (in_array($row->state, self::FOLLOWS_ROSTER_STATES, true)) {
                $studentId = $matched[$userId] ?? null;
                $row->student_id = $studentId !== null ? (int) $studentId : null;
            }
            $row->save();
        }

        Log::info('google.submissions_synced', [
            'assignment_id' => $assignment->id,
            'turned_in' => count($submissions),
            'created' => $created,
            'renewed' => $renewed,
        ]);
    }

    /**
     * The Drive files of assignmentSubmission.attachments (links, forms and
     * videos are not photos of a worksheet).
     *
     * @param  array<string, mixed>  $submission
     * @return list<array{id: string, title: string}>
     */
    private static function driveFiles(array $submission): array
    {
        $files = [];
        foreach ($submission['assignmentSubmission']['attachments'] ?? [] as $attachment) {
            $file = is_array($attachment) ? ($attachment['driveFile'] ?? null) : null;
            if (is_array($file) && is_string($file['id'] ?? null) && $file['id'] !== '') {
                $files[] = ['id' => $file['id'], 'title' => mb_substr((string) ($file['title'] ?? ''), 0, 255)];
            }
        }

        return $files;
    }

    /**
     * From the file name when it is clear, else Drive's metadata; '' when
     * unknown (the phone sniffs the downloaded bytes anyway).
     *
     * @param  array{id: string, title: string}  $file
     */
    private function mimeType(GoogleApi $api, array $file): string
    {
        $extension = strtolower(pathinfo($file['title'], PATHINFO_EXTENSION));
        if (isset(self::MIME_BY_EXTENSION[$extension])) {
            return self::MIME_BY_EXTENSION[$extension];
        }

        try {
            return $api->driveMimeType($file['id']) ?? '';
        } catch (GoogleApiException $e) {
            if ($e->needsReconnect()) {
                throw $e;
            }

            return '';
        }
    }
}
