<?php

namespace Tests\Feature\Firestore;

use App\Jobs\SignalAttendanceJob;
use App\Jobs\SignalClassFeedJob;
use App\Models\ClassPost;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

class LiveClassroomPrivacyTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Classroom $otherClassroom;

    private Course $course;

    /** @var list<User> */
    private array $students = [];

    private User $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->otherClassroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);

        for ($i = 1; $i <= 3; $i++) {
            $this->students[] = $this->enrollStudent($this->classroom, $i)['student'];
        }

        $this->otherStudent = $this->enrollStudent($this->otherClassroom, 1)['student'];
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    public function test_post_signal_contains_no_post_title_or_body(): void
    {
        $post = ClassPost::create([
            'classroom_id' => $this->classroom->id,
            'author_id' => $this->teacher->id,
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'title' => 'หัวข้อลับ123',
            'body' => 'เนื้อหาลับ456',
            'published_at' => now(),
            'audience' => ClassPost::AUDIENCE_ALL,
        ]);

        $store = new RecordingLiveStore;
        (new SignalClassFeedJob($this->classroom->id))->handle($store);

        $json = json_encode($store->ops);
        $this->assertStringNotContainsString('หัวข้อลับ123', $json);
        $this->assertStringNotContainsString('เนื้อหาลับ456', $json);
    }

    public function test_attendance_signal_contains_no_status_or_notes(): void
    {
        $store = new RecordingLiveStore;
        (new SignalAttendanceJob($this->course->id, $this->classroom->id))->handle($store);

        $json = json_encode($store->ops);
        $this->assertStringNotContainsString('absent', $json);
        $this->assertStringNotContainsString('เหตุผลลับ789', $json);
    }

    public function test_all_document_fields_match_allowed_schema_strictly(): void
    {
        $feedStore = new RecordingLiveStore;
        (new SignalClassFeedJob($this->classroom->id))->handle($feedStore);

        foreach ($feedStore->ops as $op) {
            $this->assertSame(['classroom_id', 'updated_at', 'expires_at'], array_keys($op['fields']));
        }

        $attStore = new RecordingLiveStore;
        (new SignalAttendanceJob($this->course->id, $this->classroom->id))->handle($attStore);

        foreach ($attStore->ops as $op) {
            $this->assertSame(['course_id', 'classroom_id', 'updated_at', 'expires_at'], array_keys($op['fields']));
        }
    }

    public function test_targeted_post_signals_all_students_but_api_filters_visibility(): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            '*' => Http::response([], 200),
        ]);

        // Post targeted to student A (students[0]) only
        $res = $this->asUser($this->teacher)->postJson('/api/v1/class-posts', [
            'classroom_ids' => [$this->classroom->id],
            'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'state' => ClassPost::STATE_PUBLISHED,
            'body' => 'สำหรับนักเรียน A เท่านั้น',
            'audience' => ClassPost::AUDIENCE_STUDENTS,
            'student_ids' => [$this->students[0]->id],
        ])->assertCreated();

        $store = new RecordingLiveStore;
        (new SignalClassFeedJob($this->classroom->id))->handle($store);

        // Both student A and student B get the minimal signal
        $this->assertContains("users/u{$this->students[0]->id}/class_feed/{$this->classroom->id}", $store->paths());
        $this->assertContains("users/u{$this->students[1]->id}/class_feed/{$this->classroom->id}", $store->paths());

        // But REST API filters so student B sees 0 posts, while student A sees 1 post
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asUser($this->students[1])->getJson('/api/v1/student/class-feed')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_students_in_other_classrooms_receive_no_documents(): void
    {
        $feedStore = new RecordingLiveStore;
        (new SignalClassFeedJob($this->classroom->id))->handle($feedStore);
        $this->assertNotContains("users/u{$this->otherStudent->id}/class_feed/{$this->classroom->id}", $feedStore->paths());

        $attStore = new RecordingLiveStore;
        (new SignalAttendanceJob($this->course->id, $this->classroom->id))->handle($attStore);
        $this->assertNotContains("users/u{$this->otherStudent->id}/attendance/{$this->course->id}_{$this->classroom->id}", $attStore->paths());
    }
}
