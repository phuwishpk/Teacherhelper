<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\PushMessage;
use App\Events\AppealOpened;
use App\Events\AppealResolved;
use App\Events\SubmissionPublished;
use App\Listeners\NotifyStudentOfAppealResolution;
use App\Listeners\NotifyStudentOfPublishedResult;
use App\Listeners\NotifyTeacherOfAppeals;
use App\Models\Appeal;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Review\ReviewFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * The queued listeners behind the §9.9 pushes: they run on the cron worker
 * (queue `default`), coalesce appeal bursts and skip stale events.
 */
class NotificationListenersTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(1);
        $this->notifier = new RecordingNotifier;
    }

    private function publishedAnswers(): array
    {
        ['q1' => $a, 'q2' => $b] = $this->answerSheet($this->students[0]);
        $submission = $this->submission($this->students[0]);
        $this->reviewAll($submission);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()])->save();

        return [$a, $b, $submission];
    }

    public function test_listeners_are_queued_on_the_default_queue(): void
    {
        Queue::fake();
        [, , $submission] = $this->publishedAnswers();

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk(); // already published: no event
        Queue::assertNothingPushed();

        $submission->forceFill(['status' => Submission::STATUS_REVIEWED, 'published_at' => null])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();
        Queue::assertPushedOn('default', CallQueuedListener::class, fn ($job) => $job->class === NotifyStudentOfPublishedResult::class);

        foreach ([NotifyStudentOfPublishedResult::class, NotifyTeacherOfAppeals::class, NotifyStudentOfAppealResolution::class] as $listener) {
            $this->assertTrue(is_subclass_of($listener, ShouldQueue::class));
        }
        $this->assertSame(NotifyTeacherOfAppeals::DELAY_SECONDS, (new \ReflectionClass(NotifyTeacherOfAppeals::class))->newInstanceWithoutConstructor()->delay);
    }

    public function test_a_burst_of_appeals_gives_one_push_with_the_total(): void
    {
        [$a, $b] = $this->publishedAnswers();
        $first = Appeal::create(['response_id' => $a->id, 'student_id' => $this->students[0]->id]);
        $second = Appeal::create(['response_id' => $b->id, 'student_id' => $this->students[0]->id]);
        $listener = new NotifyTeacherOfAppeals($this->notifier);

        $listener->handle(new AppealOpened($first->id, $this->teacher->id));
        $this->assertSame([], $this->notifier->sent, 'a newer appeal will send');

        $listener->handle(new AppealOpened($second->id, $this->teacher->id));
        $this->assertSame([[[$this->teacher->id], 'มีคำขอให้ตรวจใหม่ 2 รายการ']], array_map(fn ($s) => [$s[0], $s[1]->body], $this->notifier->sent));

        // Both answered before the delayed job ran: nothing to say.
        Appeal::query()->update(['status' => Appeal::STATUS_REJECTED]);
        $listener->handle(new AppealOpened($second->id, $this->teacher->id));
        $this->assertCount(1, $this->notifier->sent);
    }

    public function test_stale_events_send_nothing(): void
    {
        [$a, , $submission] = $this->publishedAnswers();
        $appeal = Appeal::create(['response_id' => $a->id, 'student_id' => $this->students[0]->id]);

        // Reopened by a rescan before the worker ran.
        $submission->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW])->save();
        (new NotifyStudentOfPublishedResult($this->notifier))->handle(new SubmissionPublished($submission->id, $this->assignment->id, $this->students[0]->id, $this->teacher->id));
        // Still open.
        (new NotifyStudentOfAppealResolution($this->notifier))->handle(new AppealResolved($appeal->id, $a->id, $this->students[0]->id, false));

        $this->assertSame([], $this->notifier->sent);

        $appeal->update(['status' => Appeal::STATUS_ACCEPTED]);
        (new NotifyStudentOfAppealResolution($this->notifier))->handle(new AppealResolved($appeal->id, $a->id, $this->students[0]->id, false));
        $this->assertSame(PushMessage::APPEAL_RESOLVED, $this->notifier->sent[0][1]->type);
        $this->assertSame(['type' => 'appeal_resolved', 'submission_id' => (string) $submission->id, 'appeal_id' => (string) $appeal->id], $this->notifier->sent[0][1]->data());
    }

    public function test_a_failing_notifier_does_not_fail_the_job(): void
    {
        [, , $submission] = $this->publishedAnswers();
        $broken = new class extends RecordingNotifier
        {
            protected function push(array $userIds, PushMessage $message): void
            {
                throw new \RuntimeException('FCM down');
            }
        };

        (new NotifyStudentOfPublishedResult($broken))->handle(new SubmissionPublished($submission->id, $this->assignment->id, $this->students[0]->id, $this->teacher->id));

        $this->assertTrue(true, 'no exception escaped');
    }
}
