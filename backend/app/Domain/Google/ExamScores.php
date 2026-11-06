<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Jobs\PushExamScoresJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GradebookEntry;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Exam scores into Google Classroom (DESIGN §30.6, §30.14): the exam itself
 * stays on paper, but the teacher sends the scores by hand after the
 * results are out. The first send makes an empty courseWork named after
 * the exam to hold them; every send then grades each scored student's
 * Classroom submission and returns it, and queues the private result
 * announcement of an app-graded exam like homework's.
 */
final class ExamScores
{
    public function __construct(private readonly GoogleAccounts $accounts) {}

    /**
     * Makes the courseWork if needed and queues the scores.
     *
     * @return array{queued: int, course_work_id: string}
     *
     * @throws ApiException
     */
    public function send(User $teacher, Assignment $exam): array
    {
        if (! $exam->isExam()) {
            throw new ApiException('คำสั่งนี้ใช้กับข้อสอบเท่านั้น', 'homework_kind_unsupported', 422);
        }
        $scores = self::scores($exam);
        if ($scores === []) {
            throw new ApiException('ยังไม่มีคะแนนที่ประกาศหรือกรอกไว้ให้ส่ง', 'no_results', 409);
        }
        $link = GoogleRoster::linkOfAssignment($exam);
        $this->accounts->accountOf($teacher);

        $posted = $exam->googleLink()->first() ?? $this->createHolder($teacher, $exam, $link->course_id);
        PushExamScoresJob::dispatch($exam->id);

        return ['queued' => count($scores), 'course_work_id' => $posted->course_work_id];
    }

    /**
     * The scores to send: the published totals of an app-graded exam, or
     * the typed scores of a manual one (excused students left out).
     *
     * @return array<int, float> student id => score
     */
    public static function scores(Assignment $exam): array
    {
        if ($exam->isManualExam()) {
            return GradebookEntry::query()
                ->where('assignment_id', $exam->id)
                ->where('excused', false)
                ->whereNotNull('score')
                ->pluck('score', 'student_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        return Submission::query()
            ->where('assignment_id', $exam->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->get()
            ->mapWithKeys(fn (Submission $s) => [(int) $s->student_id => (float) $s->effectiveTotal()])
            ->all();
    }

    /**
     * How the scores stand in Classroom, for the exam page.
     *
     * @return array{posted: bool, link: string|null, linked: bool, scored: int, graded: int, failed: int, unpaired: int, last_error: string|null}
     */
    public static function status(Assignment $exam): array
    {
        $posted = $exam->googleLink()->first();
        $scores = self::scores($exam);
        $paired = ClassroomStudent::query()->where('classroom_id', $exam->classroom_id)->whereNotNull('google_user_id')->pluck('student_id')->map(fn ($id) => (int) $id)->flip();
        $imports = ClassroomSubmissionImport::query()->where('assignment_id', $exam->id)->whereNotNull('student_id')
            ->orderBy('id')->get(['student_id', 'state', 'last_error'])->keyBy(fn ($r) => (int) $r->student_id);
        $graded = $failed = $unpaired = 0;
        $lastError = null;
        foreach (array_keys($scores) as $studentId) {
            if (! isset($paired[$studentId])) {
                $unpaired++;

                continue;
            }
            $row = $imports->get($studentId);
            if ($row?->state === ClassroomSubmissionImport::STATE_GRADED) {
                $graded++;
            } elseif ($row?->state === ClassroomSubmissionImport::STATE_GRADE_FAILED) {
                $failed++;
                $lastError ??= $row->last_error;
            }
        }

        return [
            'posted' => $posted !== null,
            'link' => $posted?->alternate_link,
            'linked' => ClassroomGoogleLink::forAssignment($exam) !== null,
            'scored' => count($scores),
            'graded' => $graded,
            'failed' => $failed,
            'unpaired' => $unpaired,
            'last_error' => $lastError,
        ];
    }

    /** The empty courseWork that holds the scores: the exam's title and full marks, no due date. */
    private function createHolder(User $teacher, Assignment $exam, string $courseId): AssignmentGoogleLink
    {
        $lock = Cache::lock("google-post:{$exam->id}", 120);
        if (! $lock->get()) {
            throw new ApiException('กำลังสร้างงานใน Classroom อยู่ รอสักครู่', 'google_post_in_progress', 409);
        }
        try {
            if ($existing = $exam->googleLink()->first()) {
                return $existing;
            }

            return $this->accounts->call($teacher, function (GoogleApi $api) use ($teacher, $exam, $courseId) {
                $work = $api->createCourseWork($courseId, [
                    'title' => $exam->title,
                    'description' => "คะแนนสอบ \"{$exam->title}\" จาก Krucheck",
                    'workType' => 'ASSIGNMENT',
                    'state' => 'PUBLISHED',
                    'maxPoints' => self::fullMarks($exam),
                ]);
                if ($work['id'] === '') {
                    throw new GoogleApiException(GoogleApiException::BAD_REQUEST, 'courseWork.create answered without an id');
                }
                $posted = AssignmentGoogleLink::create([
                    'assignment_id' => $exam->id,
                    'course_work_id' => $work['id'],
                    'alternate_link' => $work['alternate_link'],
                    'drive_file_id' => null,
                    'posted_by' => $teacher->id,
                    'posted_at' => now(),
                ]);
                Log::info('google.exam_holder_posted', ['assignment_id' => $exam->id, 'course_work_id' => $work['id']]);

                return $posted;
            }, GoogleRoster::COURSE_GONE);
        } finally {
            $lock->release();
        }
    }

    public static function fullMarks(Assignment $exam): float
    {
        return $exam->isManualExam()
            ? (float) ($exam->manual_full_marks ?? 0)
            : (float) $exam->questions()->sum('max_points');
    }
}
