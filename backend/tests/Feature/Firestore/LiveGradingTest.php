<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\LiveGrading;
use App\Domain\Firestore\LiveStore;
use App\Jobs\MirrorAssignmentProgressJob;
use App\Jobs\MirrorSubmissionStatusJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

/**
 * Live grading status (DESIGN §38.9): a hand-in's new status queues one
 * document for its student and the per-status counts for every teacher of
 * the classroom, deduplicated per assignment, and nothing while Firestore
 * is off.
 */
class LiveGradingTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    private User $coTeacher;

    private Classroom $classroom;

    private Assignment $assignment;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->teacher = $this->makeTeacher();
        $this->coTeacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        ClassroomTeacher::create(['classroom_id' => $this->classroom->id, 'email' => (string) $this->coTeacher->email, 'teacher_id' => $this->coTeacher->id, 'invited_by' => $this->teacher->id, 'status' => ClassroomTeacher::STATUS_ACCEPTED, 'decided_at' => now()]);
        // An invitation not accepted yet sees nothing.
        ClassroomTeacher::create(['classroom_id' => $this->classroom->id, 'email' => 'pending@example.test', 'invited_by' => $this->teacher->id]);
        for ($i = 1; $i <= 3; $i++) {
            $this->students[] = $this->enrollStudent($this->classroom, $i)['student'];
        }
        $this->assignment = Assignment::factory()->for_classroom($this->classroom)->create(['title' => 'เศษส่วน']);
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    private function submit(User $student, string $status): Submission
    {
        return Submission::create(['assignment_id' => $this->assignment->id, 'student_id' => $student->id, 'status' => $status]);
    }

    public function test_a_new_hand_in_and_a_new_status_queue_both_mirrors_once_per_assignment(): void
    {
        Queue::fake();
        $a = $this->submit($this->students[0], Submission::STATUS_GRADING);
        $b = $this->submit($this->students[1], Submission::STATUS_GRADING);

        Queue::assertPushed(MirrorSubmissionStatusJob::class, 2);
        Queue::assertPushed(MirrorAssignmentProgressJob::class, 1); // unique per assignment until it runs
        Queue::assertPushed(MirrorAssignmentProgressJob::class, fn ($job) => $job->assignmentId === $this->assignment->id);

        // Saving without a status change queues nothing new.
        $a->forceFill(['total_score' => 1.5])->save();
        Queue::assertPushed(MirrorSubmissionStatusJob::class, 2);

        // The same hand-in again while its job waits: still one job for it.
        $b->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW])->save();
        Queue::assertPushed(MirrorSubmissionStatusJob::class, fn ($job) => $job->submissionId === $b->id);
        $this->assertCount(1, Queue::pushed(MirrorSubmissionStatusJob::class, fn ($job) => $job->submissionId === $b->id));
    }

    public function test_nothing_is_queued_while_firestore_is_off(): void
    {
        $this->enableFirestore(false);
        Queue::fake();
        $this->submit($this->students[0], Submission::STATUS_GRADING)->forceFill(['status' => Submission::STATUS_REVIEWED])->save();
        Queue::assertNothingPushed();
    }

    public function test_the_teacher_document_counts_every_status_for_every_teacher(): void
    {
        Queue::fake();
        $this->submit($this->students[0], Submission::STATUS_GRADING);
        $this->submit($this->students[1], Submission::STATUS_NEEDS_REVIEW);
        $this->submit($this->students[2], Submission::STATUS_PUBLISHED);
        $store = new RecordingLiveStore;

        (new MirrorAssignmentProgressJob($this->assignment->id))->handle($store);

        $this->assertSame(1, $store->commits, 'every teacher in one commit');
        $this->assertEqualsCanonicalizing([
            "users/u{$this->teacher->id}/assignments/{$this->assignment->id}",
            "users/u{$this->coTeacher->id}/assignments/{$this->assignment->id}",
        ], $store->paths());
        $fields = $store->ops[0]['fields'];
        $this->assertSame('เศษส่วน', $fields['title']);
        $this->assertSame(3, $fields['total']);
        $this->assertSame([0, 1, 1, 0, 1], [
            $fields['awaiting_scan'], $fields['grading'], $fields['needs_review'], $fields['reviewed'], $fields['published'],
        ]);
        $this->assertSame(0, $fields['awaiting_ai_key']);
        $this->assertTrue($fields['expires_at']->equalTo($fields['updated_at']->addDays(LiveGrading::RETENTION_DAYS)));
        $this->assertArrayNotHasKey('score', $fields);
    }

    public function test_the_student_document_has_the_status_and_no_score(): void
    {
        Queue::fake();
        $submission = $this->submit($this->students[0], Submission::STATUS_PUBLISHED);
        $submission->forceFill(['total_score' => 9.5])->save();
        $store = new RecordingLiveStore;

        (new MirrorSubmissionStatusJob($submission->id))->handle($store);

        $this->assertSame(["users/u{$this->students[0]->id}/submissions/{$submission->id}"], $store->paths());
        $fields = $store->ops[0]['fields'];
        $this->assertSame($this->assignment->id, $fields['assignment_id']);
        $this->assertSame('เศษส่วน', $fields['assignment_title']);
        $this->assertSame('published', $fields['status']);
        $this->assertSame(['assignment_id', 'assignment_title', 'status', 'updated_at', 'expires_at'], array_keys($fields));
    }

    public function test_jobs_for_rows_that_are_gone_do_nothing(): void
    {
        $store = new RecordingLiveStore;
        (new MirrorSubmissionStatusJob(999999))->handle($store);
        (new MirrorAssignmentProgressJob(999999))->handle($store);
        $this->assertSame([], $store->ops);
    }

    public function test_the_jobs_resolve_the_bound_store_through_the_queue(): void
    {
        $store = new RecordingLiveStore;
        $this->app->instance(LiveStore::class, $store);
        // Sync queue: the jobs run right after the commit.
        $submission = $this->submit($this->students[0], Submission::STATUS_GRADING);

        $this->assertContains("users/u{$this->students[0]->id}/submissions/{$submission->id}", $store->paths());
        $this->assertContains("users/u{$this->teacher->id}/assignments/{$this->assignment->id}", $store->paths());
    }
}
