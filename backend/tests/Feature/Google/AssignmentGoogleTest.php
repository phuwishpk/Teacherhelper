<?php

namespace Tests\Feature\Google;

use App\Domain\Google\CourseWorkPoster;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Jobs\FetchClassroomAttachmentsJob;
use App\Jobs\PushClassroomGradeJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * POST /assignments/{id}/google-post, GET /assignments/{id}/google-submissions,
 * POST /google-submissions/{id}/return, POST /assignments/{id}/google-grades/retry
 * and GET /student/retake-requests (DESIGN §18.2, §18.6).
 */
class AssignmentGoogleTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Assignment $assignment;

    /** @var array<int, User> by student number */
    private array $students = [];

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->captureLogs();
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);

        $this->teacher = $this->makeTeacher();
        $this->connectGoogle($this->teacher);
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ม.1/1']);
        foreach ([1, 2, 3] as $n) {
            $this->students[$n] = $this->enrollStudent($this->classroom, $n, "นักเรียนคนที่ {$n}")['student'];
        }
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิต ม.1/1', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        ClassroomStudent::query()->where('student_id', $this->students[1]->id)->update(['google_user_id' => 'g-1']);
        ClassroomStudent::query()->where('student_id', $this->students[2]->id)->update(['google_user_id' => 'g-2']);

        $this->assignment = Assignment::factory()->for_classroom($this->classroom)->create(['title' => 'เศษส่วน ชุดที่ 3']);
        Question::factory()->create(['assignment_id' => $this->assignment->id]);
        Question::factory()->short()->create(['assignment_id' => $this->assignment->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/layout")->assertCreated();
        $this->assignment->refresh();
    }

    private function courseWorkUrl(): string
    {
        return 'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork';
    }

    private function submissionsUrl(): string
    {
        return $this->courseWorkUrl().'/'.self::COURSE_WORK_ID.'/studentSubmissions';
    }

    private function posted(): AssignmentGoogleLink
    {
        return AssignmentGoogleLink::create([
            'assignment_id' => $this->assignment->id,
            'course_work_id' => self::COURSE_WORK_ID,
            'alternate_link' => 'https://classroom.google.com/c/x/a/y/details',
            'posted_by' => $this->teacher->id,
            'posted_at' => now(),
        ]);
    }

    private function import(string $id, array $attributes = []): ClassroomSubmissionImport
    {
        return ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignment->id,
            'google_submission_id' => $id,
            'google_user_id' => 'g-1',
            'student_id' => $this->students[1]->id,
            'state' => ClassroomSubmissionImport::STATE_NEW,
            'attachments' => [['drive_file_id' => 'f1', 'title' => 'IMG_1.jpg', 'mime_type' => 'image/jpeg']],
            'google_update_time' => '2026-09-20T02:15:00.000Z',
            ...$attributes,
        ]);
    }

    public function test_posting_creates_a_published_course_work_with_full_marks_and_instructions(): void
    {
        $this->fakeGoogle([$this->courseWorkUrl() => Http::response(['id' => self::COURSE_WORK_ID, 'alternateLink' => 'https://classroom.google.com/c/x/a/y/details'])]);
        $due = now()->addDays(3)->setTime(9, 30)->utc();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-post", [
            'attach_blank_worksheet' => false,
            'instructions' => 'ส่งภายในวันศุกร์',
            'due_at' => $due->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.course_work_id', self::COURSE_WORK_ID)
            ->assertJsonPath('data.alternate_link', 'https://classroom.google.com/c/x/a/y/details')
            ->assertJsonPath('data.has_blank_worksheet', false)
            ->assertJsonPath('data.drive_file_id', null);

        $sent = $this->sentTo('/courseWork', 'POST')[0];
        $this->assertSame('เศษส่วน ชุดที่ 3', $sent['title']);
        $this->assertSame(['ASSIGNMENT', 'PUBLISHED'], [$sent['workType'], $sent['state']]);
        $this->assertEquals(3.0, $sent['maxPoints']);
        $this->assertStringStartsWith(CourseWorkPoster::INSTRUCTIONS, $sent['description']);
        $this->assertStringContainsString('ส่งภายในวันศุกร์', $sent['description']);
        $this->assertSame(['year' => $due->year, 'month' => $due->month, 'day' => $due->day], $sent['dueDate']);
        $this->assertSame(['hours' => 9, 'minutes' => 30], $sent['dueTime']);
        $this->assertArrayNotHasKey('materials', $sent->data());
        $this->assertTrue($sent->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}")
            ->assertJsonPath('data.google_link.course_work_id', self::COURSE_WORK_ID);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-post", ['attach_blank_worksheet' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_posted');
        $this->assertCount(1, $this->sentTo('/courseWork', 'POST'));

        // A posted assignment can no longer be deleted, even back in draft.
        $this->assignment->forceFill(['status' => Assignment::STATUS_DRAFT])->save();
        $this->asUser($this->teacher)->deleteJson("/api/v1/assignments/{$this->assignment->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_posted');
    }

    public function test_no_spare_worksheet_is_attached_any_more(): void
    {
        // DESIGN §19.4 drops the anonymous spare sheet of §18.3: older apps may still ask for it.
        $this->fakeGoogle([
            $this->courseWorkUrl() => Http::response(['id' => self::COURSE_WORK_ID, 'alternateLink' => 'https://classroom.google.com/x']),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-post", ['attach_blank_worksheet' => true])
            ->assertCreated()
            ->assertJsonPath('data.drive_file_id', null)
            ->assertJsonPath('data.has_blank_worksheet', false);

        $this->assertCount(0, $this->sentTo('/upload/drive/v3/files'));
        $work = $this->sentTo('/courseWork', 'POST')[0];
        $this->assertArrayNotHasKey('materials', $work->data());
        $this->assertStringNotContainsString('ใบงานสำรอง', $work['description']);
        $this->assertStringContainsString('ส่งได้ไม่เกิน 5 หน้า ไฟล์ละไม่เกิน 10 MB', $work['description']);
    }

    public function test_posting_needs_a_ready_assignment_a_linked_room_and_a_google_account(): void
    {
        $this->fakeGoogle();
        $url = "/api/v1/assignments/{$this->assignment->id}/google-post";

        $this->asUser($this->teacher)->postJson($url, ['due_at' => '2020-01-01T00:00:00Z'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['due_at']]);

        $this->assignment->forceFill(['status' => Assignment::STATUS_DRAFT])->save();
        $this->asUser($this->teacher)->postJson($url, ['attach_blank_worksheet' => false])
            ->assertStatus(409)->assertJsonPath('code', 'assignment_not_ready');

        $this->assignment->forceFill(['status' => Assignment::STATUS_READY])->save();
        ClassroomGoogleLink::query()->delete();
        $this->asUser($this->teacher)->postJson($url, ['attach_blank_worksheet' => false])
            ->assertStatus(422)->assertJsonPath('code', 'classroom_not_linked');

        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        $this->teacher->googleAccount()->delete();
        $this->asUser($this->teacher)->postJson($url, ['attach_blank_worksheet' => false])
            ->assertStatus(409)->assertJsonPath('code', 'google_not_connected');

        $stranger = $this->makeTeacher($this->teacher->school);
        $this->asUser($stranger)->postJson($url, ['attach_blank_worksheet' => false])->assertNotFound();
        $this->assertCount(0, $this->sentTo('classroom.googleapis.com'));
    }

    public function test_sync_keeps_one_row_per_turned_in_submission_with_attachments(): void
    {
        Queue::fake([SyncClassroomRosterJob::class, FetchClassroomAttachmentsJob::class]);
        $this->posted();
        $this->fakeGoogle([
            $this->submissionsUrl().'*' => Http::response(['studentSubmissions' => [
                self::turnedIn('sub-1', 'g-1', [['f-a', 'IMG_0001.JPG'], ['f-b', 'หน้า 2.pdf']]),
                self::turnedIn('sub-2', 'g-2', [['f-c', 'scan-from-app']]),
                self::turnedIn('sub-3', 'g-9', [['f-d', 'photo.heic']]),
                self::turnedIn('sub-4', 'g-3', []),
            ]]),
            'www.googleapis.com/drive/v3/files/f-c*' => Http::response(['mimeType' => 'image/webp']),
        ]);

        $res = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.next_cursor', null);

        $this->assertStringContainsString('states=TURNED_IN', $this->sentTo('/studentSubmissions')[0]->url());
        $rows = collect($res->json('data'))->keyBy('google_submission_id');
        $this->assertSame(['id' => $this->students[1]->id, 'name' => 'นักเรียนคนที่ 1', 'student_number' => 1], $rows['sub-1']['student']);
        $this->assertSame([
            ['drive_file_id' => 'f-a', 'title' => 'IMG_0001.JPG', 'mime_type' => 'image/jpeg'],
            ['drive_file_id' => 'f-b', 'title' => 'หน้า 2.pdf', 'mime_type' => 'application/pdf'],
        ], $rows['sub-1']['attachments']);
        $this->assertSame('image/webp', $rows['sub-2']['attachments'][0]['mime_type'], 'asked Drive for a name without extension');
        $this->assertSame('image/heic', $rows['sub-3']['attachments'][0]['mime_type']);
        $this->assertNull($rows['sub-3']['student'], 'g-9 is not matched');
        $this->assertSame('new', $rows['sub-1']['state']);
        $this->assertStringContainsString('sub-1', $rows['sub-1']['alternate_link']);
        $this->assertSame(['sub-1', 'sub-2', 'sub-3'], array_column($res->json('data'), 'google_submission_id'), 'by student number, unmatched last');
        $this->assertCount(1, $this->sentTo('/drive/v3/files/'), 'only the file without a known extension');
        $this->assertNoSecretIn($res->getContent(), 'the submissions answer');
        // g-9 and g-3 are matched to no student: one roster sync for the room (§19.2).
        Queue::assertPushed(SyncClassroomRosterJob::class, 1);
        Queue::assertPushed(SyncClassroomRosterJob::class, fn (SyncClassroomRosterJob $job) => $job->classroomId === $this->classroom->id);
        // The server downloads the files of the matched new rows (§19.4): sub-1 and sub-2.
        Queue::assertPushed(FetchClassroomAttachmentsJob::class, 2);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")->assertOk();
        Queue::assertPushed(SyncClassroomRosterJob::class, 1);
    }

    public function test_resync_renews_only_new_hand_ins(): void
    {
        Queue::fake([FetchClassroomAttachmentsJob::class]);
        $this->posted();
        $imported = $this->import('sub-1', ['state' => ClassroomSubmissionImport::STATE_IMPORTED]);
        $graded = $this->import('sub-2', ['google_user_id' => 'g-2', 'student_id' => $this->students[2]->id, 'state' => ClassroomSubmissionImport::STATE_GRADED, 'attachments' => [['drive_file_id' => 'f2', 'title' => 'a.jpg', 'mime_type' => 'image/jpeg']]]);
        $returned = $this->import('sub-3', ['google_user_id' => 'g-3', 'student_id' => null, 'state' => ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE, 'retake_reason' => 'รูปเบลอ']);
        ClassroomStudent::query()->where('student_id', $this->students[3]->id)->update(['google_user_id' => 'g-3']);
        $this->fakeGoogle([$this->submissionsUrl().'*' => Http::response(['studentSubmissions' => [
            // Same photo, new updateTime (e.g. our own grade push): stays imported.
            self::turnedIn('sub-1', 'g-1', [['f1', 'IMG_1.jpg']], '2026-09-21T00:00:00Z'),
            // New photo after grading: to be scanned again.
            self::turnedIn('sub-2', 'g-2', [['f2-new', 'b.png']], '2026-09-21T00:00:00Z'),
            // Handed in again after the retake request.
            self::turnedIn('sub-3', 'g-3', [['f1', 'IMG_1.jpg']], '2026-09-21T00:00:00Z'),
        ]])]);

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")->assertOk();

        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, '2026-09-21T00:00:00Z'], [$imported->refresh()->state, $imported->google_update_time]);
        $this->assertSame(ClassroomSubmissionImport::STATE_NEW, $graded->refresh()->state);
        $this->assertSame('image/png', $graded->attachments[0]['mime_type']);
        $returned->refresh();
        // The retake reason stays until the files are fetched: that hand-in is graded at once (§19.4).
        $this->assertSame([ClassroomSubmissionImport::STATE_NEW, 'รูปเบลอ', $this->students[3]->id], [$returned->state, $returned->retake_reason, $returned->student_id]);
        $this->assertNull($graded->retake_reason);
        Queue::assertPushed(FetchClassroomAttachmentsJob::class, fn (FetchClassroomAttachmentsJob $job) => $job->importId === $graded->id);
        Queue::assertPushed(FetchClassroomAttachmentsJob::class, fn (FetchClassroomAttachmentsJob $job) => $job->importId === $returned->id);
        Queue::assertNotPushed(FetchClassroomAttachmentsJob::class, fn (FetchClassroomAttachmentsJob $job) => $job->importId === $imported->id);
    }

    public function test_sync_errors(): void
    {
        $this->fakeGoogle([$this->submissionsUrl().'*' => Http::response(self::googleError(404, 'NOT_FOUND', 'Requested entity was not found.'), 404)]);
        $url = "/api/v1/assignments/{$this->assignment->id}/google-submissions";

        $this->asUser($this->teacher)->getJson($url)->assertStatus(409)->assertJsonPath('code', 'not_posted');

        $this->posted();
        $this->asUser($this->teacher)->getJson($url)->assertStatus(409)->assertJsonPath('code', 'google_not_found');

        $this->asUser($this->makeTeacher($this->teacher->school))->getJson($url)->assertNotFound();
        $this->asUser($this->students[1])->getJson($url)->assertForbidden();
    }

    public function test_return_for_retake_returns_in_classroom_and_tells_the_student_why(): void
    {
        $this->posted();
        $row = $this->import('sub-1', ['alternate_link' => 'https://classroom.google.com/sub-1']);
        $this->fakeGoogle([$this->submissionsUrl().'/sub-1:return' => Http::response([])]);

        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$row->id}/return", ['reason' => '  รูปเบลอ ถ่ายใหม่ให้เห็นมุมทั้ง 4  '])
            ->assertOk()
            ->assertJsonPath('data.state', 'returned_for_retake')
            ->assertJsonPath('data.retake_reason', 'รูปเบลอ ถ่ายใหม่ให้เห็นมุมทั้ง 4')
            ->assertJsonPath('data.student.student_number', 1);
        $this->assertCount(1, $this->sentTo('/studentSubmissions/sub-1:return', 'POST'));

        $pushes = $this->notifier->ofType(PushMessage::RETAKE_REQUESTED);
        $this->assertCount(1, $pushes);
        $this->assertSame([$this->students[1]->id], $pushes[0][0]);
        $this->assertSame('ครูขอให้ส่งรูปการบ้าน เศษส่วน ชุดที่ 3 ใหม่ใน Google Classroom: รูปเบลอ ถ่ายใหม่ให้เห็นมุมทั้ง 4', $pushes[0][1]->body);
        $this->assertSame(['type' => 'retake_requested', 'assignment_id' => (string) $this->assignment->id], $pushes[0][1]->data());

        $this->asUser($this->students[1])->getJson('/api/v1/student/retake-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.assignment.title', 'เศษส่วน ชุดที่ 3')
            ->assertJsonPath('data.0.reason', 'รูปเบลอ ถ่ายใหม่ให้เห็นมุมทั้ง 4')
            ->assertJsonPath('data.0.alternate_link', 'https://classroom.google.com/sub-1');
        $this->asUser($this->students[2])->getJson('/api/v1/student/retake-requests')->assertOk()->assertExactJson(['data' => []]);
        $this->asUser($this->teacher)->getJson('/api/v1/student/retake-requests')->assertForbidden();

        // Once returned it cannot be returned again until the student hands in.
        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$row->id}/return", ['reason' => 'อีกครั้ง'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_submission_not_returnable');
    }

    public function test_the_student_result_shows_an_open_retake_request(): void
    {
        $this->posted();
        $submission = Submission::create(['assignment_id' => $this->assignment->id, 'student_id' => $this->students[1]->id, 'status' => Submission::STATUS_PUBLISHED, 'total_score' => 2, 'published_at' => now()]);
        $this->asUser($this->students[1])->getJson("/api/v1/student/results/{$submission->id}")->assertOk()->assertJsonPath('data.retake_reason', null);

        $this->import('sub-1', ['state' => ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE, 'retake_reason' => 'หน้า 2 หายไป']);
        $this->asUser($this->students[1])->getJson("/api/v1/student/results/{$submission->id}")->assertOk()->assertJsonPath('data.retake_reason', 'หน้า 2 หายไป');
    }

    public function test_returning_work_not_created_by_the_app_is_refused_clearly(): void
    {
        $this->posted();
        $row = $this->import('sub-1');
        $this->fakeGoogle([$this->submissionsUrl().'/sub-1:return' => Http::response(self::googleError(403, 'PERMISSION_DENIED', '@ProjectPermissionDenied The Developer Console project is not permitted to make this request.'), 403)]);

        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$row->id}/return", ['reason' => 'รูปเบลอ'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'project_permission_denied');
        $this->assertSame(ClassroomSubmissionImport::STATE_NEW, $row->refresh()->state);
        $this->assertSame([], $this->notifier->sent);

        $this->asUser($this->teacher)->postJson("/api/v1/google-submissions/{$row->id}/return", [])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->asUser($this->makeTeacher($this->teacher->school))->postJson("/api/v1/google-submissions/{$row->id}/return", ['reason' => 'x'])->assertNotFound();
    }

    public function test_retry_queues_failed_and_never_sent_grades(): void
    {
        Queue::fake();
        $this->posted();
        $publish = fn (int $n) => Submission::create(['assignment_id' => $this->assignment->id, 'student_id' => $this->students[$n]->id, 'status' => Submission::STATUS_PUBLISHED, 'total_score' => 1, 'published_at' => now()]);
        $failed = $publish(1);
        $this->import('sub-1', ['state' => ClassroomSubmissionImport::STATE_GRADE_FAILED, 'last_error' => 'x']);
        $neverSent = $publish(2);   // matched (g-2), no row
        $publish(3);                // not matched: not in Classroom
        Submission::create(['assignment_id' => $this->assignment->id, 'student_id' => $this->makeClassroomStudent(), 'status' => Submission::STATUS_REVIEWED]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-grades/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 2);

        Queue::assertPushed(PushClassroomGradeJob::class, 2);
        Queue::assertPushed(PushClassroomGradeJob::class, fn (PushClassroomGradeJob $job) => $job->submissionId === $failed->id);
        Queue::assertPushed(PushClassroomGradeJob::class, fn (PushClassroomGradeJob $job) => $job->submissionId === $neverSent->id);
    }

    private function makeClassroomStudent(): int
    {
        return $this->enrollStudent($this->classroom, 9, 'นักเรียนคนที่ 9')['student']->id;
    }
}
