<?php

namespace Tests\Feature\Firestore;

use App\Domain\ClassPosts\ClassPosts;
use App\Jobs\SignalClassFeedJob;
use App\Models\Assignment;
use App\Models\ClassPost;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LiveClassFeedTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    /**
     * A fake queue never runs the job, so its unique lock stays taken and the
     * next change would not queue. Cache::flush() leaves array store locks alone.
     */
    private function releaseSignalLock(): void
    {
        Cache::lock(UniqueLock::getKey(new SignalClassFeedJob($this->classroom->id)))->forceRelease();
    }

    public function test_publishing_a_post_immediately_queues_a_class_feed_signal(): void
    {
        Queue::fake();

        $this->asUser($this->teacher)->postJson('/api/v1/class-posts', [
            'classroom_ids' => [$this->classroom->id],
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'body' => 'ประกาศด่วน',
        ])->assertCreated();

        Queue::assertPushed(SignalClassFeedJob::class, 1);
        Queue::assertPushed(SignalClassFeedJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
    }

    public function test_saving_a_draft_post_queues_no_signal(): void
    {
        Queue::fake();

        $this->asUser($this->teacher)->postJson('/api/v1/class-posts', [
            'classroom_ids' => [$this->classroom->id],
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_DRAFT,
            'body' => 'ร่างประกาศ',
        ])->assertCreated();

        Queue::assertNothingPushed();
    }

    public function test_scheduled_post_signals_only_when_published_at_due_time(): void
    {
        Queue::fake();

        $time = now()->addHour();
        $this->asUser($this->teacher)->postJson('/api/v1/class-posts', [
            'classroom_ids' => [$this->classroom->id],
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_SCHEDULED,
            'scheduled_at' => $time->toIso8601String(),
            'body' => 'ประกาศตั้งเวลา',
        ])->assertCreated();

        Queue::assertNothingPushed();

        $this->travelTo($time->addMinute());
        $published = ClassPosts::publishDue();
        $this->assertSame(1, $published);

        Queue::assertPushed(SignalClassFeedJob::class, 1);
        Queue::assertPushed(SignalClassFeedJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
    }

    public function test_editing_a_published_post_signals_but_editing_a_draft_does_not(): void
    {
        $published = ClassPost::create([
            'classroom_id' => $this->classroom->id,
            'author_id' => $this->teacher->id,
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'body' => 'โพสต์เผยแพร่',
            'published_at' => now(),
            'audience' => ClassPost::AUDIENCE_ALL,
        ]);

        $draft = ClassPost::create([
            'classroom_id' => $this->classroom->id,
            'author_id' => $this->teacher->id,
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_DRAFT,
            'body' => 'ร่างโพสต์',
            'audience' => ClassPost::AUDIENCE_ALL,
        ]);

        Queue::fake();

        // Edit draft -> no signal
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$draft->id}", [
            'body' => 'แก้ฉบับร่าง',
        ])->assertOk();
        Queue::assertNothingPushed();

        // Edit published -> signals
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$published->id}", [
            'body' => 'แก้โพสต์เผยแพร่แล้ว',
        ])->assertOk();
        Queue::assertPushed(SignalClassFeedJob::class, 1);
        Queue::assertPushed(SignalClassFeedJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
    }

    public function test_deleting_a_published_post_signals_but_deleting_a_draft_does_not(): void
    {
        $published = ClassPost::create([
            'classroom_id' => $this->classroom->id,
            'author_id' => $this->teacher->id,
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'body' => 'โพสต์เผยแพร่',
            'published_at' => now(),
            'audience' => ClassPost::AUDIENCE_ALL,
        ]);

        $draft = ClassPost::create([
            'classroom_id' => $this->classroom->id,
            'author_id' => $this->teacher->id,
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_DRAFT,
            'body' => 'ร่างโพสต์',
            'audience' => ClassPost::AUDIENCE_ALL,
        ]);

        Queue::fake();

        // Delete draft -> no signal
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$draft->id}")->assertNoContent();
        Queue::assertNothingPushed();

        // Delete published -> signals
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$published->id}")->assertNoContent();
        Queue::assertPushed(SignalClassFeedJob::class, 1);
        Queue::assertPushed(SignalClassFeedJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
    }

    public function test_homework_signals_when_ready_or_closed_to_draft_but_not_draft_or_exam(): void
    {
        Queue::fake();

        // Creating as draft -> no signal
        $hw = Assignment::factory()->for_classroom($this->classroom)->create([
            'status' => Assignment::STATUS_DRAFT,
            'kind' => Assignment::KIND_HOMEWORK,
        ]);
        Queue::assertNothingPushed();

        // Creating an exam as ready -> no signal because kind is exam
        Assignment::factory()->for_classroom($this->classroom)->create([
            'status' => Assignment::STATUS_READY,
            'kind' => Assignment::KIND_EXAM,
        ]);
        Queue::assertNothingPushed();

        // Homework becomes ready -> signals
        $hw->update(['status' => Assignment::STATUS_READY]);
        Queue::assertPushed(SignalClassFeedJob::class, 1);

        $this->releaseSignalLock();

        // Homework goes back to draft -> signals
        $hw->update(['status' => Assignment::STATUS_DRAFT]);
        Queue::assertPushed(SignalClassFeedJob::class, 2);

        $this->releaseSignalLock();

        // Homework created directly as ready -> signals
        Assignment::factory()->for_classroom($this->classroom)->create([
            'status' => Assignment::STATUS_READY,
            'kind' => Assignment::KIND_HOMEWORK,
        ]);
        Queue::assertPushed(SignalClassFeedJob::class, 3);
    }

    public function test_no_signal_queued_and_api_succeeds_when_firestore_is_disabled(): void
    {
        $this->enableFirestore(false);
        Queue::fake();

        $this->asUser($this->teacher)->postJson('/api/v1/class-posts', [
            'classroom_ids' => [$this->classroom->id],
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'body' => 'เมื่อปิด Firestore',
        ])->assertCreated();

        Queue::assertNotPushed(SignalClassFeedJob::class);
    }
}
