<?php

namespace App\Domain\Google;

use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\Submission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Sends a published total to Google Classroom (DESIGN §18.2 "ส่งคะแนนกลับ",
 * §18.6): finds the student's studentSubmission in the assignment's
 * courseWork, sets assignedGrade = the effective total
 * (COALESCE(total_override, total_score), §19.3; with draftGrade, §19.7) and returns the work, so the student sees the
 * score in Classroom; the per-question explanations stay in our app.
 *
 * Runs from PushClassroomGradeJob with the Google account of the teacher who
 * posted the courseWork (only courseWork this project created accepts
 * grades). The submission is found through its import row, or by the
 * student's matched Google account for work handed in on paper (a row is
 * then created, so the result shows in the submissions list and can be
 * retried).
 *
 * A grade only ever goes to the Classroom submission of the Google account
 * matched to that student: a row filed under the student but handed in by
 * another account (a QR of student A inside B's hand-in, flagged
 * identity_mismatch by §18.3, or a roster re-matched since) ends as
 * grade_failed with the reason instead of grading the classmate's work.
 *
 * Outcome on the import row: `graded` + grade_pushed_at + pushed_grade (the
 * base of the grade-conflict check), or `grade_failed` + last_error (Thai,
 * shown to the teacher). A transient error is thrown for the queue to retry.
 * courseWork created on the Classroom website (origin classroom_web) is
 * skipped: Classroom refuses its grades from this project.
 */
final class ClassroomGradePusher
{
    public const GRADED = 'graded';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function __construct(private readonly GoogleAccessTokens $tokens) {}

    /**
     * @return self::GRADED|self::SKIPPED|self::FAILED
     *
     * @throws GoogleApiException only transient ones (unavailable)
     */
    public function push(int $submissionId): string
    {
        $submission = Submission::query()->with('assignment.classroom.googleLink', 'assignment.googleLink')->find($submissionId);
        $assignment = $submission?->assignment;
        $posted = $assignment?->googleLink;
        if ($submission === null || ! $submission->isPublished() || $posted === null) {
            return self::SKIPPED;
        }
        if ($posted->isFromClassroomWeb()) {
            // Created on the Classroom website: Classroom refuses grades from this
            // project (ProjectPermissionDenied, §19.3), so nothing is sent.
            return self::SKIPPED;
        }

        $import = self::importOf($assignment, $submission->student_id);
        $googleUserId = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->where('student_id', $submission->student_id)
            ->value('google_user_id');
        if ($import === null && $googleUserId === null) {
            return self::SKIPPED; // not in Classroom (or not matched yet)
        }
        if ($import !== null && $import->google_user_id !== $googleUserId) {
            // Someone else's hand-in carries this student's scan: never write
            // this student's score onto the classmate's Classroom submission.
            Log::warning('google.grade_account_mismatch', ['submission_id' => $submission->id, 'import_id' => $import->id]);

            return $this->fail($import, $submission, $googleUserId === null
                ? 'นักเรียนคนนี้ยังไม่ได้จับคู่กับบัญชี Google ที่ส่งงานนี้ จับคู่นักเรียนที่หน้าห้องเรียนแล้วกดส่งคะแนนอีกครั้ง'
                : 'บัญชี Google ของงานที่ส่งไม่ตรงกับนักเรียนคนนี้ ตรวจสอบป้าย identity_mismatch ในคิวตรวจทาน คะแนนของเจ้าของบัญชีจะส่งเมื่อเผยแพร่งานของเขา');
        }

        $link = $assignment->classroom?->googleLink;
        if ($link === null) {
            return $this->fail($import, $submission, 'ห้องเรียนไม่ได้ผูกกับ Google Classroom แล้ว ผูกคอร์สเดิมอีกครั้งแล้วกดส่งคะแนนอีกครั้ง');
        }
        $account = GoogleAccount::query()->find($posted->posted_by);
        if ($account === null || $account->needsReconnect()) {
            return $this->fail($import, $submission, 'บัญชี Google ของครูที่โพสต์งานนี้ไม่ได้เชื่อมอยู่ เชื่อมใหม่แล้วกดส่งคะแนนอีกครั้ง');
        }

        $api = GoogleApi::forAccount($account, $this->tokens);
        $grade = (float) $submission->effectiveTotal();

        try {
            $import ??= $this->findOnClassroom($api, $assignment, $link->course_id, $posted->course_work_id, (string) $googleUserId, $submission->student_id);
            if ($import === null) {
                Log::info('google.grade_skipped', ['submission_id' => $submission->id, 'reason' => 'no_classroom_submission']);

                return self::SKIPPED;
            }

            $patched = $api->setAssignedGrade($link->course_id, $posted->course_work_id, $import->google_submission_id, $grade);
            if (is_string($patched['updateTime'] ?? null)) {
                // So the next sync does not take our own change for a new hand-in.
                $import->google_update_time = mb_substr($patched['updateTime'], 0, 40);
            }

            $note = null;
            if (($patched['state'] ?? null) !== 'RETURNED') {
                try {
                    $api->returnSubmission($link->course_id, $posted->course_work_id, $import->google_submission_id);
                } catch (GoogleApiException $e) {
                    if ($e->kind !== GoogleApiException::FAILED_PRECONDITION) {
                        throw $e;
                    }
                    // The grade is set; Classroom only refuses to return work the
                    // student never handed in there (paper). §18.2 open point.
                    $note = 'ใส่คะแนนใน Classroom แล้ว แต่ส่งคืนงานไม่ได้ เพราะนักเรียนยังไม่ได้กดส่งงานใน Classroom';
                }
            }
        } catch (GoogleApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            Log::warning('google.grade_failed', ['submission_id' => $submission->id, 'kind' => $e->kind, 'message' => $e->getMessage()]);

            return $this->fail($import, $submission, GoogleErrors::shortText($e));
        }

        $import->state = ClassroomSubmissionImport::STATE_GRADED;
        $import->grade_pushed_at = now();
        // The base of the conflict check (§19.3): what the app sent is what Classroom shows now.
        $import->pushed_grade = $grade;
        $import->classroom_grade = $grade;
        $import->last_error = $note;
        $import->save();
        Log::info('google.grade_pushed', ['submission_id' => $submission->id, 'import_id' => $import->id]);

        return self::GRADED;
    }

    /**
     * Records a failure the queue gave up on (PushClassroomGradeJob after its
     * last attempt).
     */
    public function markFailed(int $submissionId, string $reason): void
    {
        $submission = Submission::query()->with('assignment')->find($submissionId);
        if ($submission?->assignment === null) {
            return;
        }
        $this->fail(self::importOf($submission->assignment, $submission->student_id), $submission, $reason);
    }

    /**
     * The row filed under the student, the one scanned last first. push()
     * still checks that its Google account is the student's own.
     */
    private static function importOf(Assignment $assignment, int $studentId): ?ClassroomSubmissionImport
    {
        return ClassroomSubmissionImport::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $studentId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The student's submission in the courseWork, for work handed in on
     * paper (no import row yet).
     */
    private function findOnClassroom(GoogleApi $api, Assignment $assignment, string $courseId, string $courseWorkId, string $googleUserId, int $studentId): ?ClassroomSubmissionImport
    {
        $found = $api->studentSubmissions($courseId, $courseWorkId, null, $googleUserId)[0] ?? null;
        if (! is_array($found) || ! is_string($found['id'] ?? null) || $found['id'] === '') {
            return null;
        }

        try {
            $row = ClassroomSubmissionImport::query()->firstOrCreate(
                ['google_submission_id' => $found['id']],
                [
                    'assignment_id' => $assignment->id,
                    'google_user_id' => $googleUserId,
                    'student_id' => $studentId,
                    // Nothing to scan: the paper was scanned with the camera.
                    'state' => ClassroomSubmissionImport::STATE_IMPORTED,
                    'attachments' => [],
                    'google_update_time' => mb_substr((string) ($found['updateTime'] ?? ''), 0, 40),
                    'alternate_link' => is_string($found['alternateLink'] ?? null) ? mb_substr($found['alternateLink'], 0, 512) : null,
                ],
            );
            if ($row->student_id === null) {
                // Synced before the roster match: it is this student's work.
                $row->student_id = $studentId;
                $row->save();
            }

            return $row;
        } catch (UniqueConstraintViolationException) {
            return ClassroomSubmissionImport::query()->where('google_submission_id', $found['id'])->first();
        }
    }

    private function fail(?ClassroomSubmissionImport $import, Submission $submission, string $reason): string
    {
        if ($import !== null) {
            $import->state = ClassroomSubmissionImport::STATE_GRADE_FAILED;
            $import->last_error = mb_substr($reason, 0, 255);
            $import->save();
        }
        Log::warning('google.grade_not_pushed', ['submission_id' => $submission->id, 'import_id' => $import?->id]);

        return self::FAILED;
    }
}
