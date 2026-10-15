<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomFeedback;
use App\Domain\Google\ClassroomGradePusher;
use App\Domain\Google\ExamScores;
use App\Domain\Google\GoogleApiException;
use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends every scored student's exam score to Classroom (DESIGN §30.14):
 * grade and return their submission in the holder courseWork, and queue
 * the private result announcement of an app-graded exam. One student
 * failing does not stop the others; Google unreachable retries the job.
 */
class PushExamScoresJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 110;

    public function __construct(public readonly int $assignmentId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->assignmentId;
    }

    public function handle(ClassroomGradePusher $pusher): void
    {
        $exam = Assignment::query()->with(['classroom', 'googleLink'])->find($this->assignmentId);
        if ($exam === null || ! $exam->isExam() || $exam->googleLink === null) {
            return;
        }
        $transient = false;
        foreach (ExamScores::scores($exam) as $studentId => $score) {
            try {
                $pusher->pushGrade($exam, (int) $studentId, $score);
            } catch (GoogleApiException $e) {
                $transient = true;
            }
        }
        if (! $exam->isManualExam()) {
            // The private announcement with the score and the link (§19.7), as for homework.
            foreach (Submission::query()->where('assignment_id', $exam->id)->where('status', Submission::STATUS_PUBLISHED)->pluck('id') as $submissionId) {
                $post = ClassroomFeedback::queue((int) $submissionId, $created);
                if ($post !== null && $created) {
                    PostClassroomFeedbackJob::dispatch($post->id);
                }
            }
        }
        if ($transient && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
