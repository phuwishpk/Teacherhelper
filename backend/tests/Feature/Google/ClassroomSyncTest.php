<?php

namespace Tests\Feature\Google;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Google\ClassroomGradePusher;
use App\Domain\Google\ClassroomSync;
use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Events\SubmissionPublished;
use App\Jobs\ClassroomSyncJob;
use App\Jobs\PushClassroomGradeJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\GradeConflict;
use App\Models\Question;
use App\Models\SourceDocument;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\SubmissionPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * The cron sync with Google Classroom (DESIGN §19.3, §19.10): the
 * every-minute eduvision:queue-work queues a round at most every 5 minutes;
 * a round mirrors courseWork created on the Classroom website (key drafted
 * by AI, teacher notified, nothing graded before approval, no grade ever
 * pushed), syncs the hand-ins of open assignments within its limits, skips
 * teachers who must reconnect (one push per drop) and applies the late
 * policy. Google is Http::fake, Gemini the FakeGeminiClient.
 */
class ClassroomSyncTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    /** @var array<int, User> by student number */
    private array $students = [];

    private RecordingNotifier $notifier;

    private FakeGeminiClient $gemini;

    /** @var list<array<string, mixed>> courseWork.list of the course */
    private array $courseWork = [];

    /** @var array<string, list<array<string, mixed>>> studentSubmissions.list by courseWork id */
    private array $submissions = [];

    /** @var array<string, array{name: string, mime: string, bytes: string}> Drive files by id */
    private array $drive = [];

    private bool $grantDead = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->configureGoogle();
        $this->captureLogs();
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);

        $this->teacher = $this->makeTeacher();
        $this->connectGoogle($this->teacher);
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ม.1/1']);
        foreach ([1, 2] as $n) {
            $this->students[$n] = $this->enrollStudent($this->classroom, $n, "นักเรียนคนที่ {$n}")['student'];
            ClassroomStudent::query()->where('student_id', $this->students[$n]->id)->update(['google_user_id' => "g-{$n}"]);
        }
        $this->link($this->classroom, $this->teacher, self::COURSE_ID);

        $this->fakeGoogle([
            'oauth2.googleapis.com/token' => fn () => $this->grantDead
                ? Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)
                : Http::response(self::tokenBody()),
            'classroom.googleapis.com/v1/courses/*/courseWork/*/studentSubmissions*' => function (Request $request) {
                $courseWorkId = (string) preg_replace('#^.*/courseWork/([^/]+)/studentSubmissions.*$#', '$1', $request->url());

                return Http::response(['studentSubmissions' => $this->submissions[$courseWorkId] ?? []]);
            },
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork*' => fn () => Http::response(['courseWork' => $this->courseWork]),
            'classroom.googleapis.com/v1/courses/*/courseWork*' => Http::response(['courseWork' => []]),
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
                    $meta['size'] = (string) strlen($file['bytes']);
                }

                return Http::response($meta);
            },
        ]);
    }

    private function link(Classroom $classroom, User $owner, string $courseId): ClassroomGoogleLink
    {
        return ClassroomGoogleLink::create(['classroom_id' => $classroom->id, 'course_id' => $courseId, 'course_name' => 'คณิต', 'owner_user_id' => $owner->id, 'linked_at' => now()->subDay()]);
    }

    /** An app assignment posted to Classroom as $courseWorkId, key approved. */
    private function posted(string $courseWorkId, array $attributes = [], ?Classroom $classroom = null): Assignment
    {
        $classroom ??= $this->classroom;
        $assignment = Assignment::factory()->for_classroom($classroom)->create([
            'title' => "งาน {$courseWorkId}",
            'status' => Assignment::STATUS_READY,
            'mode' => Assignment::MODE_FREEFORM,
            'key_approved_at' => now(),
            ...$attributes,
        ]);
        Question::factory()->short()->create(['assignment_id' => $assignment->id]);
        AssignmentGoogleLink::create(['assignment_id' => $assignment->id, 'course_work_id' => $courseWorkId, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $classroom->teacher_id, 'posted_at' => now()]);

        return $assignment;
    }

    /** @return array<string, mixed> a CourseWork resource created on the website */
    private static function webWork(string $id, array $attributes = []): array
    {
        return [
            'courseId' => self::COURSE_ID,
            'id' => $id,
            'title' => 'แบบฝึกหัดบทที่ 2',
            'description' => 'ทำข้อ 1-4 ในใบงาน แสดงวิธีทำ',
            'state' => 'PUBLISHED',
            'workType' => 'ASSIGNMENT',
            'alternateLink' => "https://classroom.google.com/c/x/a/{$id}/details",
            'creationTime' => now()->subHour()->toIso8601ZuluString(),
            'dueDate' => ['year' => 2026, 'month' => 10, 'day' => 5],
            'dueTime' => ['hours' => 16, 'minutes' => 59],
            'maxPoints' => 10,
            'associatedWithDeveloper' => false,
            ...$attributes,
        ];
    }

    private static function jpeg(string $markers = ''): string
    {
        return "\xFF\xD8\xFF\xE0JFIF page ".$markers.' '.bin2hex(random_bytes(4));
    }

    private function round(): void
    {
        app(ClassroomSync::class)->run();
    }

    /** @return list<GeminiRequest> */
    private function sent(string $purpose): array
    {
        return array_values(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === $purpose));
    }

    public function test_the_cron_queues_a_sync_round_at_most_every_five_minutes(): void
    {
        Queue::fake();

        $this->artisan('eduvision:queue-work', ['--memory' => 1024])->assertSuccessful();
        $this->artisan('eduvision:queue-work', ['--memory' => 1024])->assertSuccessful();
        Queue::assertPushed(ClassroomSyncJob::class, 1);
        Queue::assertPushed(ClassroomSyncJob::class, fn (ClassroomSyncJob $job) => $job->classroomId === null);

        $this->travel(ClassroomSyncJob::INTERVAL_SECONDS + 1)->seconds();
        $this->artisan('eduvision:queue-work', ['--memory' => 1024])->assertSuccessful();
        Queue::assertPushed(ClassroomSyncJob::class, 2);

        // Without the OAuth client there is nothing to sync.
        Cache::flush();
        config(['services.google.client_id' => '']);
        $this->assertFalse(ClassroomSyncJob::dispatchIfDue());
        Queue::assertPushed(ClassroomSyncJob::class, 2);
    }

    public function test_a_round_mirrors_web_coursework_drafts_its_key_and_tells_the_teacher(): void
    {
        $this->drive['f-sheet'] = ['name' => 'ใบงานบทที่2.jpg', 'mime' => 'image/jpeg', 'bytes' => self::jpeg('questions only')];
        $this->drive['f-doc'] = ['name' => 'เฉลย', 'mime' => 'application/vnd.google-apps.document', 'bytes' => ''];
        $this->courseWork = [
            self::webWork('cw-web', ['materials' => [
                ['driveFile' => ['driveFile' => ['id' => 'f-sheet', 'title' => 'ใบงานบทที่2.jpg'], 'shareMode' => 'VIEW']],
                ['driveFile' => ['driveFile' => ['id' => 'f-doc', 'title' => 'เฉลย'], 'shareMode' => 'VIEW']],
                ['link' => ['url' => 'https://example.com']],
            ]]),
            self::webWork('cw-app', ['associatedWithDeveloper' => true]),
            self::webWork('cw-old', ['creationTime' => now()->subDays(3)->toIso8601ZuluString()]),
            self::webWork('cw-quiz', ['workType' => 'SHORT_ANSWER_QUESTION']),
        ];

        $this->round();

        $mirror = Assignment::query()->where('source', Assignment::SOURCE_CLASSROOM_WEB)->sole();
        $this->assertSame(
            ['แบบฝึกหัดบทที่ 2', Assignment::MODE_FREEFORM, Assignment::STATUS_DRAFT, null, $this->classroom->id, $this->teacher->id, '2026-10-05T16:59:00+00:00'],
            [$mirror->title, $mirror->mode, $mirror->status, $mirror->subject_id, $mirror->classroom_id, $mirror->created_by, $mirror->due_at?->toIso8601String()],
        );
        $link = $mirror->googleLink()->sole();
        $this->assertSame([AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB, 'cw-web', $this->teacher->id], [$link->origin, $link->course_work_id, $link->posted_by]);
        $this->assertSame([
            ['drive_file_id' => 'f-sheet', 'title' => 'ใบงานบทที่2.jpg', 'mime_type' => 'image/jpeg', 'supported' => true],
            ['drive_file_id' => 'f-doc', 'title' => 'เฉลย', 'mime_type' => 'application/vnd.google-apps.document', 'supported' => false],
        ], $link->materials);
        $this->assertSame(1, AssignmentGoogleLink::query()->count(), 'the app\'s own, old and quiz courseWork are not mirrored');

        // Drafted at once from the title, the instructions and the readable material.
        $draft = $this->sent('answer_key_draft');
        $this->assertCount(1, $draft);
        $this->assertCount(1, $draft[0]->images);
        $this->assertStringContainsString('ทำข้อ 1-4 ในใบงาน แสดงวิธีทำ', $draft[0]->userText);
        $this->assertSame(1, SourceDocument::query()->where('uploaded_by', $this->teacher->id)->count());
        $this->assertSame([Assignment::KEY_AI_DRAFT, null, 4], [$mirror->refresh()->key_origin, $mirror->key_approved_at, $mirror->questions()->count()]);

        $pushes = $this->notifier->ofType(PushMessage::CLASSROOM_WORK_IMPORTED);
        $this->assertCount(1, $pushes);
        $this->assertSame([$this->teacher->id], $pushes[0][0]);
        $this->assertSame(['type' => 'classroom_work_imported', 'assignment_id' => (string) $mirror->id], $pushes[0][1]->data());
        $this->assertStringContainsString('มีงานใหม่จาก Classroom รออนุมัติเฉลย', $pushes[0][1]->body);
        $this->assertNotNull($this->classroom->googleLink()->sole()->work_synced_at);

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$mirror->id}")
            ->assertOk()
            ->assertJsonPath('data.source', 'classroom_web')
            ->assertJsonPath('data.subject', null)
            ->assertJsonPath('data.google_link.origin', 'classroom_web')
            ->assertJsonPath('data.google_link.can_push_grades', false);
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertOk()->assertJsonPath('data.keys_pending', 1);

        // The next round finds nothing new.
        $this->round();
        $this->assertSame(1, Assignment::query()->where('source', Assignment::SOURCE_CLASSROOM_WEB)->count());
        $this->assertCount(1, $this->sent('answer_key_draft'));
        $this->assertCount(1, $this->notifier->ofType(PushMessage::CLASSROOM_WORK_IMPORTED));
        $this->assertNoSecretInLogs();
    }

    public function test_web_coursework_without_instructions_or_readable_material_is_not_drafted(): void
    {
        $this->courseWork = [self::webWork('cw-bare', ['description' => ''])];

        $this->round();

        $mirror = Assignment::query()->where('source', Assignment::SOURCE_CLASSROOM_WEB)->sole();
        $this->assertSame([], $this->sent('answer_key_draft'));
        $this->assertSame([null, 0], [$mirror->key_origin, $mirror->questions()->count()]);
        $this->assertCount(1, $this->notifier->ofType(PushMessage::CLASSROOM_WORK_IMPORTED));
    }

    public function test_hand_ins_wait_for_the_key_and_approving_a_mirror_needs_a_subject(): void
    {
        $this->courseWork = [self::webWork('cw-web')];
        $this->round();
        $mirror = Assignment::query()->where('source', Assignment::SOURCE_CLASSROOM_WEB)->sole();
        $mirror->questions()->update(['rubric_status' => Question::RUBRIC_APPROVED]);

        $this->drive['f-1'] = ['name' => 'IMG_1.jpg', 'mime' => 'image/jpeg', 'bytes' => self::jpeg()];
        $this->submissions['cw-web'] = [self::turnedIn('sub-1', 'g-1', [['f-1', 'IMG_1.jpg']])];
        $this->round();

        $import = ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-1')->sole();
        $this->assertSame(ClassroomSubmissionImport::STATE_WAITING_KEY, $import->state);
        $this->assertSame(SubmissionPage::STATE_STORED, SubmissionPage::query()->sole()->state);
        $this->assertSame([], $this->sent('extract_page'), 'nothing is graded before the key is approved');

        $url = "/api/v1/assignments/{$mirror->id}/answer-key/approve";
        $this->asUser($this->teacher)->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('code', 'course_required')
            ->assertJsonStructure(['errors' => ['subject_id']]);
        $subject = Subject::factory()->create();
        $this->asUser($this->teacher)->postJson($url, ['subject_id' => 'x'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson($url, ['subject_id' => $subject->id])
            ->assertOk()
            ->assertJsonPath('data.status', Assignment::STATUS_READY);

        $this->assertSame($subject->id, $mirror->refresh()->subject_id);
        $this->assertSame(ClassroomSubmissionImport::STATE_IMPORTED, $import->refresh()->state);
        $this->assertNotEmpty($this->sent('extract_page'));
        $this->assertSame(4, Submission::query()->where('assignment_id', $mirror->id)->sole()->responses()->count());
    }

    public function test_grades_are_never_pushed_for_web_coursework(): void
    {
        $mirror = $this->posted('cw-web', ['source' => Assignment::SOURCE_CLASSROOM_WEB]);
        AssignmentGoogleLink::query()->whereKey($mirror->id)->update(['origin' => AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB]);
        $submission = Submission::create(['assignment_id' => $mirror->id, 'student_id' => $this->students[1]->id, 'status' => Submission::STATUS_PUBLISHED, 'total_score' => 2, 'published_at' => now()]);

        Queue::fake([PushClassroomGradeJob::class]);
        event(new SubmissionPublished($submission->id, $mirror->id, $this->students[1]->id, $this->teacher->id));
        Queue::assertNotPushed(PushClassroomGradeJob::class);

        $this->assertSame(ClassroomGradePusher::SKIPPED, app(ClassroomGradePusher::class)->push($submission->id));
        $this->assertCount(0, $this->sentTo('/studentSubmissions'));

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$mirror->id}/google-grades/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'coursework_not_owned');
    }

    public function test_a_round_skips_teachers_who_must_reconnect_and_syncs_at_most_the_limit(): void
    {
        config(['eduvision.classroom_sync.max_coursework' => 1]);
        $older = $this->posted('cw-1');
        $newer = $this->posted('cw-2');
        AssignmentGoogleLink::query()->whereKey($older->id)->update(['last_synced_at' => now()->subHour()]);
        AssignmentGoogleLink::query()->whereKey($newer->id)->update(['last_synced_at' => now()->subMinutes(10)]);
        $this->posted('cw-closed', ['status' => Assignment::STATUS_CLOSED]);

        $other = $this->makeTeacher();
        $this->connectGoogle($other, ['last_error' => GoogleAccount::ERROR_INVALID_GRANT]);
        $otherRoom = $this->makeClassroom($other);
        $this->link($otherRoom, $other, 'course-of-other');
        $this->posted('cw-other', [], $otherRoom);

        $this->round();
        $this->assertCount(1, $this->sentTo('/courseWork/cw-1/studentSubmissions'));
        $this->assertCount(0, $this->sentTo('/courseWork/cw-2/'));
        $this->assertCount(0, $this->sentTo('course-of-other'));
        $this->assertCount(0, $this->sentTo('/courseWork/cw-closed/'));
        $this->assertStringContainsString('states=TURNED_IN&states=RETURNED', $this->sentTo('/courseWork/cw-1/studentSubmissions')[0]->url());

        $this->round();
        $this->assertCount(1, $this->sentTo('/courseWork/cw-2/studentSubmissions'), 'the one left waits for the next round');
        $this->assertCount(0, $this->sentTo('course-of-other'));
    }

    public function test_an_expired_grant_marks_the_teacher_and_pushes_once_per_drop(): void
    {
        $this->posted('cw-1');
        $this->grantDead = true;

        $this->round();
        $account = GoogleAccount::query()->findOrFail($this->teacher->id);
        $this->assertSame(GoogleAccount::ERROR_INVALID_GRANT, $account->last_error);
        $this->assertNotNull($account->reconnect_notified_at);
        $pushes = $this->notifier->ofType(PushMessage::GOOGLE_RECONNECT);
        $this->assertCount(1, $pushes);
        $this->assertSame([$this->teacher->id], $pushes[0][0]);
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.needs_reconnect', true);

        // Skipped from now on, and no second push for the same drop.
        $sent = count(Http::recorded());
        $this->round();
        $this->assertCount($sent, Http::recorded());
        $this->asUser($this->teacher)->getJson('/api/v1/google/status')->assertJsonPath('data.needs_reconnect', true);
        $this->assertCount(1, $this->notifier->ofType(PushMessage::GOOGLE_RECONNECT));

        // A new connect clears the drop: the next one is pushed again.
        $this->grantDead = false;
        $this->fakeGoogle(['classroom.googleapis.com/v1/userProfiles/me' => Http::response(['id' => 'google-sub-'.$this->teacher->id, 'emailAddress' => 'kru@school.example'])]);
        $this->asUser($this->teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQ-new-code'])->assertOk();
        $this->assertSame([null, null], [$account->refresh()->last_error, $account->reconnect_notified_at]);
        $this->grantDead = true;
        app(GoogleAccessTokens::class)->forget($account);
        $this->round();
        $this->assertCount(2, $this->notifier->ofType(PushMessage::GOOGLE_RECONNECT));
        $this->assertNoSecretInLogs();
    }

    public function test_a_late_hand_in_is_refused_until_the_teacher_accepts_it(): void
    {
        $assignment = $this->posted('cw-1', ['accept_late' => false]);
        $this->drive['f-1'] = ['name' => 'IMG_1.jpg', 'mime' => 'image/jpeg', 'bytes' => self::jpeg()];
        $late = self::turnedIn('sub-1', 'g-1', [['f-1', 'IMG_1.jpg']]);
        $late['late'] = true;
        $this->submissions['cw-1'] = [$late];

        $this->round();
        $import = ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-1')->sole();
        $this->assertSame([ClassroomSubmissionImport::STATE_REJECTED_LATE, true, $this->students[1]->id], [$import->state, $import->late, $import->student_id]);
        $this->assertCount(0, $this->sentTo('alt=media'), 'not downloaded');
        $this->assertSame(0, Submission::query()->where('assignment_id', $assignment->id)->count());

        // Still refused on the next round.
        $this->round();
        $this->assertSame(ClassroomSubmissionImport::STATE_REJECTED_LATE, $import->refresh()->state);

        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$import->id}/accept-late")
            ->assertStatus(202)
            ->assertJsonPath('data.state', ClassroomSubmissionImport::STATE_NEW)
            ->assertJsonPath('data.late', true);
        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$import->id}/accept-late")
            ->assertStatus(409)
            ->assertJsonPath('code', 'import_not_rejected');
        $this->assertCount(0, $this->sentTo('alt=media'), 'fetched by the next round, not at once');

        // The next round downloads and grades it, labelled late.
        $this->round();
        $this->assertSame(ClassroomSubmissionImport::STATE_IMPORTED, $import->refresh()->state);
        $this->assertTrue($import->late);
        $this->assertTrue(Submission::query()->where('assignment_id', $assignment->id)->sole()->late);
    }

    public function test_a_late_hand_in_is_taken_with_a_label_when_late_work_is_accepted(): void
    {
        $assignment = $this->posted('cw-1');
        $this->drive['f-1'] = ['name' => 'IMG_1.jpg', 'mime' => 'image/jpeg', 'bytes' => self::jpeg()];
        $late = self::turnedIn('sub-1', 'g-1', [['f-1', 'IMG_1.jpg']]);
        $late['late'] = true;
        $this->submissions['cw-1'] = [$late];

        $this->round();

        $import = ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-1')->sole();
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, true], [$import->state, $import->late]);
        $this->assertTrue(Submission::query()->where('assignment_id', $assignment->id)->sole()->late);
        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$import->id}/accept-late")
            ->assertStatus(409)
            ->assertJsonPath('code', 'import_not_rejected');
    }

    public function test_an_unknown_submitter_queues_a_roster_sync(): void
    {
        $this->posted('cw-1');
        $this->drive['f-9'] = ['name' => 'IMG_9.jpg', 'mime' => 'image/jpeg', 'bytes' => self::jpeg()];
        $this->submissions['cw-1'] = [self::turnedIn('sub-9', 'g-new', [['f-9', 'IMG_9.jpg']])];
        Queue::fake([SyncClassroomRosterJob::class]);

        $this->round();

        Queue::assertPushed(SyncClassroomRosterJob::class, fn ($job) => $job->classroomId === $this->classroom->id);
        $this->assertSame(ClassroomSubmissionImport::STATE_NEW, ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-9')->value('state'));
    }

    public function test_sync_now_queues_a_round_of_the_classroom(): void
    {
        Queue::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-sync")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);
        Queue::assertPushed(ClassroomSyncJob::class, fn (ClassroomSyncJob $job) => $job->classroomId === $this->classroom->id);

        $unlinked = $this->makeClassroom($this->teacher);
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$unlinked->id}/google-sync")
            ->assertStatus(422)
            ->assertJsonPath('code', 'classroom_not_linked');

        GoogleAccount::query()->whereKey($this->teacher->id)->update(['last_error' => GoogleAccount::ERROR_INVALID_GRANT]);
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_reconnect_required');
        Queue::assertPushed(ClassroomSyncJob::class, 1);
    }

    public function test_the_classroom_shows_when_its_work_was_last_synced(): void
    {
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}")
            ->assertOk()
            ->assertJsonPath('data.google_link.work_synced_at', null)
            ->assertJsonPath('data.google_link.roster_synced_at', null);

        $this->round();

        $synced = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}")
            ->assertOk()
            ->json('data.google_link.work_synced_at');
        $this->assertIsString($synced);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($synced), 5);
    }

    public function test_a_round_for_one_classroom_touches_only_that_course(): void
    {
        $this->posted('cw-1');
        $otherRoom = $this->makeClassroom($this->teacher);
        $this->link($otherRoom, $this->teacher, 'course-two');
        $this->posted('cw-two', [], $otherRoom);

        app(ClassroomSync::class)->run($this->classroom->id);

        $this->assertCount(1, $this->sentTo('/courseWork/cw-1/studentSubmissions'));
        $this->assertCount(0, $this->sentTo('course-two'));
    }

    public function test_the_attention_card_counts_what_waits_for_the_teacher(): void
    {
        $assignment = $this->posted('cw-1');
        Assignment::factory()->for_classroom($this->classroom)->create(['mode' => Assignment::MODE_FREEFORM]);
        Assignment::factory()->for_classroom($this->classroom)->create(['mode' => Assignment::MODE_FREEFORM, 'status' => Assignment::STATUS_CLOSED]);
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $this->students[1]->id, 'status' => Submission::STATUS_PUBLISHED, 'total_score' => 2, 'regrade_pending' => true]);
        $import = ClassroomSubmissionImport::create([
            'assignment_id' => $assignment->id, 'google_submission_id' => 'sub-1', 'google_user_id' => 'g-1', 'student_id' => $this->students[1]->id,
            'state' => ClassroomSubmissionImport::STATE_GRADE_FAILED, 'attachments' => [], 'google_update_time' => 'T1',
        ]);
        GradeConflict::create(['submission_id' => $submission->id, 'import_id' => $import->id, 'app_score' => 2, 'classroom_score' => 1, 'detected_at' => now()]);

        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')
            ->assertOk()
            ->assertExactJson(['data' => [
                'keys_pending' => 1,
                'grade_conflicts' => 1,
                'grade_failed' => 1,
                'feedback_failed' => 0,
                'regrade_pending' => 1,
                'needs_reconnect' => false,
            ]]);

        // Another teacher sees only their own.
        $this->asUser($this->makeTeacher())->getJson('/api/v1/teacher/attention')
            ->assertOk()
            ->assertJsonPath('data.keys_pending', 0)
            ->assertJsonPath('data.grade_conflicts', 0);
    }
}
