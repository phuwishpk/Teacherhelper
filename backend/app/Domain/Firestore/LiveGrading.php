<?php

namespace App\Domain\Firestore;

use App\Domain\Classrooms\ClassroomAccess;
use App\Jobs\MirrorAssignmentProgressJob;
use App\Jobs\MirrorSubmissionStatusJob;
use App\Models\Assignment;
use App\Models\ClassroomTeacher;
use App\Models\Response;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live grading status (DESIGN §38.9, step 3). MariaDB stays the truth; the
 * queue copies two small documents into Firestore whenever a submission's
 * status changes:
 *
 *   users/{student}/submissions/{id}   status of that hand-in, no score
 *   users/{teacher}/assignments/{id}   how many hand-ins are in each status,
 *                                      for every teacher of the classroom
 *
 * Submission::saved queues both jobs after the commit. The assignment job is
 * unique per assignment until it starts, so grading a whole class writes the
 * counts a handful of times, not once per answer.
 */
final class LiveGrading
{
    public const RETENTION_DAYS = 90;

    public const STATUSES = [
        Submission::STATUS_AWAITING_SCAN,
        Submission::STATUS_GRADING,
        Submission::STATUS_NEEDS_REVIEW,
        Submission::STATUS_REVIEWED,
        Submission::STATUS_PUBLISHED,
    ];

    /** Called by Submission::saved when a hand-in is new or its status changed. */
    public static function queue(Submission $submission): void
    {
        if (! FirestoreConfig::enabled()) {
            return;
        }
        MirrorSubmissionStatusJob::dispatch($submission->id)->afterCommit();
        MirrorAssignmentProgressJob::dispatch((int) $submission->assignment_id)->afterCommit();
    }

    /**
     * The student's document.
     *
     * @return array<string, mixed>
     */
    public static function submissionFields(Submission $submission, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'assignment_id' => (int) $submission->assignment_id,
            'assignment_title' => (string) $submission->assignment?->title,
            'status' => (string) $submission->status,
            'updated_at' => $now,
            'expires_at' => $now->addDays(self::RETENTION_DAYS),
        ];
    }

    /**
     * The teachers' document: hand-ins per status and the answers waiting
     * for a Gemini key (the same rule as the grading-finished push).
     *
     * @return array<string, mixed>
     */
    public static function assignmentFields(Assignment $assignment, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (DB::table('submissions')->where('assignment_id', $assignment->id)
            ->groupBy('status')->selectRaw('status, count(*) as n')->get() as $row) {
            if (array_key_exists((string) $row->status, $counts)) {
                $counts[(string) $row->status] = (int) $row->n;
            }
        }
        $unpublished = DB::table('submissions')->select('id')
            ->where('assignment_id', $assignment->id)
            ->where('status', '!=', Submission::STATUS_PUBLISHED);

        return [
            'title' => (string) $assignment->title,
            'total' => array_sum($counts),
            ...$counts,
            'awaiting_ai_key' => Response::query()->whereIn('submission_id', $unpublished)->awaitingAiKey()->count(),
            'updated_at' => $now,
            'expires_at' => $now->addDays(self::RETENTION_DAYS),
        ];
    }

    /**
     * Who teaches the assignment's classroom: its owner, the teacher who owns
     * the work (the course creator) and the co-teachers who accepted.
     *
     * @return list<int>
     */
    public static function teacherIds(Assignment $assignment): array
    {
        $ids = [ClassroomAccess::managerId($assignment), $assignment->classroom?->teacher_id];
        if ($assignment->classroom_id !== null) {
            array_push($ids, ...ClassroomTeacher::query()
                ->where('classroom_id', $assignment->classroom_id)
                ->where('status', ClassroomTeacher::STATUS_ACCEPTED)
                ->whereNotNull('teacher_id')
                ->pluck('teacher_id')->all());
        }

        return array_values(array_unique(array_map(intval(...), array_filter($ids, fn ($id) => $id !== null))));
    }
}
