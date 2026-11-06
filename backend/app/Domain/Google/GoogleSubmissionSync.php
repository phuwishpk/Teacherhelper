<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Jobs\FetchClassroomAttachmentsJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleIgnoredUser;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;

/**
 * "ดึงงานที่ส่ง" (DESIGN §18.2, §18.6 GET /assignments/{id}/google-submissions):
 * reads the TURNED_IN studentSubmissions of the assignment's courseWork and
 * keeps one classroom_submission_imports row per submission that has Drive
 * attachments, with Classroom's `late` flag.
 *
 * Since Phase 8 (DESIGN §19.4, replacing §18.1) the server downloads the
 * files of every `new` row whose submitter is matched to a student
 * (FetchClassroomAttachmentsJob) and grades them from the whole page.
 *
 * A row goes back to `new` when the student handed in again after being
 * sent back for a retake, or when the attached files changed. updateTime
 * alone is not enough: grading and returning change it too.
 * userId -> student comes from the roster match (classroom_students.google_user_id)
 * while the row is not scanned yet; after that the scans decide.
 *
 * Since build step 4 (DESIGN §19.3) the same sync runs from the cron
 * (ClassroomSyncJob, with the account of the teacher who linked the course)
 * and also:
 * - reads RETURNED submissions for their assignedGrade only: every row
 *   stores Classroom's grade (classroom_grade) and GradeConflicts::detect
 *   compares it with the app's;
 * - applies the assignment's late policy: a late hand-in of an assignment
 *   that does not accept late work becomes `rejected_late` (not downloaded,
 *   not graded) until the teacher accepts it (POST .../accept-late);
 * - stamps assignment_google_links.last_synced_at.
 */
final class GoogleSubmissionSync
{
    /** Rows whose student follows the roster match (nothing filed under a student yet). */
    public const FOLLOWS_ROSTER_STATES = [
        ClassroomSubmissionImport::STATE_NEW,
        ClassroomSubmissionImport::STATE_NEEDS_RETAKE,
        ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE,
        ClassroomSubmissionImport::STATE_REJECTED_LATE,
    ];

    /**
     * Hand-ins (TURNED_IN) and work already returned (RETURNED), whose
     * assignedGrade the teacher may have changed on the website (§19.3).
     */
    public const LISTED_STATES = ['TURNED_IN', 'RETURNED'];

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
            $submissions = $api->studentSubmissions($link->course_id, $posted->course_work_id, self::LISTED_STATES);
            $this->apply($api, $assignment, $posted, $submissions);
        }, self::COURSE_WORK_GONE);

        $posted->last_synced_at = now();
        $posted->save();

        return self::rows($assignment);
    }

    /**
     * The cron's sync of one assignment (ClassroomSyncJob), with the Google
     * account of the teacher who linked the course the assignment goes
     * through (its manager's, DESIGN §24.10).
     *
     * @throws ApiException
     */
    public function syncAsOwner(Assignment $assignment): void
    {
        [, $link] = self::links($assignment);
        $owner = User::query()->find($link->owner_user_id);
        if ($owner === null) {
            throw GoogleErrors::notConnected();
        }
        $this->sync($owner, $assignment);
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

        return [$posted, GoogleRoster::linkOfAssignment($assignment)];
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
    private function apply(GoogleApi $api, Assignment $assignment, AssignmentGoogleLink $posted, array $submissions): void
    {
        $existing = ClassroomSubmissionImport::query()->where('assignment_id', $assignment->id)->get()->keyBy('google_submission_id');
        $matched = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->whereNotNull('google_user_id')
            ->pluck('student_id', 'google_user_id');
        $created = 0;
        $renewed = 0;
        $rejectedLate = 0;
        $conflicts = 0;
        $handIns = [];

        foreach ($submissions as $submission) {
            $id = (string) ($submission['id'] ?? '');
            $userId = (string) ($submission['userId'] ?? '');
            if ($id === '' || $userId === '') {
                continue;
            }
            /** @var ClassroomSubmissionImport|null $row */
            $row = $existing[$id] ?? null;
            $turnedIn = ($submission['state'] ?? 'TURNED_IN') === 'TURNED_IN';
            $files = self::driveFiles($submission);

            if ($turnedIn && $files !== []) {
                $handIns[] = $submission;
                $row = $this->applyHandIn($api, $assignment, $submission, $row, $files, $matched, $created, $renewed, $rejectedLate);
            }
            if ($row === null || ! $row->exists) {
                continue; // not handed in with files, and nothing synced before
            }

            $grade = $submission['assignedGrade'] ?? null;
            $row->classroom_grade = is_numeric($grade) ? round((float) $grade, 2) : null;
            $row->save();
            if (GradeConflicts::detect($row, $posted)?->wasRecentlyCreated) {
                $conflicts++;
            }
        }

        $this->syncRosterIfUnknown($assignment, $handIns, $matched->keys()->map(fn ($id) => (string) $id)->all());
        $fetching = FetchClassroomAttachmentsJob::dispatchForNewRows($assignment);

        Log::info('google.submissions_synced', [
            'assignment_id' => $assignment->id,
            'listed' => count($submissions),
            'turned_in' => count($handIns),
            'created' => $created,
            'renewed' => $renewed,
            'rejected_late' => $rejectedLate,
            'conflicts' => $conflicts,
            'fetching' => $fetching,
        ]);
    }

    /**
     * A TURNED_IN submission with Drive files: creates its row, or puts it
     * back to `new` when the files changed or it answers a retake request.
     * A late hand-in of an assignment that does not accept late work waits
     * as `rejected_late` instead (§19.3).
     *
     * @param  array<string, mixed>  $submission
     * @param  list<array{id: string, title: string}>  $files
     * @param  SupportCollection<string, int>  $matched  google user id -> student id
     */
    private function applyHandIn(
        GoogleApi $api,
        Assignment $assignment,
        array $submission,
        ?ClassroomSubmissionImport $row,
        array $files,
        SupportCollection $matched,
        int &$created,
        int &$renewed,
        int &$rejectedLate,
    ): ?ClassroomSubmissionImport {
        $id = (string) $submission['id'];
        $userId = (string) $submission['userId'];
        if ($row === null && ClassroomSubmissionImport::query()->where('google_submission_id', $id)->exists()) {
            return null; // belongs to another assignment (cannot happen with Classroom's ids)
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
        $renew = ! $row->exists || $filesChanged || $handedInAgain;
        $late = ($submission['late'] ?? false) === true;

        if ($renew) {
            $row->attachments = array_map(fn (array $file) => [
                'drive_file_id' => $file['id'],
                'title' => $file['title'],
                'mime_type' => $known[$file['id']] ?? $this->mimeType($api, $file),
            ], $files);
            if ($row->exists) {
                $row->state = ClassroomSubmissionImport::STATE_NEW;
                if (! $handedInAgain) {
                    // Kept after a retake request: the fetch grades that hand-in
                    // at once (§19.4); any other new hand-in waits for "ตรวจ".
                    $row->retake_reason = null;
                }
                $row->last_error = null;
                $renewed++;
            } else {
                $created++;
            }
            $row->late = $late;
            if ($late && ! $assignment->accept_late) {
                // Not downloaded nor graded until the teacher accepts it (§19.3).
                $row->state = ClassroomSubmissionImport::STATE_REJECTED_LATE;
                $rejectedLate++;
            }
        } elseif ($row->state !== ClassroomSubmissionImport::STATE_NEW) {
            // A late hand-in the teacher accepted stays `new` + late until it is fetched.
            $row->late = $late;
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

        return $row;
    }

    /**
     * A Google user who handed in but is matched to no student may be new in
     * the course: queue a roster sync (DESIGN §19.2), at most once per
     * classroom per sync round. Accounts the teacher removed at import stay out.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @param  list<string>  $matchedIds
     */
    private function syncRosterIfUnknown(Assignment $assignment, array $submissions, array $matchedIds): void
    {
        $known = array_flip([
            ...$matchedIds,
            ...array_map('strval', ClassroomGoogleIgnoredUser::query()->where('classroom_id', $assignment->classroom_id)->pluck('google_user_id')->all()),
        ]);
        foreach ($submissions as $submission) {
            $userId = (string) ($submission['userId'] ?? '');
            if ($userId !== '' && ! isset($known[$userId])) {
                SyncClassroomRosterJob::dispatchOncePerRound($assignment->classroom_id);

                return;
            }
        }
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
