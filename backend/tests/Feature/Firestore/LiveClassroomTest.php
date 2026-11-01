<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\LiveClassroom;
use App\Domain\Firestore\LiveGrading;
use App\Jobs\SignalClassFeedJob;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

class LiveClassroomTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    private User $coTeacher;

    private User $courseTeacher;

    private Classroom $classroom;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->teacher = $this->makeTeacher();
        $this->coTeacher = $this->makeTeacher();
        $this->courseTeacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);

        // Bound course creator
        $this->makeCourse($this->courseTeacher, [$this->classroom]);

        // Co-teacher who accepted
        ClassroomTeacher::create([
            'classroom_id' => $this->classroom->id,
            'email' => (string) $this->coTeacher->email,
            'teacher_id' => $this->coTeacher->id,
            'invited_by' => $this->teacher->id,
            'status' => ClassroomTeacher::STATUS_ACCEPTED,
            'decided_at' => now(),
        ]);

        // An invitation not accepted yet
        ClassroomTeacher::create([
            'classroom_id' => $this->classroom->id,
            'email' => 'pending@example.test',
            'invited_by' => $this->teacher->id,
            'status' => ClassroomTeacher::STATUS_PENDING,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->students[] = $this->enrollStudent($this->classroom, $i)['student'];
        }
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    public function test_student_ids_returns_only_students_of_that_classroom(): void
    {
        $otherClassroom = $this->makeClassroom($this->teacher);
        $otherStudent = $this->enrollStudent($otherClassroom, 1)['student'];

        $ids = LiveClassroom::studentIds($this->classroom->id);

        $expectedIds = array_map(fn (User $u) => $u->id, $this->students);
        sort($expectedIds);
        $this->assertSame($expectedIds, $ids);
        $this->assertNotContains($otherStudent->id, $ids);
    }

    public function test_teacher_ids_includes_homeroom_course_creator_and_accepted_coteacher_without_duplicates(): void
    {
        // Also bind a course created by the homeroom teacher to test deduplication
        $this->makeCourse($this->teacher, [$this->classroom]);

        $ids = LiveClassroom::teacherIds($this->classroom->id);

        $this->assertEqualsCanonicalizing([
            $this->teacher->id,
            $this->courseTeacher->id,
            $this->coTeacher->id,
        ], $ids);
    }

    public function test_job_with_recording_live_store_writes_signals_for_everyone(): void
    {
        // Homeroom teacher also created a course
        $this->makeCourse($this->teacher, [$this->classroom]);
        $store = new RecordingLiveStore;

        (new SignalClassFeedJob($this->classroom->id))->handle($store);

        $expectedPaths = [
            "users/u{$this->teacher->id}/class_feed/{$this->classroom->id}",
            "users/u{$this->courseTeacher->id}/class_feed/{$this->classroom->id}",
            "users/u{$this->coTeacher->id}/class_feed/{$this->classroom->id}",
            "users/u{$this->students[0]->id}/class_feed/{$this->classroom->id}",
            "users/u{$this->students[1]->id}/class_feed/{$this->classroom->id}",
            "users/u{$this->students[2]->id}/class_feed/{$this->classroom->id}",
        ];

        $this->assertEqualsCanonicalizing($expectedPaths, $store->paths());
    }

    public function test_every_document_holds_only_minimal_fields_with_90_day_retention(): void
    {
        $store = new RecordingLiveStore;

        (new SignalClassFeedJob($this->classroom->id))->handle($store);

        $this->assertNotEmpty($store->ops);
        foreach ($store->ops as $op) {
            $fields = $op['fields'];
            $this->assertSame(['classroom_id', 'updated_at', 'expires_at'], array_keys($fields));
            $this->assertSame($this->classroom->id, $fields['classroom_id']);
            $this->assertTrue($fields['expires_at']->equalTo($fields['updated_at']->addDays(LiveGrading::RETENTION_DAYS)));
        }
    }

    public function test_feed_changed_pushes_a_unique_job_when_called_repeatedly(): void
    {
        Queue::fake();

        LiveClassroom::feedChanged($this->classroom->id);
        LiveClassroom::feedChanged($this->classroom->id);
        LiveClassroom::feedChanged($this->classroom->id);

        Queue::assertPushed(SignalClassFeedJob::class, 1);
        Queue::assertPushed(SignalClassFeedJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
    }

    public function test_feed_changed_pushes_nothing_when_firestore_is_disabled(): void
    {
        $this->enableFirestore(false);
        Queue::fake();

        LiveClassroom::feedChanged($this->classroom->id);

        Queue::assertNothingPushed();
    }
}
