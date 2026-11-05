<?php

namespace Tests\Feature\Google;

use App\Domain\Google\ClassroomSync;
use App\Domain\Notifications\Notifier;
use App\Models\ClassPost;
use App\Models\ClassPostFile;
use App\Models\ClassPostGooglePost;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomGoogleTopic;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * DESIGN §45: announcements and materials the teacher writes on the
 * Classroom website come into Krucheck as class posts of the linked
 * classroom and subject, with their PDFs copied and their links kept;
 * edits and deletions follow; the app's own copies are never pulled back;
 * old posts come in without notifying; pulled posts are read-only.
 */
class ClassroomPostImportTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private ClassroomGoogleLink $link;

    /** @var list<User> */
    private array $students;

    /** @var list<array<string, mixed>> what announcements.list answers */
    private array $announcements = [];

    /** @var list<array<string, mixed>> what courseWorkMaterials.list answers */
    private array $materials = [];

    /** @var array<string, array{name: string, mime: string, bytes: string, size?: int}> Drive files by id */
    private array $drive = [];

    /** announcements.list answers 403 */
    private bool $refused = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->configureGoogle();
        $this->captureLogs();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ม.4/9']);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->students = [];
        foreach ([1, 2, 3] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => 'g-1']);
        $this->connectGoogle($this->teacher);
        $this->link = ClassroomGoogleLink::create([
            'classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'Math1 m4/9',
            'owner_user_id' => $this->teacher->id, 'app_course_id' => $this->course->id, 'linked_at' => now()->subDay(),
        ]);

        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/*/announcements?*' => fn () => $this->refused
                ? Http::response(self::googleError(403, 'PERMISSION_DENIED', 'The caller does not have permission'), 403)
                : Http::response(['announcements' => $this->announcements]),
            'classroom.googleapis.com/v1/courses/*/courseWorkMaterials?*' => fn () => Http::response(['courseWorkMaterial' => $this->materials]),
            'classroom.googleapis.com/v1/courses/*/courseWork*' => Http::response(['courseWork' => []]),
            'classroom.googleapis.com/v1/courses/*/topics*' => Http::response(['topic' => [['topicId' => 't-set', 'name' => 'เซต']]]),
            'www.googleapis.com/drive/v3/files/*' => function (Request $request) {
                $id = rawurldecode((string) preg_replace('#^.*/files/([^?]+).*$#', '$1', $request->url()));
                $file = $this->drive[$id] ?? null;
                if ($file === null) {
                    return Http::response(self::googleError(404, 'NOT_FOUND', 'File not found'), 404);
                }
                if (str_contains($request->url(), 'alt=media')) {
                    return Http::response($file['bytes']);
                }
                $meta = ['mimeType' => $file['mime'], 'name' => $file['name']];
                if (! str_starts_with($file['mime'], 'application/vnd.google-apps.')) {
                    $meta['size'] = (string) ($file['size'] ?? strlen($file['bytes']));
                }

                return Http::response($meta);
            },
        ]);
    }

    /**
     * An Announcement resource as announcements.list gives it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function announcement(string $id, string $text, array $extra = []): array
    {
        return $extra + [
            'courseId' => self::COURSE_ID,
            'id' => $id,
            'text' => $text,
            'state' => 'PUBLISHED',
            'alternateLink' => "https://classroom.google.com/c/MTIz/p/{$id}",
            'creationTime' => '2026-11-05T15:34:00.000Z',
            'updateTime' => '2026-11-05T15:34:00.000Z',
            'assigneeMode' => 'ALL_STUDENTS',
            'creatorUserId' => 'teacher-google-id',
        ];
    }

    /**
     * A CourseWorkMaterial resource.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function material(string $id, string $title, array $extra = []): array
    {
        return $extra + [
            'courseId' => self::COURSE_ID,
            'id' => $id,
            'title' => $title,
            'state' => 'PUBLISHED',
            'alternateLink' => "https://classroom.google.com/c/MTIz/m/{$id}/details",
            'creationTime' => '2026-11-05T15:40:00.000Z',
            'updateTime' => '2026-11-05T15:40:00.000Z',
            'assigneeMode' => 'ALL_STUDENTS',
        ];
    }

    /** @return array<string, mixed> a Drive file material */
    private static function driveFile(string $id, string $title): array
    {
        return ['driveFile' => ['driveFile' => ['id' => $id, 'title' => $title, 'alternateLink' => "https://drive.google.com/file/d/{$id}/view"], 'shareMode' => 'VIEW']];
    }

    private function round(): void
    {
        app(ClassroomSync::class)->run($this->classroom->id);
    }

    private function notifier(): Notifier&MockInterface
    {
        $notifier = Mockery::spy(Notifier::class);
        $this->app->instance(Notifier::class, $notifier);

        return $notifier;
    }

    public function test_a_web_announcement_comes_in_under_the_linked_subject_with_its_pdf_copied_and_its_links_kept(): void
    {
        $this->drive['f-pdf'] = ['name' => 'สรุปเรื่องเซต ม.4.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF];
        $this->drive['f-doc'] = ['name' => 'แบบฝึกหัดเซต', 'mime' => 'application/vnd.google-apps.document', 'bytes' => ''];
        $this->announcements = [self::announcement('ann-web', 'เนื้อหา เซต', ['materials' => [
            self::driveFile('f-pdf', 'สรุปเรื่องเซต ม.4.pdf'),
            self::driveFile('f-doc', 'แบบฝึกหัดเซต'),
            ['link' => ['url' => 'https://example.com/set', 'title' => 'อ่านเพิ่ม']],
            ['youtubeVideo' => ['id' => 'abc123', 'title' => 'คลิปเรื่องเซต', 'alternateLink' => 'https://www.youtube.com/watch?v=abc123']],
        ]])];

        $this->round();

        $post = ClassPost::query()->with('attachments.file')->sole();
        $this->assertSame(
            [ClassPost::KIND_ANNOUNCEMENT, 'เนื้อหา เซต', $this->classroom->id, $this->course->id, $this->teacher->id, ClassPost::STATE_PUBLISHED, ClassPost::AUDIENCE_ALL, false],
            [$post->kind, $post->body, $post->classroom_id, $post->course_id, $post->author_id, $post->state, $post->audience, $post->send_to_google],
        );
        $this->assertSame('2026-11-05T15:34:00+00:00', $post->published_at?->toIso8601String());

        $row = ClassPostGooglePost::query()->sole();
        $this->assertSame(
            [ClassPostGooglePost::ORIGIN_CLASSROOM, 'ann-web', self::COURSE_ID, ClassPostGooglePost::STATE_SENT, 'https://classroom.google.com/c/MTIz/p/ann-web'],
            [$row->origin, $row->google_id, $row->course_id, $row->state, $row->alternate_link],
        );

        $this->assertSame([
            ['file', 'สรุปเรื่องเซต ม.4.pdf', null],
            ['link', 'แบบฝึกหัดเซต', 'https://drive.google.com/file/d/f-doc/view'],
            ['link', 'อ่านเพิ่ม', 'https://example.com/set'],
            ['link', 'คลิปเรื่องเซต', 'https://www.youtube.com/watch?v=abc123'],
        ], $post->attachments->map(fn ($a) => [$a->kind, $a->title, $a->url])->all());
        $file = $post->attachments[0]->file;
        $this->assertSame(['application/pdf', 'f-pdf', null, $this->teacher->id], [$file->mime_type, $file->source_drive_file_id, $file->drive_file_id, $file->owner_id]);
        $this->assertSame(self::PDF, Storage::disk('local')->get($file->path));

        // The teacher sees where it came from; nothing was sent back to Classroom.
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/posts")->assertOk()
            ->assertJsonPath('data.0.google.origin', 'classroom')
            ->assertJsonPath('data.0.google.link', 'https://classroom.google.com/c/MTIz/p/ann-web');
        $this->assertCount(0, $this->sentTo('/announcements', 'POST'));
        $this->assertCount(0, $this->sentTo('/upload/drive'));
        $listed = $this->sentTo('/announcements?', 'GET');
        $this->assertStringContainsString('announcementStates=PUBLISHED', $listed[0]->url());

        // The student reads it under the subject and opens the PDF without Google.
        $student = $this->students[1];
        $this->asUser($student)->getJson("/api/v1/student/class-feed?classroom_id={$this->classroom->id}&course_id={$this->course->id}")->assertOk()
            ->assertJsonPath('data.0.post.body', 'เนื้อหา เซต');
        $this->asUser($student)->get("/api/v1/class-post-files/{$file->id}/file")->assertOk();
    }

    public function test_a_material_takes_its_topic_and_a_post_for_chosen_students_reaches_only_the_paired_ones(): void
    {
        $unit = Unit::query()->create(['course_id' => $this->course->id, 'title' => 'หน่วยที่ 1 เซต', 'position' => 1]);
        ClassroomGoogleTopic::query()->create(['course_id' => self::COURSE_ID, 'topic_id' => 't-unit', 'name' => 'หน่วยที่ 1 เซต', 'unit_id' => $unit->id]);
        $this->materials = [
            self::material('m-1', 'ใบความรู้เรื่องเซต', ['description' => "อ่านก่อนเรียน\nหน้า 1-3", 'topicId' => 't-set']),
            self::material('m-2', 'แบบฝึกหัดหน่วยที่ 1', ['topicId' => 't-unit', 'creationTime' => '2026-11-05T15:41:00.000Z']),
        ];
        $this->announcements = [self::announcement('ann-some', 'พบครูหลังเลิกเรียน', [
            'assigneeMode' => 'INDIVIDUAL_STUDENTS',
            'individualStudentsOptions' => ['studentIds' => ['g-1', 'g-not-in-krucheck']],
        ])];

        $this->round();

        $m1 = ClassPost::query()->where('title', 'ใบความรู้เรื่องเซต')->sole();
        $this->assertSame([ClassPost::KIND_MATERIAL, "อ่านก่อนเรียน\nหน้า 1-3", 'เซต', null], [$m1->kind, $m1->body, $m1->topic, $m1->unit_id]);
        $m2 = ClassPost::query()->where('title', 'แบบฝึกหัดหน่วยที่ 1')->sole();
        $this->assertSame([$unit->id, null], [$m2->unit_id, $m2->topic]);
        $this->assertSame('เซต', ClassroomGoogleTopic::query()->where('topic_id', 't-set')->value('name'), 'the topic name is remembered');

        $some = ClassPost::query()->where('kind', ClassPost::KIND_ANNOUNCEMENT)->sole();
        $this->assertSame(ClassPost::AUDIENCE_STUDENTS, $some->audience);
        $this->assertSame([$this->students[0]->id], $some->recipients()->pluck('users.id')->all());
        $this->asUser($this->students[1])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($this->students[0])->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_the_apps_own_copies_and_drafts_are_not_pulled_back(): void
    {
        $ours = ClassPost::query()->create([
            'classroom_id' => $this->classroom->id, 'author_id' => $this->teacher->id, 'kind' => ClassPost::KIND_ANNOUNCEMENT,
            'body' => 'สวัสดีนักเรียนทุกคน', 'audience' => ClassPost::AUDIENCE_ALL, 'state' => ClassPost::STATE_PUBLISHED, 'published_at' => now(),
        ]);
        ClassPostGooglePost::query()->create([
            'class_post_id' => $ours->id, 'course_id' => self::COURSE_ID, 'kind' => 'announcement', 'google_id' => 'ann-ours',
            'state' => ClassPostGooglePost::STATE_SENT, 'sent_at' => now(),
        ]);
        $this->announcements = [
            self::announcement('ann-ours', 'สวัสดีนักเรียนทุกคน', ['updateTime' => '2026-11-06T01:00:00.000Z']),
            self::announcement('ann-draft', 'ยังไม่เสร็จ', ['state' => 'DRAFT']),
        ];

        $this->round();
        $this->round();

        $this->assertSame([$ours->id], ClassPost::query()->pluck('id')->all());
        $this->assertSame(ClassPostGooglePost::ORIGIN_KRUCHECK, ClassPostGooglePost::query()->sole()->origin);
    }

    public function test_edits_and_deletions_in_classroom_follow_into_krucheck(): void
    {
        $this->drive['f-pdf'] = ['name' => 'สรุปเรื่องเซต ม.4.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF];
        $this->announcements = [self::announcement('ann-web', 'เนื้อหา เซต', ['materials' => [self::driveFile('f-pdf', 'สรุปเรื่องเซต ม.4.pdf')]])];
        $this->round();
        $post = ClassPost::query()->sole();
        $path = ClassPostFile::query()->sole()->path;

        // Unchanged: nothing is fetched again.
        $this->round();
        $this->assertCount(1, $this->sentTo('alt=media'));

        // Edited on the website: new text, the PDF swapped for a link.
        $this->announcements = [self::announcement('ann-web', 'เนื้อหา เซต (แก้ไข)', [
            'updateTime' => '2026-11-06T02:00:00.000Z',
            'materials' => [['link' => ['url' => 'https://example.com/set2']]],
        ])];
        $this->round();
        $post->refresh();
        $this->assertSame('เนื้อหา เซต (แก้ไข)', $post->body);
        $this->assertSame([['link', 'https://example.com/set2']], $post->attachments()->get()->map(fn ($a) => [$a->kind, $a->url])->all());
        $this->assertSame(0, ClassPostFile::query()->count(), 'the PDF no post uses is gone');
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('2026-11-06T02:00:00+00:00', ClassPostGooglePost::query()->sole()->google_updated_at?->toIso8601String());

        // Deleted on the website.
        $this->announcements = [];
        $this->round();
        $this->assertSame(0, ClassPost::query()->count());
        $this->assertSame(0, ClassPostGooglePost::query()->count());
    }

    public function test_old_posts_come_in_without_notifying_and_new_announcements_notify(): void
    {
        $notifier = $this->notifier();
        $this->announcements = [self::announcement('ann-old', 'ประกาศเก่า', ['creationTime' => '2026-10-01T01:00:00.000Z'])];
        $this->round();
        $this->assertSame(1, ClassPost::query()->count());
        $notifier->shouldNotHaveReceived('classPostPublished');

        $this->travel(5)->minutes();
        $this->announcements[] = self::announcement('ann-new', 'ประกาศใหม่', ['creationTime' => now()->toIso8601ZuluString(), 'updateTime' => now()->toIso8601ZuluString()]);
        $this->materials = [self::material('m-new', 'ใบความรู้ใหม่', ['creationTime' => now()->toIso8601ZuluString(), 'updateTime' => now()->toIso8601ZuluString()])];
        $this->round();

        $this->assertSame(3, ClassPost::query()->count());
        $new = ClassPost::query()->where('body', 'ประกาศใหม่')->sole();
        $notifier->shouldHaveReceived('classPostPublished')->once()->with(Mockery::on(fn (ClassPost $p) => $p->id === $new->id));
    }

    public function test_at_most_the_limit_comes_in_per_round_oldest_first(): void
    {
        config(['eduvision.classroom_sync.max_post_imports' => 2]);
        $this->announcements = [
            self::announcement('ann-3', 'สาม', ['creationTime' => '2026-11-03T01:00:00.000Z']),
            self::announcement('ann-1', 'หนึ่ง', ['creationTime' => '2026-11-01T01:00:00.000Z']),
            self::announcement('ann-2', 'สอง', ['creationTime' => '2026-11-02T01:00:00.000Z']),
        ];

        $this->round();
        $this->assertEqualsCanonicalizing(['หนึ่ง', 'สอง'], ClassPost::query()->pluck('body')->all());

        $this->round();
        $this->assertEqualsCanonicalizing(['หนึ่ง', 'สอง', 'สาม'], ClassPost::query()->pluck('body')->all());
    }

    public function test_files_that_cannot_be_copied_stay_links(): void
    {
        config(['eduvision.class_posts.max_file_mb' => 1, 'eduvision.class_posts.quota_mb' => 2]);
        $this->drive['f-big'] = ['name' => 'ใหญ่.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF, 'size' => 3 * 1024 * 1024];
        $this->drive['f-zip'] = ['name' => 'งาน.zip', 'mime' => 'application/zip', 'bytes' => 'PK...'];
        $this->drive['f-fits'] = ['name' => 'พอดี.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF, 'size' => 600 * 1024];
        $this->announcements = [self::announcement('ann-files', 'ไฟล์', ['materials' => [
            self::driveFile('f-big', 'ใหญ่.pdf'),
            self::driveFile('f-zip', 'งาน.zip'),
            self::driveFile('f-gone', 'หายไป.pdf'),
            self::driveFile('f-fits', 'พอดี.pdf'),
        ]])];
        // The teacher's space is almost full: the file that fits the size limit does not fit the quota.
        ClassPostFile::query()->create(['owner_id' => $this->teacher->id, 'path' => 'class-posts/x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1800 * 1024, 'sha256' => str_repeat('0', 64)]);

        $this->round();

        $post = ClassPost::query()->sole();
        $this->assertSame([
            ['link', 'ใหญ่.pdf', 'https://drive.google.com/file/d/f-big/view'],
            ['link', 'งาน.zip', 'https://drive.google.com/file/d/f-zip/view'],
            ['link', 'หายไป.pdf', 'https://drive.google.com/file/d/f-gone/view'],
            ['link', 'พอดี.pdf', 'https://drive.google.com/file/d/f-fits/view'],
        ], $post->attachments()->get()->map(fn ($a) => [$a->kind, $a->title, $a->url])->all());
        $this->assertCount(0, $this->sentTo('alt=media'));
    }

    public function test_a_pulled_post_is_read_only_while_its_course_is_linked(): void
    {
        $this->announcements = [self::announcement('ann-web', 'เนื้อหา เซต')];
        $this->round();
        $id = ClassPost::query()->sole()->id;

        $this->asUser($this->teacher)->putJson("/api/v1/class-posts/{$id}", ['body' => 'แก้'])->assertStatus(409)->assertJsonPath('code', 'post_from_classroom');
        $this->asUser($this->teacher)->postJson("/api/v1/class-posts/{$id}/google/retry")->assertStatus(409)->assertJsonPath('code', 'post_from_classroom');
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$id}")->assertStatus(409)->assertJsonPath('code', 'post_from_classroom');

        // Unlinked: the teacher may delete it, and Classroom's original is never touched.
        $this->link->delete();
        $this->asUser($this->teacher)->deleteJson("/api/v1/class-posts/{$id}")->assertNoContent();
        $this->assertCount(0, $this->sentTo('/announcements/', 'DELETE'));
    }

    public function test_a_course_google_refuses_is_logged_and_the_round_goes_on(): void
    {
        $this->refused = true;
        $this->announcements = [self::announcement('ann-web', 'เนื้อหา เซต')];
        $this->round();

        $this->assertSame(0, ClassPost::query()->count());
        $this->assertNotNull($this->link->refresh()->work_synced_at, 'the courseWork step of the round still ran');
    }
}
