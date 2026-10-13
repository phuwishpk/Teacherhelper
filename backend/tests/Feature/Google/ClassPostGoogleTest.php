<?php

namespace Tests\Feature\Google;

use App\Domain\ClassPosts\ClassPostGoogle;
use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleScopes;
use App\Jobs\PushClassPostGoogleJob;
use App\Jobs\RenameGoogleTopicJob;
use App\Models\ClassPost;
use App\Models\ClassPostFile;
use App\Models\ClassPostGooglePost;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomGoogleTopic;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\GoogleAccount;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §30.4 (step 2): the Google Classroom copy of a class post, made
 * through the author's own link: announcements, materials under the topic
 * of their unit, files copied to Drive, chosen students, edits, deletion,
 * failures and the new scopes.
 */
class ClassPostGoogleTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const MATERIALS_URL = 'classroom.googleapis.com/v1/courses/*/courseWorkMaterials*';

    private const TOPICS_URL = 'classroom.googleapis.com/v1/courses/*/topics*';

    private const UPLOAD_URL = 'www.googleapis.com/upload/drive/v3/files*';

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    /** @var list<User> */
    private array $students;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->configureGoogle();
        $this->captureLogs();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->students = [];
        foreach ([1, 2, 3] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
        $this->account = $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิต', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => 'g-1']);
    }

    /**
     * Google as the tests need it. Http::fake keeps the first stub that
     * matches, so a test calls this once, with its overrides.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fake(array $routes = []): void
    {
        $this->fakeGoogle($routes + [
            self::TOPICS_URL => Http::sequence()
                ->push(['topic' => []])
                ->push(['topicId' => 'topic-1', 'name' => 'เศษส่วน'])
                ->whenEmpty(Http::response(['topic' => [['topicId' => 'topic-1', 'name' => 'เศษส่วน']]])),
            // The fixture's announcements stub covers the create only; patch and delete name the id.
            'classroom.googleapis.com/v1/courses/*/announcements/*' => Http::response([]),
            self::MATERIALS_URL => Http::response(['id' => 'material-1', 'alternateLink' => 'https://classroom.google.com/m/1']),
            self::UPLOAD_URL => Http::response(['id' => 'drive-file-1']),
            'www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'drive-file-1', 'trashed' => true]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(array $body = []): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/class-posts', $body + [
            'classroom_ids' => [$this->classroom->id], 'kind' => 'announcement', 'state' => 'published', 'body' => 'พรุ่งนี้สอบย่อย',
        ]);
    }

    private function upload(string $name = 'ใบความรู้.pdf'): int
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n");

        return $this->asUser($this->teacher)->post('/api/v1/class-post-files', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_a_published_announcement_becomes_a_classroom_announcement_through_the_authors_link(): void
    {
        $this->fake();
        $response = $this->send(['attachments' => [['url' => 'https://example.com/read', 'title' => 'อ่านเพิ่ม']]])->assertCreated();
        $response->assertJsonPath('data.0.google.linked', true)
            ->assertJsonPath('data.0.google.state', 'sent')
            ->assertJsonPath('data.0.google.link', 'https://classroom.google.com/a/1')
            ->assertJsonPath('data.0.google.in_classroom', true);

        $sent = $this->sentTo('/announcements', 'POST');
        $this->assertCount(1, $sent);
        $this->assertSame([
            'text' => 'พรุ่งนี้สอบย่อย',
            'materials' => [['link' => ['url' => 'https://example.com/read']]],
            'state' => 'PUBLISHED',
        ], $sent[0]->data());
        $this->assertTrue($sent[0]->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));

        $row = ClassPostGooglePost::query()->sole();
        $this->assertSame('announcement-1', $row->google_id);
        $this->assertSame(self::COURSE_ID, $row->course_id);
        $this->assertNotNull($row->sent_at);
        // Nothing else of Classroom was touched: no topic, no material, no Drive upload.
        $this->assertCount(0, $this->sentTo('/topics'));
        $this->assertCount(0, $this->sentTo('/upload/drive'));
    }

    public function test_a_material_goes_under_the_topic_of_its_unit_with_its_file_copied_to_drive(): void
    {
        $this->fake();
        $unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $fileId = $this->upload();

        $this->send(['kind' => 'material', 'title' => 'ใบความรู้ที่ 1', 'body' => 'อ่านก่อนเรียน', 'course_id' => $this->course->id, 'unit_id' => $unit->id,
            'attachments' => [['file_id' => $fileId], ['url' => 'https://youtu.be/abc', 'title' => 'วิดีโอ']]])
            ->assertCreated()->assertJsonPath('data.0.google.state', 'sent')->assertJsonPath('data.0.google.link', 'https://classroom.google.com/m/1');

        // The topic: listed (none), then made with the unit's title, then remembered.
        $this->assertCount(1, $this->sentTo('/topics', 'GET'));
        $created = $this->sentTo('/topics', 'POST');
        $this->assertCount(1, $created);
        $this->assertSame(['name' => 'เศษส่วน'], $created[0]->data());
        $topic = ClassroomGoogleTopic::query()->sole();
        $this->assertSame(['topic-1', 'เศษส่วน', $unit->id], [$topic->topic_id, $topic->name, $topic->unit_id]);

        // The file went up to Drive once, as the teacher named it, with its real type.
        $uploads = $this->sentTo('/upload/drive/v3/files', 'POST');
        $this->assertCount(1, $uploads);
        $this->assertStringContainsString('"name":"ใบความรู้.pdf"', $uploads[0]->body());
        $this->assertStringContainsString('"mimeType":"application\/pdf"', $uploads[0]->body());
        $this->assertStringContainsString('%PDF-1.4', $uploads[0]->body());
        $this->assertSame('drive-file-1', ClassPostFile::query()->findOrFail($fileId)->drive_file_id);

        $material = $this->sentTo('/courseWorkMaterials', 'POST');
        $this->assertCount(1, $material);
        $this->assertSame([
            'title' => 'ใบความรู้ที่ 1',
            'description' => 'อ่านก่อนเรียน',
            'materials' => [
                ['driveFile' => ['driveFile' => ['id' => 'drive-file-1'], 'shareMode' => 'VIEW']],
                ['link' => ['url' => 'https://youtu.be/abc']],
            ],
            'state' => 'PUBLISHED',
            'topicId' => 'topic-1',
        ], $material[0]->data());

        // A second material of the same unit with the same file: no new topic, no new upload.
        $this->send(['kind' => 'material', 'title' => 'ใบความรู้ที่ 2', 'course_id' => $this->course->id, 'unit_id' => $unit->id, 'attachments' => [['file_id' => $fileId]]])->assertCreated();
        $this->assertCount(1, $this->sentTo('/topics', 'POST'));
        $this->assertCount(1, $this->sentTo('/upload/drive/v3/files', 'POST'));
        $this->assertSame('topic-1', $this->sentTo('/courseWorkMaterials', 'POST')[1]->data()['topicId']);
    }

    public function test_a_free_topic_reuses_the_courses_topic_of_that_name(): void
    {
        $this->fake([self::TOPICS_URL => Http::response(['topic' => [['topicId' => 'topic-9', 'name' => 'ประกาศทั่วไป']]])]);
        $this->send(['kind' => 'material', 'title' => 'กติกาห้อง', 'topic' => 'ประกาศทั่วไป'])->assertCreated()->assertJsonPath('data.0.google.state', 'sent');
        $this->assertCount(0, $this->sentTo('/topics', 'POST'));
        $this->assertSame('topic-9', $this->sentTo('/courseWorkMaterials', 'POST')[0]->data()['topicId']);
        $this->assertSame('ประกาศทั่วไป', ClassroomGoogleTopic::query()->sole()->name);
    }

    public function test_a_post_for_chosen_students_reaches_only_those_paired_with_google(): void
    {
        $this->fake();
        $this->send(['audience' => 'students', 'student_ids' => [$this->students[0]->id, $this->students[1]->id]])->assertCreated()->assertJsonPath('data.0.google.state', 'sent');
        $body = $this->sentTo('/announcements', 'POST')[0]->data();
        $this->assertSame('INDIVIDUAL_STUDENTS', $body['assigneeMode']);
        $this->assertSame(['studentIds' => ['g-1']], $body['individualStudentsOptions']);

        // Nobody paired: nothing is sent to the whole class by mistake.
        $this->send(['audience' => 'students', 'student_ids' => [$this->students[1]->id]])->assertCreated()
            ->assertJsonPath('data.0.google.state', 'failed')
            ->assertJsonPath('data.0.google.last_error', 'นักเรียนที่เลือกยังไม่มีใครจับคู่กับบัญชี Google จับคู่ในหน้ารายชื่อ Google ของห้องแล้วกดส่งอีกครั้ง');
        $this->assertCount(1, $this->sentTo('/announcements', 'POST'));
    }

    public function test_nothing_is_sent_without_a_link_a_draft_or_the_switch_off(): void
    {
        $this->fake();
        $other = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->send(['classroom_ids' => [$other->id]])->assertCreated()
            ->assertJsonPath('data.0.google.linked', false)->assertJsonPath('data.0.google.state', 'not_sent');
        $this->send(['state' => 'draft'])->assertCreated()->assertJsonPath('data.0.google.state', 'not_sent');
        $id = $this->send(['send_to_google' => false])->assertCreated()
            ->assertJsonPath('data.0.google.linked', true)->assertJsonPath('data.0.google.state', 'not_sent')->assertJsonPath('data.0.send_to_google', false)->json('data.0.id');
        $this->assertCount(0, $this->sentTo('/announcements', 'POST'));

        // "ส่งอีกครั้ง" on a post kept off switches it on and sends it.
        $this->asUser($this->teacher)->postJson("/api/v1/class-posts/{$id}/google/retry")->assertStatus(202)->assertJsonPath('data.state', 'sent');
        $this->assertCount(1, $this->sentTo('/announcements', 'POST'));
        $this->assertTrue(ClassPost::query()->findOrFail($id)->send_to_google);
        // Without a link the retry says so.
        $otherPost = $this->send(['classroom_ids' => [$other->id]])->json('data.0.id');
        $this->asUser($this->teacher)->postJson("/api/v1/class-posts/{$otherPost}/google/retry")->assertStatus(422)->assertJsonPath('code', 'classroom_not_linked');
    }

    public function test_the_scheduled_post_is_sent_when_the_cron_publishes_it(): void
    {
        $this->fake();
        $this->send(['state' => 'scheduled', 'scheduled_at' => now()->addHour()->toIso8601String()])->assertCreated()->assertJsonPath('data.0.google.state', 'not_sent');
        $this->travel(61)->minutes();
        $this->assertSame(1, ClassPosts::publishDue());
        $this->assertSame('sent', ClassPostGooglePost::query()->sole()->state);
        $this->assertCount(1, $this->sentTo('/announcements', 'POST'));
    }

    public function test_edits_patch_the_copy_and_the_files_of_a_sent_copy_are_locked(): void
    {
        $this->fake();
        $fileId = $this->upload();
        $id = $this->send(['attachments' => [['file_id' => $fileId]]])->assertCreated()->json('data.0.id');
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['body' => 'เลื่อนสอบเป็นวันศุกร์'])->assertOk()->assertJsonPath('data.google.state', 'sent');
        $patched = $this->sentTo('/announcements/announcement-1', 'PATCH');
        $this->assertCount(1, $patched);
        $this->assertStringContainsString('updateMask=text', $patched[0]->url());
        $this->assertSame(['text' => 'เลื่อนสอบเป็นวันศุกร์'], $patched[0]->data());

        // The same file list passes; a different one is refused.
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['attachments' => [['file_id' => $fileId, 'title' => 'ชื่อใหม่']]])->assertOk();
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['attachments' => []])->assertStatus(409)->assertJsonPath('code', 'attachments_locked');
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['attachments' => [['file_id' => $fileId], ['url' => 'https://example.com']]])->assertStatus(409);

        $unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $material = $this->send(['kind' => 'material', 'title' => 'ใบความรู้', 'course_id' => $this->course->id, 'unit_id' => $unit->id])->json('data.0.id');
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$material}", ['title' => 'ใบความรู้ (แก้)', 'body' => 'อ่านหน้า 3'])->assertOk();
        $patched = $this->sentTo('/courseWorkMaterials/material-1', 'PATCH');
        $this->assertCount(1, $patched);
        $this->assertStringContainsString('updateMask=title%2Cdescription%2CtopicId', $patched[0]->url());
        $this->assertSame(['title' => 'ใบความรู้ (แก้)', 'description' => 'อ่านหน้า 3', 'topicId' => 'topic-1'], $patched[0]->data());
    }

    public function test_switching_the_copy_off_or_deleting_the_post_removes_it_from_classroom_and_drive(): void
    {
        $this->fake();
        $fileId = $this->upload();
        $id = $this->send(['attachments' => [['file_id' => $fileId]]])->assertCreated()->json('data.0.id');
        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['send_to_google' => false])->assertOk()->assertJsonPath('data.google.state', 'not_sent');
        $this->assertCount(1, $this->sentTo('/announcements/announcement-1', 'DELETE'));
        $this->assertSame(0, ClassPostGooglePost::query()->count());

        $second = $this->send(['attachments' => [['file_id' => $fileId]]])->assertCreated()->json('data.0.id');
        $this->assertCount(1, $this->sentTo('/upload/drive/v3/files', 'POST'), 'the Drive copy is reused');
        // The first post (switched off, nothing in Classroom) still holds the file: no bin yet.
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$id}")->assertNoContent();
        $this->assertCount(1, $this->sentTo('/announcements/announcement-1', 'DELETE'));
        $this->assertCount(0, $this->sentTo('/drive/v3/files/drive-file-1', 'PATCH'));
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$second}")->assertNoContent();
        $this->assertCount(2, $this->sentTo('/announcements/announcement-1', 'DELETE'));
        $trashed = $this->sentTo('/drive/v3/files/drive-file-1', 'PATCH');
        $this->assertCount(1, $trashed);
        $this->assertSame(['trashed' => true], $trashed[0]->data());
        $this->assertSame(0, ClassPostFile::query()->count());
    }

    public function test_a_renamed_unit_renames_its_classroom_topic(): void
    {
        $this->fake();
        $unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $this->send(['kind' => 'material', 'title' => 'ใบความรู้', 'course_id' => $this->course->id, 'unit_id' => $unit->id])->assertCreated();
        Queue::fake();
        $this->asUser($this->teacher)->patchJson("/api/v1/units/{$unit->id}", ['title' => 'เศษส่วนและทศนิยม'])->assertOk();
        Queue::assertPushed(RenameGoogleTopicJob::class, fn (RenameGoogleTopicJob $job) => $job->unitId === $unit->id);
        (new RenameGoogleTopicJob($unit->id))->handle(app(GoogleAccessTokens::class));
        $renamed = $this->sentTo('/topics/topic-1', 'PATCH');
        $this->assertCount(1, $renamed);
        $this->assertSame(['name' => 'เศษส่วนและทศนิยม'], $renamed[0]->data());
        $this->assertSame('เศษส่วนและทศนิยม', ClassroomGoogleTopic::query()->sole()->name);
    }

    public function test_google_refusals_are_recorded_and_can_be_retried(): void
    {
        $this->fake(['classroom.googleapis.com/v1/courses/*/announcements' => Http::sequence()
            ->push(self::googleError(403, 'PERMISSION_DENIED', 'Request had insufficient authentication scopes.', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'), 403)
            ->push(['id' => 'announcement-1', 'alternateLink' => 'https://classroom.google.com/a/1'])]);
        $id = $this->send()->assertCreated()
            ->assertJsonPath('data.0.google.state', 'failed')
            ->assertJsonPath('data.0.google.last_error', GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE)
            ->json('data.0.id');
        $this->assertSame(GoogleAccount::ERROR_SCOPE_MISSING, $this->account->refresh()->last_error);

        // Reconnected: the retry sends it.
        $this->account->forceFill(['last_error' => null, 'reconnect_notified_at' => null])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/class-posts/{$id}/google/retry")->assertStatus(202)->assertJsonPath('data.state', 'sent');
        $this->assertCount(2, $this->sentTo('/announcements', 'POST'));
    }

    public function test_an_unreachable_google_is_retried_by_the_queue_then_fails(): void
    {
        Queue::fake();
        $this->fake(['classroom.googleapis.com/v1/courses/*/announcements' => Http::response(self::googleError(503, 'UNAVAILABLE', 'Try again'), 503)]);
        $this->send()->assertCreated()->assertJsonPath('data.0.google.state', 'queued');
        $row = ClassPostGooglePost::query()->sole();
        Queue::assertPushed(PushClassPostGoogleJob::class, fn (PushClassPostGoogleJob $job) => $job->rowId === $row->id);

        $first = (new PushClassPostGoogleJob($row->id))->withFakeQueueInteractions();
        $first->handle(app(ClassPostGoogle::class));
        $first->assertReleased(60);
        $this->assertSame(ClassPostGooglePost::STATE_QUEUED, $row->refresh()->state);

        $last = (new PushClassPostGoogleJob($row->id))->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle(app(ClassPostGoogle::class));
        $last->assertNotReleased();
        $this->assertSame(ClassPostGooglePost::STATE_FAILED, $row->refresh()->state);
        $this->assertStringContainsString('ติดต่อ Google ไม่ได้', (string) $row->last_error);
    }

    public function test_an_account_without_the_new_scopes_must_connect_again(): void
    {
        $this->fake();
        $old = implode(' ', array_diff(GoogleScopes::REQUIRED, GoogleScopes::CLASS_POSTS)).' '.GoogleScopes::ANNOUNCEMENTS;
        $this->account->forceFill(['scopes' => $old])->save();
        $this->assertTrue($this->account->needsReconnect());
        $this->assertSame(GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE, $this->account->reconnectMessage());
        $this->asUser($this->teacher)->getJson('/api/v1/google/status')->assertOk()
            ->assertJsonPath('data.needs_reconnect', true)->assertJsonPath('data.reconnect_message', GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE);

        $this->send()->assertCreated()->assertJsonPath('data.0.google.state', 'failed')->assertJsonPath('data.0.google.last_error', GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE);
        $this->assertCount(0, $this->sentTo('/announcements', 'POST'));

        // The migration marks such accounts so the reconnect banner shows before the first send.
        $this->account->forceFill(['last_error' => null])->save();
        $migration = require database_path('migrations/2026_10_13_000002_add_class_post_google.php');
        $this->assertSame(1, $migration->markAccountsWithoutClassPostScopes());
        $this->assertSame(GoogleAccount::ERROR_SCOPE_MISSING, $this->account->refresh()->last_error);
        $this->assertSame(0, $migration->markAccountsWithoutClassPostScopes());
    }

    public function test_only_the_author_retries_and_a_closed_classroom_refuses(): void
    {
        $this->fake();
        $id = $this->send()->json('data.0.id');
        $subject = $this->makeTeacher($this->teacher->school);
        $this->makeCourse($subject, [$this->classroom], ['code' => 'ว15101', 'name' => 'วิทย์']);
        $this->asUser($subject)->postJson("/api/v1/class-posts/{$id}/google/retry")->assertStatus(403)->assertJsonPath('code', 'not_post_author');
        $this->classroom->forceFill(['closed_at' => now(), 'closed_by' => $this->teacher->id])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/class-posts/{$id}/google/retry")->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
    }
}
