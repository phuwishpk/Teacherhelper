<?php

namespace Tests\Feature\Google;

use App\Domain\Google\ClassroomGradePusher;
use App\Domain\Notifications\Notifier;
use App\Events\AppealResolved;
use App\Events\SubmissionPublished;
use App\Jobs\PushClassroomGradeJob;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Review\ReviewFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Publishing sends the total back to Google Classroom (DESIGN §18.2, §18.6):
 * SubmissionPublished -> PushClassroomGradeJob -> studentSubmissions.patch
 * (updateMask=assignedGrade) + return; failures end as grade_failed.
 */
class GradePushTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use ReviewFixtures;

    private GoogleAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->captureLogs();
        $this->app->instance(Notifier::class, new RecordingNotifier);
        $this->makeReviewWorld(3);
        $this->account = $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        AssignmentGoogleLink::create(['assignment_id' => $this->assignment->id, 'course_work_id' => self::COURSE_WORK_ID, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        foreach ([0 => 'g-1', 1 => 'g-2'] as $i => $googleId) {
            ClassroomStudent::query()->where('student_id', $this->students[$i]->id)->update(['google_user_id' => $googleId]);
        }
    }

    private function submissionsUrl(): string
    {
        return 'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork/'.self::COURSE_WORK_ID.'/studentSubmissions';
    }

    private function reviewedSubmission(int $i): Submission
    {
        $this->answerSheet($this->students[$i]);
        $submission = $this->submission($this->students[$i]);
        $this->reviewAll($submission->load('responses'));

        return $submission;
    }

    private function importFor(int $i, string $id, string $state = ClassroomSubmissionImport::STATE_IMPORTED): ClassroomSubmissionImport
    {
        return ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignment->id,
            'google_submission_id' => $id,
            'google_user_id' => 'g-'.($i + 1),
            'student_id' => $this->students[$i]->id,
            'state' => $state,
            'attachments' => [['drive_file_id' => 'f', 'title' => 'a.jpg', 'mime_type' => 'image/jpeg']],
            'google_update_time' => 'T1',
        ]);
    }

    public function test_publishing_sets_the_assigned_grade_and_returns_the_work(): void
    {
        $submission = $this->reviewedSubmission(0);
        $import = $this->importFor(0, 'sub-1');
        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-1:return' => Http::response([]),
            $this->submissionsUrl().'/sub-1*' => Http::response(['id' => 'sub-1', 'state' => 'TURNED_IN', 'assignedGrade' => 5, 'updateTime' => 'T2']),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $patch = $this->sentTo('/studentSubmissions/sub-1?', 'PATCH');
        $this->assertCount(1, $patch);
        $this->assertStringContainsString('updateMask=assignedGrade', $patch[0]->url());
        $this->assertEquals(5.0, $patch[0]['assignedGrade']);
        $this->assertCount(1, $this->sentTo('/studentSubmissions/sub-1:return', 'POST'));

        $import->refresh();
        $this->assertSame([ClassroomSubmissionImport::STATE_GRADED, 'T2', null], [$import->state, $import->google_update_time, $import->last_error]);
        $this->assertNotNull($import->grade_pushed_at);
        $this->assertNoSecretInLogs();
    }

    public function test_work_handed_in_on_paper_is_found_by_the_matched_account(): void
    {
        $submission = $this->reviewedSubmission(1);
        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-2:return' => Http::response(self::googleError(400, 'FAILED_PRECONDITION', 'Precondition check failed.'), 400),
            $this->submissionsUrl().'/sub-2*' => Http::response(['id' => 'sub-2', 'state' => 'CREATED', 'updateTime' => 'T2']),
            $this->submissionsUrl().'*' => Http::response(['studentSubmissions' => [['id' => 'sub-2', 'userId' => 'g-2', 'state' => 'CREATED', 'updateTime' => 'T1', 'alternateLink' => 'https://classroom.google.com/sub-2']]]),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $this->assertStringContainsString('userId=g-2', $this->sentTo('/studentSubmissions?')[0]->url());
        $row = ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-2')->firstOrFail();
        $this->assertSame([$this->students[1]->id, ClassroomSubmissionImport::STATE_GRADED, []], [$row->student_id, $row->state, $row->attachments]);
        $this->assertStringContainsString('ส่งคืนงานไม่ได้', (string) $row->last_error);
    }

    public function test_a_sheet_inside_a_classmates_hand_in_grades_only_the_students_own_submission(): void
    {
        // Student 2 (g-2) handed in a photo of student 1's sheet: the ingestor
        // filed the scan under student 1 (identity_mismatch) and left the import
        // row with student 2. Publishing student 1 must not grade sub-2.
        $submission = $this->reviewedSubmission(0);
        $classmates = $this->importFor(1, 'sub-2');
        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-1:return' => Http::response([]),
            $this->submissionsUrl().'/sub-1*' => Http::response(['id' => 'sub-1', 'state' => 'TURNED_IN', 'assignedGrade' => 5, 'updateTime' => 'T2']),
            $this->submissionsUrl().'*' => Http::response(['studentSubmissions' => [['id' => 'sub-1', 'userId' => 'g-1', 'state' => 'TURNED_IN', 'updateTime' => 'T1']]]),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $this->assertStringContainsString('userId=g-1', $this->sentTo('/studentSubmissions?')[0]->url());
        $this->assertCount(1, $this->sentTo('/studentSubmissions/sub-1?', 'PATCH'));
        $this->assertCount(0, $this->sentTo('/sub-2'), 'the classmate\'s submission is untouched');
        $classmates->refresh();
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, $this->students[1]->id, null], [$classmates->state, $classmates->student_id, $classmates->grade_pushed_at]);
        $this->assertSame(ClassroomSubmissionImport::STATE_GRADED, ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-1')->value('state'));
    }

    public function test_a_row_handed_in_by_another_account_is_never_graded(): void
    {
        // A row filed under student 1 that Google says was handed in by g-2
        // (a pre-fix identity_mismatch scan, or a roster re-matched since).
        $submission = $this->reviewedSubmission(0);
        $import = ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignment->id,
            'google_submission_id' => 'sub-2',
            'google_user_id' => 'g-2',
            'student_id' => $this->students[0]->id,
            'state' => ClassroomSubmissionImport::STATE_IMPORTED,
            'attachments' => [],
            'google_update_time' => 'T1',
        ]);
        $this->fakeGoogle();

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        Http::assertNothingSent();
        $import->refresh();
        $this->assertSame(ClassroomSubmissionImport::STATE_GRADE_FAILED, $import->state);
        $this->assertStringContainsString('identity_mismatch', (string) $import->last_error);
        $this->assertNull($import->grade_pushed_at);
        $this->assertNoSecretInLogs();
    }

    public function test_a_row_of_an_unmatched_student_waits_for_the_roster_match(): void
    {
        // Student 3 is not matched; a scan of their QR arrived inside g-3's
        // hand-in and was filed under them. No grade until the teacher confirms g-3 is student 3.
        $submission = $this->reviewedSubmission(2);
        $import = $this->importFor(2, 'sub-3');
        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-3:return' => Http::response([]),
            $this->submissionsUrl().'/sub-3*' => Http::response(['id' => 'sub-3', 'state' => 'TURNED_IN', 'updateTime' => 'T2']),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        Http::assertNothingSent();
        $import->refresh();
        $this->assertSame(ClassroomSubmissionImport::STATE_GRADE_FAILED, $import->state);
        $this->assertStringContainsString('จับคู่', (string) $import->last_error);

        // Matched: the retry sends the grade to that very submission.
        ClassroomStudent::query()->where('student_id', $this->students[2]->id)->update(['google_user_id' => 'g-3']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-grades/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);
        $this->assertCount(1, $this->sentTo('/studentSubmissions/sub-3?', 'PATCH'));
        $this->assertSame([ClassroomSubmissionImport::STATE_GRADED, null], [$import->refresh()->state, $import->last_error]);
    }

    public function test_nothing_is_sent_for_an_unmatched_student_or_an_assignment_not_posted(): void
    {
        $this->fakeGoogle();
        $submission = $this->reviewedSubmission(2);
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();
        $this->assertCount(0, $this->sentTo('googleapis.com'));

        Queue::fake();
        AssignmentGoogleLink::query()->delete();
        SubmissionPublished::dispatch($submission->id, $this->assignment->id, $this->students[2]->id, $this->teacher->id);
        Queue::assertNotPushed(PushClassroomGradeJob::class);
    }

    public function test_work_not_created_by_the_app_ends_as_grade_failed(): void
    {
        $submission = $this->reviewedSubmission(0);
        $import = $this->importFor(0, 'sub-1');
        $this->fakeGoogle([$this->submissionsUrl().'/sub-1*' => Http::response(self::googleError(403, 'PERMISSION_DENIED', '@ProjectPermissionDenied The Developer Console project is not permitted to make this request.'), 403)]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $import->refresh();
        $this->assertSame(ClassroomSubmissionImport::STATE_GRADE_FAILED, $import->state);
        $this->assertSame('งานนี้ไม่ได้สร้างจากแอป จึงส่งคะแนนกลับไม่ได้', $import->last_error);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions");
    }

    public function test_an_expired_grant_marks_the_account_and_the_row(): void
    {
        $submission = $this->reviewedSubmission(0);
        $import = $this->importFor(0, 'sub-1');
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $this->assertSame(ClassroomSubmissionImport::STATE_GRADE_FAILED, $import->refresh()->state);
        $this->assertStringContainsString('เชื่อมบัญชี Google ใหม่', (string) $import->last_error);
        $this->assertTrue($this->account->refresh()->needsReconnect());
        $this->assertNoSecretInLogs();
    }

    public function test_google_outages_are_retried_then_recorded(): void
    {
        $submission = $this->reviewedSubmission(0);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 5])->save();
        $import = $this->importFor(0, 'sub-1');
        $this->fakeGoogle([$this->submissionsUrl().'/sub-1*' => Http::response('unavailable', 503)]);

        $job = (new PushClassroomGradeJob($submission->id))->withFakeQueueInteractions();
        $job->handle(app(ClassroomGradePusher::class));
        $job->assertReleased(60);
        $this->assertSame(ClassroomSubmissionImport::STATE_IMPORTED, $import->refresh()->state);

        $job = (new PushClassroomGradeJob($submission->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle(app(ClassroomGradePusher::class));
        $job->assertNotReleased();
        $import->refresh();
        $this->assertSame([ClassroomSubmissionImport::STATE_GRADE_FAILED, 'ติดต่อ Google ไม่ได้ ลองส่งคะแนนอีกครั้งภายหลัง'], [$import->state, $import->last_error]);

        // The job carries the submission id only.
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, serialize(new PushClassroomGradeJob($submission->id)));
    }

    public function test_the_retry_endpoint_pushes_a_failed_grade_again(): void
    {
        $submission = $this->reviewedSubmission(0);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 4.5])->save();
        $import = $this->importFor(0, 'sub-1', ClassroomSubmissionImport::STATE_GRADE_FAILED);
        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-1:return' => Http::response([]),
            $this->submissionsUrl().'/sub-1*' => Http::response(['id' => 'sub-1', 'state' => 'RETURNED', 'updateTime' => 'T3']),
        ]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-grades/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        $this->assertSame(ClassroomSubmissionImport::STATE_GRADED, $import->refresh()->state);
        $this->assertEquals(4.5, $this->sentTo('/studentSubmissions/sub-1?', 'PATCH')[0]['assignedGrade']);
        $this->assertCount(0, $this->sentTo(':return'), 'already RETURNED: only the grade changes');
    }

    public function test_an_accepted_appeal_that_changes_the_score_pushes_the_new_total(): void
    {
        Queue::fake();
        $submission = $this->reviewedSubmission(0);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 5])->save();
        $responseId = $submission->responses()->value('id');

        AppealResolved::dispatch(1, $responseId, $this->students[0]->id, false);
        Queue::assertNotPushed(PushClassroomGradeJob::class);

        AppealResolved::dispatch(1, $responseId, $this->students[0]->id, true);
        Queue::assertPushed(PushClassroomGradeJob::class, fn (PushClassroomGradeJob $job) => $job->submissionId === $submission->id);
        Queue::assertPushedOn('default', PushClassroomGradeJob::class);
    }

    public function test_a_disconnected_poster_ends_as_grade_failed_without_calling_google(): void
    {
        $submission = $this->reviewedSubmission(0);
        $import = $this->importFor(0, 'sub-1');
        $this->account->delete();
        $this->fakeGoogle();

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $this->assertSame(ClassroomSubmissionImport::STATE_GRADE_FAILED, $import->refresh()->state);
        $this->assertCount(0, $this->sentTo('googleapis.com'));
        Http::assertNothingSent();
    }
}
