<?php

namespace Tests\Feature\Api;

use App\Domain\Google\AssignmentLinks;
use App\Models\Assignment;
use App\Models\ClassPostFile;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Question;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §30.12: an assignment carries a topic (a unit or free text), a
 * text for the students and files and links, like a class post; the
 * students read them on the hand-in page and the feed, and /a/{id} opens
 * the hand-in from a Classroom description.
 */
class AssignmentPostsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private Unit $unit;

    /** @var list<User> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $this->students = [];
        foreach ([1, 2] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
    }

    private function upload(string $name = 'ใบงาน.pdf', ?User $as = null): int
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n");

        return $this->asUser($as ?? $this->teacher)->post('/api/v1/class-post-files', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function create(array $body = []): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body + [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'แบบฝึกหัดเศษส่วน',
        ]);
    }

    public function test_an_assignment_takes_a_unit_a_text_and_attachments(): void
    {
        $fileId = $this->upload();
        $id = $this->create([
            'unit_id' => $this->unit->id, 'topic' => 'ignored', 'instructions' => "  อ่านหน้า 12\nแล้วทำข้อ 1-5  ",
            'attachments' => [['file_id' => $fileId], ['url' => 'https://youtu.be/abc', 'title' => 'วิดีโอ']],
        ])->assertCreated()
            ->assertJsonPath('data.unit_id', $this->unit->id)
            ->assertJsonPath('data.unit.title', 'เศษส่วน')
            ->assertJsonPath('data.topic', 'เศษส่วน')
            ->assertJsonPath('data.instructions', "อ่านหน้า 12\nแล้วทำข้อ 1-5")
            ->assertJsonPath('data.send_to_google', true)
            ->assertJsonPath('data.attachments.0.kind', 'file')
            ->assertJsonPath('data.attachments.0.file.id', $fileId)
            ->assertJsonPath('data.attachments.1.kind', 'link')
            ->assertJsonPath('data.attachments.1.title', 'วิดีโอ')
            ->assertJsonPath('data.google_post.state', 'not_sent')
            ->json('data.id');

        // A free topic instead of a unit; the switch off.
        $this->create(['topic' => ' ทบทวน ก่อนสอบ ', 'send_to_google' => false])->assertCreated()
            ->assertJsonPath('data.unit', null)->assertJsonPath('data.topic', 'ทบทวน ก่อนสอบ')->assertJsonPath('data.send_to_google', false);
        // A unit of another course is refused; so are too many attachments and another teacher's file.
        $other = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ท15101', 'name' => 'ภาษาไทย']);
        $foreignUnit = Unit::create(['course_id' => $other->id, 'position' => 1, 'title' => 'คำควบกล้ำ']);
        $this->create(['unit_id' => $foreignUnit->id])->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
        $this->create(['attachments' => array_fill(0, 6, ['url' => 'https://example.com'])])->assertStatus(422)->assertJsonValidationErrors(['attachments']);
        $colleague = $this->makeTeacher($this->teacher->school);
        $this->create(['attachments' => [['file_id' => $this->upload('x.pdf', $colleague)]]])->assertStatus(422);
        $this->assertSame(2, Assignment::query()->count());
        $this->assertTrue(ClassPostFile::query()->findOrFail($fileId)->inUse());
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-post-files/{$fileId}")->assertStatus(409)->assertJsonPath('code', 'file_in_use');
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$id}")->assertOk()->assertJsonCount(2, 'data.attachments');
    }

    public function test_editing_replaces_the_attachments_and_a_course_change_drops_the_unit(): void
    {
        $first = $this->upload('ก.pdf');
        $second = $this->upload('ข.pdf');
        $id = $this->create(['unit_id' => $this->unit->id, 'attachments' => [['file_id' => $first]]])->assertCreated()->json('data.id');

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['attachments' => [['file_id' => $second], ['url' => 'https://example.com']], 'instructions' => 'ใหม่'])
            ->assertOk()->assertJsonCount(2, 'data.attachments')->assertJsonPath('data.attachments.0.file.id', $second)->assertJsonPath('data.instructions', 'ใหม่');
        $this->assertNull(ClassPostFile::query()->find($first), 'the file nothing uses any more is gone');
        Storage::disk('local')->assertMissing(ClassPostFile::query()->findOrFail($second)->path === '' ? 'x' : 'class-posts/never/none');

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['topic' => 'ทบทวน'])->assertOk()->assertJsonPath('data.unit', null)->assertJsonPath('data.topic', 'ทบทวน');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['unit_id' => $this->unit->id])->assertOk()->assertJsonPath('data.topic', 'เศษส่วน');

        $other = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ท15101', 'name' => 'ภาษาไทย']);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['course_id' => $other->id])->assertOk()->assertJsonPath('data.unit_id', null);

        // Deleting the draft drops its files when nothing else uses them.
        $this->asUser($this->teacher)->deleteJson("/api/v1/assignments/{$id}")->assertNoContent();
        $this->assertSame(0, ClassPostFile::query()->count());
    }

    public function test_students_of_the_classroom_read_the_text_the_topic_and_the_files_of_ready_homework(): void
    {
        $fileId = $this->upload();
        $assignment = Assignment::query()->findOrFail($this->create(['unit_id' => $this->unit->id, 'instructions' => 'อ่านก่อนทำ', 'attachments' => [['file_id' => $fileId]]])->json('data.id'));
        $url = "/api/v1/class-post-files/{$fileId}/file";
        // A draft is invisible to students, and so is its file.
        $this->asUser($this->students[0])->getJson('/api/v1/student/assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->students[0])->getJson($url)->assertNotFound();

        Question::factory()->create(['assignment_id' => $assignment->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertCreated();
        $this->asUser($this->students[0])->getJson('/api/v1/student/assignments')->assertOk()
            ->assertJsonPath('data.0.topic', 'เศษส่วน')
            ->assertJsonPath('data.0.instructions', 'อ่านก่อนทำ')
            ->assertJsonPath('data.0.attachments.0.file.download_url', $url);
        $this->asUser($this->students[0])->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()
            ->assertJsonPath('data.0.type', 'assignment')->assertJsonPath('data.0.topic', 'เศษส่วน');
        // A student of another classroom and a teacher of another school: 404.
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher, ['name' => 'ป.6/1']), 1, 'คนนอก')['student'];
        $this->asUser($outsider)->getJson($url)->assertNotFound();
        $this->asUser($this->makeTeacher($this->makeSchool()))->getJson($url)->assertNotFound();
    }

    public function test_the_assignment_link_page_opens_the_hand_in(): void
    {
        config(['app.url' => 'https://teacherhelper.example']);
        $this->assertSame('https://teacherhelper.example/a/42', AssignmentLinks::url(42));
        $response = $this->get('/a/42')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('intent://a/42#Intent;scheme=eduvision;package=com.eduvision.app;end', false)
            ->assertSee('เปิดการบ้านในแอป Krucheck');
        $this->assertStringNotContainsString('/student/assignments/42/hand-in', $response->getContent(), 'no web app installed: no web button');
        $this->get('/a/abc')->assertNotFound();
    }
}
