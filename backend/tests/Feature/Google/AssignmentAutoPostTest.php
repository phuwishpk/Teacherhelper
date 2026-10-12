<?php

namespace Tests\Feature\Google;

use App\Domain\Google\AssignmentLinks;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassPostFile;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomGoogleTopic;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\GoogleAccount;
use App\Models\Question;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §30.12: homework posts itself to Google Classroom when it becomes
 * ready, with its files, its topic and a link back to Krucheck; the state
 * is kept on the assignment until the courseWork exists.
 */
class AssignmentAutoPostTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private Unit $unit;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://teacherhelper.example']);
        Storage::fake('local');
        $this->configureGoogle();
        $this->captureLogs();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $this->enrollStudent($this->classroom, 1, 'นักเรียน 1');
        $this->account = $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิต', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function fake(array $routes = []): void
    {
        $this->fakeGoogle($routes + [
            'classroom.googleapis.com/v1/courses/*/courseWork' => Http::response(['id' => self::COURSE_WORK_ID, 'alternateLink' => 'https://classroom.google.com/c/1/a/9']),
            'classroom.googleapis.com/v1/courses/*/topics*' => Http::sequence()
                ->push(['topic' => []])
                ->push(['topicId' => 'topic-1', 'name' => 'เศษส่วน'])
                ->whenEmpty(Http::response(['topic' => [['topicId' => 'topic-1', 'name' => 'เศษส่วน']]])),
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'drive-file-1']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function homework(array $body = []): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body + [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'แบบฝึกหัดเศษส่วน',
        ])->assertCreated()->json('data.id');
        Question::factory()->create(['assignment_id' => $id, 'max_points' => 4]);

        return Assignment::query()->findOrFail($id);
    }

    private function upload(string $name = 'ใบงาน.pdf'): int
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n");

        return $this->asUser($this->teacher)->post('/api/v1/class-post-files', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_ready_homework_posts_itself_with_its_files_topic_and_link_back(): void
    {
        $this->fake();
        $fileId = $this->upload();
        $assignment = $this->homework(['unit_id' => $this->unit->id, 'instructions' => 'ทำข้อ 1-5', 'attachments' => [['file_id' => $fileId], ['url' => 'https://youtu.be/abc']]]);
        $this->assertCount(0, $this->sentTo('/courseWork', 'POST'), 'a draft is not posted');

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertCreated();

        $sent = $this->sentTo('/courseWork', 'POST');
        $this->assertCount(1, $sent);
        $body = $sent[0]->data();
        $this->assertSame('แบบฝึกหัดเศษส่วน', $body['title']);
        $this->assertSame('PUBLISHED', $body['state']);
        $this->assertSame(4.0, $body['maxPoints']);
        $this->assertSame('topic-1', $body['topicId']);
        $this->assertSame([
            ['driveFile' => ['driveFile' => ['id' => 'drive-file-1'], 'shareMode' => 'VIEW']],
            ['link' => ['url' => 'https://youtu.be/abc']],
        ], $body['materials']);
        $this->assertStringContainsString('ทำข้อ 1-5', $body['description']);
        $this->assertStringContainsString(AssignmentLinks::url($assignment->id), $body['description']);
        $this->assertStringContainsString('https://teacherhelper.example/a/'.$assignment->id, $body['description']);
        $this->assertSame(['name' => 'เศษส่วน'], $this->sentTo('/topics', 'POST')[0]->data());
        $this->assertSame('drive-file-1', ClassPostFile::query()->findOrFail($fileId)->drive_file_id);
        $this->assertSame($this->unit->id, ClassroomGoogleTopic::query()->sole()->unit_id);

        $link = AssignmentGoogleLink::query()->findOrFail($assignment->id);
        $this->assertSame(self::COURSE_WORK_ID, $link->course_work_id);
        $this->assertSame($this->teacher->id, $link->posted_by);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}")->assertOk()
            ->assertJsonPath('data.google_post.state', 'sent')
            ->assertJsonPath('data.google_link.course_work_id', self::COURSE_WORK_ID);

        // The files of a posted assignment are locked; the same list passes.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['attachments' => [['file_id' => $fileId]]])->assertStatus(409)->assertJsonPath('code', 'attachments_locked');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['attachments' => [['file_id' => $fileId, 'title' => 'ชื่อใหม่'], ['url' => 'https://youtu.be/abc']]])->assertOk();
        // Posting by hand again is refused as before.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/google-post", ['attach_blank_worksheet' => false])->assertStatus(409)->assertJsonPath('code', 'already_posted');
    }

    public function test_nothing_is_posted_without_a_link_or_with_the_switch_off_and_the_switch_on_posts_later(): void
    {
        $this->fake();
        $other = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->makeCourse($this->teacher, [$other], ['code' => 'ค15102']);
        $unlinked = $this->homework(['classroom_id' => $other->id, 'course_id' => Course::query()->where('code', 'ค15102')->value('id')]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$unlinked->id}/layout")->assertCreated();
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$unlinked->id}")->assertOk()->assertJsonPath('data.google_post.state', 'not_sent');

        $off = $this->homework(['send_to_google' => false]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$off->id}/layout")->assertCreated();
        $this->assertCount(0, $this->sentTo('/courseWork', 'POST'));
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$off->id}")->assertOk()->assertJsonPath('data.google_post.state', 'not_sent');

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$off->id}", ['send_to_google' => true])->assertOk()->assertJsonPath('data.google_post.state', 'sent');
        $this->assertCount(1, $this->sentTo('/courseWork', 'POST'));
        $this->assertArrayNotHasKey('topicId', $this->sentTo('/courseWork', 'POST')[0]->data(), 'no unit, no topic');
    }

    public function test_homework_for_chosen_students_reaches_only_their_classroom_accounts(): void
    {
        $this->fake();
        $paired = User::query()->where('role', 'student')->firstOrFail();
        $unpaired = $this->enrollStudent($this->classroom, 2, 'นักเรียน 2')['student'];
        ClassroomStudent::query()->where('student_id', $paired->id)->update(['google_user_id' => 'g-1']);

        $assignment = $this->homework(['audience' => 'students', 'student_ids' => [$paired->id, $unpaired->id]]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertCreated();
        $body = $this->sentTo('/courseWork', 'POST')[0]->data();
        $this->assertSame('INDIVIDUAL_STUDENTS', $body['assigneeMode']);
        $this->assertSame(['studentIds' => ['g-1']], $body['individualStudentsOptions']);
        // Posted: who it is for cannot change any more.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['audience' => 'all'])->assertStatus(409)->assertJsonPath('code', 'audience_locked');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['student_ids' => [$paired->id, $unpaired->id]])->assertOk();

        // Nobody paired: not posted to the whole class by mistake.
        $only = $this->homework(['audience' => 'students', 'student_ids' => [$unpaired->id]]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$only->id}/layout")->assertCreated();
        $this->assertCount(1, $this->sentTo('/courseWork', 'POST'));
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$only->id}")->assertOk()
            ->assertJsonPath('data.google_post.state', 'failed')
            ->assertJsonPath('data.google_post.last_error', 'นักเรียนที่ได้รับมอบงานนี้ยังไม่มีใครจับคู่กับบัญชี Google จับคู่ในหน้ารายชื่อ Google ของห้องก่อน');
    }

    public function test_a_refusal_is_kept_on_the_assignment_and_a_post_by_hand_clears_it(): void
    {
        $this->fake(['classroom.googleapis.com/v1/courses/*/courseWork' => Http::sequence()
            ->push(self::googleError(403, 'PERMISSION_DENIED', 'The caller does not have permission', 'forbidden'), 403)
            ->push(['id' => self::COURSE_WORK_ID, 'alternateLink' => 'https://classroom.google.com/c/1/a/9'])]);
        $assignment = $this->homework();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertCreated();
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}")->assertOk()
            ->assertJsonPath('data.google_post.state', 'failed')
            ->assertJsonPath('data.google_link', null);
        $this->assertNotNull($assignment->refresh()->google_post_error);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/google-post", ['attach_blank_worksheet' => false, 'instructions' => 'ส่งภายในวันศุกร์'])->assertCreated();
        $assignment->refresh();
        $this->assertNull($assignment->google_post_state);
        $this->assertNull($assignment->google_post_error);
        $this->assertSame('ส่งภายในวันศุกร์', $assignment->instructions, 'the note of the dialog is the assignment text now');
        $this->assertStringContainsString('ส่งภายในวันศุกร์', $this->sentTo('/courseWork', 'POST')[1]->data()['description']);
    }
}
