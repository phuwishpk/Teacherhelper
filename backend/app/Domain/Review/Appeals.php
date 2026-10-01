<?php

namespace App\Domain\Review;

use App\Domain\Classrooms\ClassroomAccess;
use App\Events\AppealOpened;
use App\Events\AppealResolved;
use App\Exceptions\ApiException;
use App\Models\Appeal;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appeals (DESIGN §13): after publishing, a student may ask the teacher to
 * re-check an answer once (appeals.response_id is UNIQUE). The teacher
 * accepts, optionally with a new score, or rejects, with a note. Both are
 * logged to score_events (appeal_accepted / appeal_rejected, old and new
 * score) and the student is told by push. The submission stays published,
 * so an accepted new score is what the student sees from then on.
 *
 * An appeal waits while a confirmed rescan has reopened the submission
 * (SubmissionStatus::refresh reopen): the answer is being graded and
 * reviewed again and the student cannot see it, so resolve() answers 409
 * submission_not_published (or response_grading) until it is published
 * again; the appeal stays open for the new result.
 */
final class Appeals
{
    /**
     * Appeals on answers of assignments the teacher manages (DESIGN §24.8:
     * the course's teacher answers them, not the homeroom teacher).
     *
     * @return Builder<Appeal>
     */
    public static function forTeacher(User $teacher): Builder
    {
        return Appeal::query()->whereIn('response_id', Response::query()->select('id')->whereIn(
            'submission_id',
            Submission::query()->select('id')->whereIn(
                'assignment_id',
                ClassroomAccess::managedAssignments($teacher)->select('assignments.id'),
            ),
        ));
    }

    /**
     * Open appeals the teacher has to answer.
     *
     * @return Builder<Appeal>
     */
    public static function openForTeacher(int $teacherId): Builder
    {
        $teacher = User::query()->find($teacherId);
        if ($teacher === null) {
            return Appeal::query()->whereRaw('1 = 0');
        }

        return Appeal::query()
            ->where('appeals.status', Appeal::STATUS_OPEN)
            ->whereIn('response_id', Response::query()->select('id')->whereIn(
                'submission_id',
                Submission::query()->select('id')->whereIn(
                    'assignment_id',
                    ClassroomAccess::managedAssignments($teacher)->select('assignments.id'),
                ),
            ));
    }

    /**
     * @throws ApiException 409 appeal_exists | submission_not_published
     */
    public function open(User $student, Response $response, ?string $reason): Appeal
    {
        $reason = $reason === null ? null : (trim($reason) === '' ? null : trim($reason));

        return DB::transaction(function () use ($student, $response, $reason) {
            $submission = Submission::query()->with('assignment.classroom')->lockForUpdate()->findOrFail($response->submission_id);
            if (! $submission->isPublished() || $submission->student_id !== $student->id) {
                throw new ApiException('ขอให้ตรวจใหม่ได้เฉพาะผลที่ครูเผยแพร่แล้ว', 'submission_not_published', 409);
            }
            if (Appeal::query()->where('response_id', $response->id)->exists()) {
                throw self::alreadyAppealed();
            }

            try {
                $appeal = Appeal::create([
                    'response_id' => $response->id,
                    'student_id' => $student->id,
                    'reason' => $reason,
                    'status' => Appeal::STATUS_OPEN,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw self::alreadyAppealed();
            }

            $teacherId = $submission->assignment === null ? null : ClassroomAccess::managerId($submission->assignment);
            if ($teacherId !== null) {
                AppealOpened::dispatch($appeal->id, $teacherId);
            }

            return $appeal;
        });
    }

    /**
     * @param  array{status: string, teacher_note?: string|null, final_score?: float|int|string|null, final_understanding?: string|null}  $data
     *
     * @throws ApiException 409 appeal_resolved | submission_not_published | response_grading
     */
    public function resolve(User $teacher, Appeal $appeal, array $data): Appeal
    {
        return DB::transaction(function () use ($teacher, $appeal, $data) {
            $response = Response::query()->with('question')->findOrFail($appeal->response_id);
            $submission = Submission::query()->lockForUpdate()->findOrFail($response->submission_id);
            $appeal = Appeal::query()->lockForUpdate()->findOrFail($appeal->id);
            $response = Response::query()->with('question')->lockForUpdate()->findOrFail($appeal->response_id);
            if ($appeal->status !== Appeal::STATUS_OPEN) {
                throw new ApiException('ตอบคำขอนี้ไปแล้ว', 'appeal_resolved', 409);
            }
            if (! $submission->isPublished()) {
                throw new ApiException('ข้อนี้ถูกสแกนใหม่และกำลังตรวจอีกครั้ง ตอบคำขอได้หลังเผยแพร่ผลใหม่', 'submission_not_published', 409);
            }
            if (in_array($response->grading_state, Response::IN_PROGRESS_STATES, true)) {
                throw new ApiException('ข้อนี้ AI ยังตรวจไม่เสร็จ รอสักครู่แล้วลองใหม่', 'response_grading', 409);
            }

            $note = isset($data['teacher_note']) && trim((string) $data['teacher_note']) !== '' ? trim((string) $data['teacher_note']) : null;
            $accepted = $data['status'] === Appeal::STATUS_ACCEPTED;
            $oldScore = $response->effectiveScore();
            $oldUnderstanding = $response->effectiveUnderstanding();
            $newScore = $oldScore;
            $newUnderstanding = $oldUnderstanding;

            if ($accepted && isset($data['final_score'])) {
                $newScore = round((float) $data['final_score'], 2);
                if (($error = ScoreRules::invalid($newScore, $response->question)) !== null) {
                    throw ValidationException::withMessages(['final_score' => [$error]]);
                }
            }
            if ($accepted && isset($data['final_understanding'])) {
                $newUnderstanding = $data['final_understanding'];
            }
            $changed = ScoreRules::differs($oldScore, $newScore) || $oldUnderstanding !== $newUnderstanding;

            if ($changed) {
                $response->final_score = $newScore;
                $response->final_understanding = $newUnderstanding;
                $response->reviewed_by = $teacher->id;
                $response->reviewed_at = now();
                $response->save();
                Publisher::refreshTotal($submission);
            }

            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => ScoreEvent::ACTOR_TEACHER,
                'actor_user_id' => $teacher->id,
                'action' => $accepted ? ScoreEvent::ACTION_APPEAL_ACCEPTED : ScoreEvent::ACTION_APPEAL_REJECTED,
                'old_score' => $oldScore,
                'new_score' => $newScore,
                'old_understanding' => $oldUnderstanding,
                'new_understanding' => $newUnderstanding,
                'reason' => $note,
            ]);

            $appeal->status = $accepted ? Appeal::STATUS_ACCEPTED : Appeal::STATUS_REJECTED;
            $appeal->teacher_note = $note;
            $appeal->resolved_by = $teacher->id;
            $appeal->resolved_at = now();
            $appeal->save();

            AppealResolved::dispatch($appeal->id, $response->id, $appeal->student_id, $changed);

            return $appeal;
        });
    }

    private static function alreadyAppealed(): ApiException
    {
        return new ApiException('ขอให้ครูตรวจข้อนี้ใหม่ไปแล้ว (ขอได้ข้อละครั้ง)', 'appeal_exists', 409);
    }
}
