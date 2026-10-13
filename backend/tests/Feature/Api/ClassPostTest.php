<?php

namespace Tests\Feature\Api;

use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Notifications\Notifier;
use App\Events\ClassPostPublished;
use App\Listeners\NotifyStudentsOfClassPost;
use App\Models\ClassPost;
use App\Models\ClassPostFile;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * DESIGN §30 step 1: class posts in Krucheck (announcements and materials,
 * files and links, drafts, scheduling, several classrooms, single
 * students) and the student's feed.
 */
class ClassPostTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    /** @var list<User> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->students = [];
        foreach ([1, 2, 3] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body = [], ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->teacher)->postJson('/api/v1/class-posts', $body + [
            'classroom_ids' => [$this->classroom->id], 'kind' => 'announcement', 'state' => 'published', 'body' => 'พรุ่งนี้สอบย่อย',
        ]);
    }

    private function upload(string $name = 'ใบความรู้.pdf', ?User $as = null): int
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return $this->asUser($as ?? $this->teacher)->post('/api/v1/class-post-files', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
    }

    public function test_an_announcement_is_published_for_the_whole_classroom_and_reaches_its_students(): void
    {
        Event::fake([ClassPostPublished::class]);

        $id = $this->send(['body' => "  พรุ่งนี้สอบย่อย\nเตรียมดินสอ  ", 'title' => 'ไม่ใช้'])
            ->assertCreated()
            ->assertJsonPath('data.0.kind', 'announcement')
            ->assertJsonPath('data.0.title', null)
            ->assertJsonPath('data.0.body', "พรุ่งนี้สอบย่อย\nเตรียมดินสอ")
            ->assertJsonPath('data.0.state', 'published')
            ->assertJsonPath('data.0.audience', 'all')
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonPath('data.0.send_to_google', true)
            ->assertJsonPath('data.0.batch_id', null)
            ->assertJsonPath('data.0.classroom.id', $this->classroom->id)
            ->json('data.0.id');
        Event::assertDispatched(ClassPostPublished::class, fn (ClassPostPublished $e) => $e->postId === $id);
        $this->assertNotNull(ClassPost::query()->findOrFail($id)->published_at);

        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'post')
            ->assertJsonPath('data.0.post.id', $id)
            ->assertJsonPath('data.0.post.author.name', $this->teacher->name)
            ->assertJsonMissingPath('data.0.post.state')
            ->assertJsonMissingPath('data.0.post.student_ids');
        $this->asUser($this->students[0])->getJson("/api/v1/student/class-posts/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_a_material_needs_a_title_and_takes_a_unit_or_a_free_topic(): void
    {
        $this->send(['kind' => 'material', 'body' => 'อ่านก่อนเรียน'])->assertStatus(422)->assertJsonPath('errors.title.0', 'กรอกชื่อเรื่องของเอกสารประกอบ');

        $unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $this->send(['kind' => 'material', 'title' => 'ใบความรู้ที่ 1', 'unit_id' => $unit->id])
            ->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'เลือกหน่วยการเรียนรู้ของรายวิชานี้');
        $this->send(['kind' => 'material', 'title' => ' ใบความรู้  ที่ 1 ', 'body' => null, 'course_id' => $this->course->id, 'unit_id' => $unit->id, 'topic' => 'ไม่ใช้'])
            ->assertCreated()
            ->assertJsonPath('data.0.title', 'ใบความรู้ ที่ 1')
            ->assertJsonPath('data.0.unit.id', $unit->id)
            ->assertJsonPath('data.0.topic', 'เศษส่วน')
            ->assertJsonPath('data.0.course.id', $this->course->id);
        $this->send(['kind' => 'material', 'title' => 'กติกาห้อง', 'topic' => ' ประกาศทั่วไป '])
            ->assertCreated()->assertJsonPath('data.0.unit', null)->assertJsonPath('data.0.topic', 'ประกาศทั่วไป');
    }

    public function test_an_announcement_needs_text_or_an_attachment(): void
    {
        $this->send(['body' => '   '])->assertStatus(422)->assertJsonPath('errors.body.0', 'พิมพ์ข้อความหรือแนบไฟล์อย่างน้อยหนึ่งอย่าง');
        $this->send(['body' => null, 'attachments' => [['url' => 'https://example.com/a', 'title' => 'อ่านเพิ่ม']]])
            ->assertCreated()->assertJsonPath('data.0.attachments.0.kind', 'link')->assertJsonPath('data.0.attachments.0.title', 'อ่านเพิ่ม');
    }

    public function test_files_and_links_are_attached_in_order_and_only_readers_of_the_post_get_the_bytes(): void
    {
        $fileId = $this->upload();
        $this->assertSame(1, ClassPostFile::query()->count());
        $path = ClassPostFile::query()->findOrFail($fileId)->path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("class-posts/{$this->teacher->id}/", $path);

        $id = $this->send(['audience' => 'students', 'student_ids' => [$this->students[0]->id], 'attachments' => [
            ['url' => 'https://www.youtube.com/watch?v=abc'],
            ['file_id' => $fileId],
        ]])->assertCreated()
            ->assertJsonPath('data.0.attachments.0.kind', 'link')
            ->assertJsonPath('data.0.attachments.0.title', 'https://www.youtube.com/watch?v=abc')
            ->assertJsonPath('data.0.attachments.1.kind', 'file')
            ->assertJsonPath('data.0.attachments.1.title', 'ใบความรู้.pdf')
            ->assertJsonPath('data.0.attachments.1.file.mime_type', 'application/pdf')
            ->assertJsonPath('data.0.attachments.1.file.download_url', "/api/v1/class-post-files/{$fileId}/file")
            ->assertJsonPath('data.0.student_ids', [$this->students[0]->id])
            ->json('data.0.id');

        $url = "/api/v1/class-post-files/{$fileId}/file";
        $this->asUser($this->teacher)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->students[0])->get($url)->assertOk();
        // Not a recipient, another school's teacher, a student of another classroom: 404.
        $this->asUser($this->students[1])->getJson($url)->assertNotFound();
        $this->asUser($this->students[1])->getJson("/api/v1/student/class-posts/{$id}")->assertNotFound();
        $this->asUser($this->students[1])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->makeTeacher($this->makeSchool()))->getJson($url)->assertNotFound();

        // In use: cannot be deleted on its own; goes with the last post that attaches it.
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-post-files/{$fileId}")->assertStatus(409)->assertJsonPath('code', 'file_in_use');
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$id}")->assertNoContent();
        $this->assertSame(0, ClassPostFile::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_uploads_check_the_type_the_size_and_the_teachers_quota(): void
    {
        $post = fn (UploadedFile $file) => $this->asUser($this->teacher)->post('/api/v1/class-post-files', ['file' => $file], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->createWithContent('run.pdf', "#!/bin/sh\necho hi\n"))
            ->assertStatus(422)->assertJsonPath('code', 'file_type_unsupported');
        config(['eduvision.class_posts.max_file_mb' => 1]);
        $post(UploadedFile::fake()->createWithContent('big.pdf', '%PDF-1.4'.str_repeat('a', 1024 * 1024 + 10)))
            ->assertStatus(422)->assertJsonPath('code', 'file_too_large');
        $this->asUser($this->teacher)->postJson('/api/v1/class-post-files', [])->assertStatus(422)->assertJsonPath('errors.file.0', 'เลือกไฟล์ที่จะแนบ');

        $this->upload();
        $used = $this->asUser($this->teacher)->getJson('/api/v1/class-post-files/usage')->assertOk()->json('data.used_bytes');
        $this->assertGreaterThan(0, $used);
        ClassPostFile::query()->update(['size_bytes' => 1024 * 1024 * 1024]);
        config(['eduvision.class_posts.quota_mb' => 1024]);
        $post(UploadedFile::fake()->createWithContent('more.pdf', "%PDF-1.4\n%%EOF\n"))
            ->assertStatus(422)->assertJsonPath('code', 'class_post_quota_exceeded');
    }

    public function test_an_unattached_file_can_be_deleted_and_old_ones_are_purged(): void
    {
        $kept = $this->upload('ก.pdf');
        $gone = $this->upload('ข.pdf');
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-post-files/{$gone}")->assertNoContent();

        $old = $this->upload('ค.pdf');
        $attached = $this->upload('ง.pdf');
        $this->send(['attachments' => [['file_id' => $attached]]])->assertCreated();
        ClassPostFile::query()->whereIn('id', [$old, $attached])->update(['created_at' => now()->subHours(25)]);

        $this->assertSame(1, ClassPosts::purgeOrphans());
        $this->assertEqualsCanonicalizing([$kept, $attached], ClassPostFile::query()->pluck('id')->all());
    }

    public function test_one_post_goes_to_several_classrooms_as_separate_posts_sharing_the_file(): void
    {
        $second = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $fileId = $this->upload();

        $response = $this->send(['classroom_ids' => [$this->classroom->id, $second->id], 'attachments' => [['file_id' => $fileId]]])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.batch_id'));
        $this->assertSame($response->json('data.0.batch_id'), $response->json('data.1.batch_id'));
        $this->assertSame([$this->classroom->id, $second->id], [$response->json('data.0.classroom.id'), $response->json('data.1.classroom.id')]);

        // Deleting one keeps the file for the other.
        $this->asUser($this->teacher)->deleteJson('/api/v1/class-posts/'.$response->json('data.0.id'))->assertNoContent();
        $this->assertSame(1, ClassPostFile::query()->count());
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$second->id}/posts")->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/posts")->assertOk()->assertJsonCount(0, 'data');

        $this->send(['classroom_ids' => [$this->classroom->id, $second->id], 'audience' => 'students', 'student_ids' => [$this->students[0]->id]])
            ->assertStatus(422)->assertJsonPath('errors.student_ids.0', 'เลือกนักเรียนรายคนได้เมื่อโพสต์ห้องเดียว');
    }

    public function test_single_students_must_be_on_the_roster(): void
    {
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher, ['name' => 'ป.6/1']), 1, 'คนนอก')['student'];
        $this->send(['audience' => 'students', 'student_ids' => []])->assertStatus(422)->assertJsonPath('errors.student_ids.0', 'เลือกนักเรียนอย่างน้อยหนึ่งคน');
        $this->send(['audience' => 'students', 'student_ids' => [$outsider->id]])->assertStatus(422)->assertJsonPath('errors.student_ids.0', 'เลือกได้เฉพาะนักเรียนของห้องนี้');
    }

    public function test_a_draft_is_private_until_published_and_a_published_post_stays_published(): void
    {
        Event::fake([ClassPostPublished::class]);
        $id = $this->send(['state' => 'draft'])->assertCreated()->assertJsonPath('data.0.state', 'draft')->assertJsonPath('data.0.published_at', null)->json('data.0.id');
        Event::assertNotDispatched(ClassPostPublished::class);
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->students[0])->getJson("/api/v1/student/class-posts/{$id}")->assertNotFound();

        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['body' => 'แก้แล้ว', 'state' => 'published'])
            ->assertOk()->assertJsonPath('data.state', 'published')->assertJsonPath('data.body', 'แก้แล้ว');
        Event::assertDispatchedTimes(ClassPostPublished::class, 1);
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['state' => 'draft'])->assertStatus(409)->assertJsonPath('code', 'post_already_published');
        // Editing a published post does not announce it again.
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['body' => 'แก้อีกครั้ง'])->assertOk()->assertJsonPath('data.state', 'published');
        Event::assertDispatchedTimes(ClassPostPublished::class, 1);
    }

    public function test_a_scheduled_post_is_published_by_the_cron_step_when_due(): void
    {
        $this->send(['state' => 'scheduled'])->assertStatus(422)->assertJsonPath('errors.scheduled_at.0', 'ตั้งเวลาเผยแพร่เป็นเวลาในอนาคต');
        $this->send(['state' => 'scheduled', 'scheduled_at' => now()->subMinute()->toIso8601String()])->assertStatus(422);
        $id = $this->send(['state' => 'scheduled', 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.0.state', 'scheduled')->json('data.0.id');
        $later = $this->send(['state' => 'scheduled', 'scheduled_at' => now()->addDays(2)->toIso8601String()])->assertCreated()->json('data.0.id');

        Event::fake([ClassPostPublished::class]);
        $this->assertSame(0, ClassPosts::publishDue());
        $this->travel(61)->minutes();
        $this->assertSame(1, ClassPosts::publishDue());
        $this->assertSame(0, ClassPosts::publishDue());
        Event::assertDispatchedTimes(ClassPostPublished::class, 1);

        $post = ClassPost::query()->findOrFail($id);
        $this->assertSame('published', $post->state);
        $this->assertNull($post->scheduled_at);
        $this->assertSame('scheduled', ClassPost::query()->findOrFail($later)->state);
        // A scheduled post can be moved or turned back into a draft.
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$later}", ['state' => 'draft'])->assertOk()->assertJsonPath('data.state', 'draft')->assertJsonPath('data.scheduled_at', null);
    }

    public function test_editing_replaces_attachments_and_recipients_and_drops_unused_files(): void
    {
        $first = $this->upload('ก.pdf');
        $second = $this->upload('ข.pdf');
        $id = $this->send(['attachments' => [['file_id' => $first]]])->assertCreated()->json('data.0.id');

        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", [
            'attachments' => [['file_id' => $second], ['url' => 'https://example.com']],
            'audience' => 'students', 'student_ids' => [$this->students[1]->id, $this->students[2]->id],
        ])->assertOk()
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonPath('data.attachments.0.file.id', $second)
            ->assertJsonPath('data.student_ids', [$this->students[1]->id, $this->students[2]->id]);
        $this->assertNull(ClassPostFile::query()->find($first));
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->students[1])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['audience' => 'all'])->assertOk()->assertJsonPath('data.student_ids', []);
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(1, 'data');
        // Emptying an announcement is refused.
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['body' => '', 'attachments' => []])->assertStatus(422)->assertJsonPath('errors.body.0', 'พิมพ์ข้อความหรือแนบไฟล์อย่างน้อยหนึ่งอย่าง');
    }

    public function test_every_teacher_of_the_classroom_reads_published_posts_but_only_the_author_changes_them(): void
    {
        $subject = $this->makeTeacher($this->teacher->school);
        $subjectCourse = $this->makeCourse($subject, [$this->classroom], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์']);
        $stranger = $this->makeTeacher($this->teacher->school);

        $mine = $this->send()->assertCreated()->json('data.0.id');
        $draft = $this->send(['state' => 'draft'])->assertCreated()->json('data.0.id');
        // A subject teacher is in the classroom through a course, so names it.
        $this->send([], $subject)->assertStatus(422)->assertJsonPath('errors.course_id.0', 'เลือกรายวิชาของคุณที่สอนในห้องนี้');
        $this->send(['course_id' => $this->course->id], $subject)->assertStatus(422);
        $theirs = $this->send(['course_id' => $subjectCourse->id, 'body' => 'การบ้านวิทย์'], $subject)->assertCreated()->assertJsonPath('data.0.course.code', 'ว15101')->json('data.0.id');

        $this->asUser($subject)->getJson("/api/v1/classrooms/{$this->classroom->id}/posts")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $theirs)->assertJsonPath('data.0.is_mine', true)->assertJsonPath('data.1.id', $mine)->assertJsonPath('data.1.is_mine', false);
        $this->asUser($subject)->getJson("/api/v1/class-posts/{$draft}")->assertNotFound();
        $this->asUser($subject)->putJson("/api/v1/class-posts/{$mine}", ['body' => 'x'])->assertStatus(403)->assertJsonPath('code', 'not_post_author');
        $this->asUser($subject)->deleteJson("/api/v1/class-posts/{$mine}")->assertStatus(403);
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/posts")->assertOk()->assertJsonCount(3, 'data');

        // A teacher with no part in the classroom sees nothing and cannot post.
        $this->asUser($stranger)->getJson("/api/v1/classrooms/{$this->classroom->id}/posts")->assertNotFound();
        $this->asUser($stranger)->getJson("/api/v1/class-posts/{$mine}")->assertNotFound();
        $this->send([], $stranger)->assertStatus(422)->assertJsonPath('errors.classroom_ids.0', 'เลือกห้องเรียนของคุณ');
        // Another teacher's file cannot be attached.
        $this->send(['attachments' => [['file_id' => $this->upload('ของครูวิทย์.pdf', $subject)]]])->assertStatus(422);
    }

    public function test_a_closed_classroom_takes_no_new_posts(): void
    {
        $this->classroom->forceFill(['closed_at' => now(), 'closed_by' => $this->teacher->id])->save();
        $this->send()->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
    }

    public function test_the_feed_mixes_posts_with_homework_and_results_and_filters_by_course(): void
    {
        $other = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ท15101', 'name' => 'ภาษาไทย']);
        $this->send(['body' => 'ของห้อง'])->assertCreated();
        $this->send(['body' => 'ของคณิต', 'course_id' => $this->course->id])->assertCreated();
        $this->send(['body' => 'ของไทย', 'course_id' => $other->id])->assertCreated();

        $student = $this->students[0];
        $this->asUser($student)->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(3, 'data');
        $bodies = $this->asUser($student)->getJson("/api/v1/student/class-feed?course_id={$this->course->id}&classroom_id={$this->classroom->id}")
            ->assertOk()->assertJsonCount(2, 'data')->json('data.*.post.body');
        $this->assertEqualsCanonicalizing(['ของห้อง', 'ของคณิต'], $bodies);
        $this->asUser($student)->getJson('/api/v1/student/class-feed?classroom_id=abc')->assertStatus(422);
    }

    public function test_only_announcements_are_pushed_and_only_to_the_students_of_the_post(): void
    {
        $announcement = ClassPost::query()->findOrFail($this->send(['audience' => 'students', 'student_ids' => [$this->students[2]->id]])->json('data.0.id'));
        $material = ClassPost::query()->findOrFail($this->send(['kind' => 'material', 'title' => 'ใบความรู้'])->json('data.0.id'));
        $this->assertSame([$this->students[2]->id], ClassPosts::recipientIds($announcement));
        $this->assertCount(3, ClassPosts::recipientIds($material));

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('classPostPublished')->once()->with(Mockery::on(fn (ClassPost $p) => $p->id === $announcement->id));
        $listener = new NotifyStudentsOfClassPost($notifier);
        $listener->handle(new ClassPostPublished($announcement->id));
        $listener->handle(new ClassPostPublished($material->id));
        $listener->handle(new ClassPostPublished(999999));
    }
}
