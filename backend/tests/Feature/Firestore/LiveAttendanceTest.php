<?php

namespace Tests\Feature\Firestore;

use App\Jobs\SignalAttendanceJob;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

class LiveAttendanceTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);

        for ($i = 1; $i <= 3; $i++) {
            $this->students[] = $this->enrollStudent($this->classroom, $i)['student'];
        }
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    public function test_creating_updating_and_deleting_attendance_session_signals_each_time(): void
    {
        Queue::fake();

        // 1. Create session
        $res = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/attendance-sessions", [
            'classroom_id' => $this->classroom->id,
            'held_on' => now()->toDateString(),
            'records' => [
                ['student_id' => $this->students[0]->id, 'status' => 'present'],
            ],
        ])->assertCreated();
        $sessionId = $res->json('data.id');

        Queue::assertPushed(SignalAttendanceJob::class, 1);
        Queue::assertPushed(SignalAttendanceJob::class, fn ($job) => $job->courseId === $this->course->id && $job->classroomId === $this->classroom->id);

        Cache::flush();

        // 2. Update session
        $this->asUser($this->teacher)->putJson("/api/v1/attendance-sessions/{$sessionId}", [
            'note' => 'เปลี่ยนโน้ต',
        ])->assertOk();

        Queue::assertPushed(SignalAttendanceJob::class, 2);

        Cache::flush();

        // 3. Delete session
        $this->asUser($this->teacher)->deleteJson("/api/v1/attendance-sessions/{$sessionId}")->assertNoContent();

        Queue::assertPushed(SignalAttendanceJob::class, 3);
    }

    public function test_updating_attendance_scores_queues_no_signal(): void
    {
        Queue::fake();

        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/attendance/scores", [
            'present' => 1.0,
            'late' => 0.5,
            'absent' => 0.0,
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_running_job_writes_attendance_signals_for_students_only(): void
    {
        $store = new RecordingLiveStore;

        (new SignalAttendanceJob($this->course->id, $this->classroom->id))->handle($store);

        $expectedPaths = [
            "users/u{$this->students[0]->id}/attendance/{$this->course->id}_{$this->classroom->id}",
            "users/u{$this->students[1]->id}/attendance/{$this->course->id}_{$this->classroom->id}",
            "users/u{$this->students[2]->id}/attendance/{$this->course->id}_{$this->classroom->id}",
        ];

        $this->assertEqualsCanonicalizing($expectedPaths, $store->paths());
        $this->assertNotContains("users/u{$this->teacher->id}/attendance/{$this->course->id}_{$this->classroom->id}", $store->paths());

        $fields = $store->ops[0]['fields'];
        $this->assertSame($this->course->id, $fields['course_id']);
        $this->assertSame($this->classroom->id, $fields['classroom_id']);
        $this->assertSame(['course_id', 'classroom_id', 'updated_at', 'expires_at'], array_keys($fields));
    }

    public function test_no_signal_queued_when_firestore_is_disabled(): void
    {
        $this->enableFirestore(false);
        Queue::fake();

        $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/attendance-sessions", [
            'classroom_id' => $this->classroom->id,
            'held_on' => now()->toDateString(),
        ])->assertCreated();

        Queue::assertNothingPushed();
    }
}
