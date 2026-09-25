<?php

namespace App\Domain\Notifications;

use App\Jobs\NotifyGradingFinishedJob;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * When the teacher hears "ตรวจ {title} เสร็จแล้ว" (DESIGN §7.2 step 7, §9.9).
 *
 * A teacher scans a class over several minutes and the cron worker drains the
 * queue every minute, so "nothing of the assignment is left to grade" comes
 * true again and again. Per assignment, the notice goes out only when
 *
 * - answers finished (scored or manual) since the last notice,
 * - nothing of the assignment is still queued, extracted or failed, and
 * - the last notice is at least COOLDOWN_SECONDS old.
 *
 * Inside the cooldown one delayed NotifyGradingFinishedJob is queued for the
 * moment it ends, so a later batch still gets its notice; with nothing new by
 * then it does nothing. The state lives in the cache (the database store in
 * production) under a per-assignment lock, so two workers that finish
 * together send one notice. The notifier runs after the lock, and its
 * failure is reported without failing the grading job.
 */
final class GradingNotices
{
    public const COOLDOWN_SECONDS = 600;

    private const STATE_TTL_DAYS = 30;

    public function __construct(private readonly Notifier $notifier) {}

    /** A grading pass has committed finished answers of the assignment. */
    public function answersFinished(Assignment $assignment): void
    {
        $this->decide($assignment, markPending: true, followUpDueAt: null);
    }

    /**
     * The delayed follow-up of a cooldown, due at $dueAt. Running earlier
     * means a queue that ignores delays (sync): it cannot wait, so it stops
     * instead of queueing itself again and again.
     */
    public function followUp(int $assignmentId, int $dueAt): void
    {
        if (now()->getTimestamp() < $dueAt) {
            return;
        }
        $assignment = Assignment::query()->with('classroom')->find($assignmentId);
        if ($assignment === null) {
            Cache::forget(self::key($assignmentId));

            return;
        }
        $this->decide($assignment, markPending: false, followUpDueAt: $dueAt);
    }

    public static function key(int $assignmentId): string
    {
        return "grading-notice:{$assignmentId}";
    }

    private function decide(Assignment $assignment, bool $markPending, ?int $followUpDueAt): void
    {
        $key = self::key($assignment->id);
        try {
            $action = Cache::lock("{$key}:lock", 30)->block(10, function () use ($assignment, $key, $markPending, $followUpDueAt) {
                $state = (array) Cache::get($key, []) + ['pending' => false, 'notified_at' => null, 'follow_up_at' => null];
                if ($markPending) {
                    $state['pending'] = true;
                }
                if ($followUpDueAt !== null && $state['follow_up_at'] === $followUpDueAt) {
                    $state['follow_up_at'] = null;
                }

                $action = null;
                if ($state['pending'] && ! self::inProgress($assignment)) {
                    $now = now()->getTimestamp();
                    $readyAt = (int) $state['notified_at'] + self::COOLDOWN_SECONDS;
                    if ($now >= $readyAt) {
                        $state['pending'] = false;
                        $state['notified_at'] = $now;
                        $action = 'notify';
                    } elseif ($state['follow_up_at'] === null || $now > $state['follow_up_at'] + self::COOLDOWN_SECONDS) {
                        // None queued yet, or the queued one was lost (jobs table cleared).
                        $state['follow_up_at'] = $readyAt;
                        $action = 'follow_up';
                    }
                }
                Cache::put($key, $state, now()->addDays(self::STATE_TTL_DAYS));

                return [$action, $state['follow_up_at']];
            });
        } catch (LockTimeoutException $e) {
            report($e);

            return;
        }

        [$what, $followUpAt] = $action;
        if ($what === 'follow_up') {
            NotifyGradingFinishedJob::dispatch($assignment->id, $followUpAt)->delay(Carbon::createFromTimestamp($followUpAt));
        } elseif ($what === 'notify') {
            $this->notify($assignment);
        }
    }

    private function notify(Assignment $assignment): void
    {
        $unpublished = fn () => $assignment->submissions()->where('status', '!=', Submission::STATUS_PUBLISHED)->select('id');
        $awaitingReview = Response::query()->whereIn('submission_id', $unpublished())->whereNull('reviewed_at')->count();
        $awaitingAiKey = Response::query()->whereIn('submission_id', $unpublished())->awaitingAiKey()->count();

        try {
            $this->notifier->gradingFinished($assignment, $awaitingReview, $awaitingAiKey);
        } catch (Throwable $e) {
            report($e); // a push outage must not fail or repeat the grading job
        }
    }

    private static function inProgress(Assignment $assignment): bool
    {
        return Response::query()
            ->whereIn('submission_id', $assignment->submissions()->select('id'))
            ->whereIn('grading_state', Response::IN_PROGRESS_STATES)
            ->exists();
    }
}
