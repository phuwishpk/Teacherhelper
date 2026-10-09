<?php

namespace Tests\Feature\Google;

use App\Domain\Google\ClassroomFeedback;
use App\Domain\Google\GoogleScopes;
use App\Domain\Notifications\Notifier;
use App\Jobs\PostClassroomFeedbackJob;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomFeedbackPost;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\GoogleAccount;
use App\Models\Question;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Review\ReviewFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Private announcements of published results (DESIGN §19.7): every publish
 * of an assignment in Classroom (posted by the app or mirrored from the
 * website) sends the student one INDIVIDUAL_STUDENTS announcement with the
 * score, the explanations and the link to the app; delivery rows, retries,
 * the classroom.announcements scope and GET /r/{id}.
 */
class ClassroomFeedbackTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use ReviewFixtures;

    private GoogleAccount $account;

    private AssignmentGoogleLink $posted;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://teacherhelper.example']);
        $this->configureGoogle();
        $this->captureLogs();
        $this->app->instance(Notifier::class, new RecordingNotifier);
        $this->makeReviewWorld(3);
        $this->account = $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        $this->posted = AssignmentGoogleLink::create(['assignment_id' => $this->assignment->id, 'course_work_id' => self::COURSE_WORK_ID, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        foreach ([0 => 'g-1', 1 => 'g-2'] as $i => $googleId) {
            ClassroomStudent::query()->where('student_id', $this->students[$i]->id)->update(['google_user_id' => $googleId]);
        }
    }

    /**
     * Google with the grade push of app courseWork answered (no Classroom
     * submission of the student: the push is skipped).
     *
     * @param  array<string, mixed>  $routes
     */
    private function fake(array $routes = []): void
    {
        $this->fakeGoogle($routes + [
            'classroom.googleapis.com/v1/courses/*/studentSubmissions*' => Http::response(['studentSubmissions' => []]),
        ]);
    }

    private function announcementsUrl(): string
    {
        return 'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/announcements';
    }

    private function reviewedSubmission(int $i): Submission
    {
        $this->answerSheet($this->students[$i]);
        $submission = $this->submission($this->students[$i]);
        $this->reviewAll($submission->load('responses'));

        return $submission;
    }

    private function publish(Submission $submission): void
    {
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();
    }

    /** @return list<Request> */
    private function announcements(): array
    {
        return $this->sentTo('/announcements', 'POST');
    }

    public function test_publishing_sends_the_student_a_private_announcement_with_score_explanations_and_link(): void
    {
        $submission = $this->reviewedSubmission(0);
        $responses = $submission->responses()->with('question')->get()->keyBy(fn ($r) => $r->question->position);
        // The teacher's edited text is what the student reads (explanation holds it; the AI's is kept aside).
        $responses[2]->forceFill(['explanation' => 'ครูอธิบาย: ทำส่วนให้เท่ากันก่อนบวก', 'ai_explanation' => 'ข้อความเดิมของ AI', 'explanation_source' => 'teacher'])->save();
        $responses[3]->forceFill(['explanation' => null])->save();
        $this->fake([
            $this->announcementsUrl() => Http::response(['id' => 'ann-77', 'alternateLink' => 'https://classroom.google.com/a/77']),
        ]);

        $this->publish($submission);

        $sent = $this->announcements();
        $this->assertCount(1, $sent);
        $body = $sent[0]->data();
        $this->assertSame('PUBLISHED', $body['state']);
        $this->assertSame('INDIVIDUAL_STUDENTS', $body['assigneeMode']);
        $this->assertSame(['studentIds' => ['g-1']], $body['individualStudentsOptions']);

        $submission->refresh();
        $max = (float) Question::query()->where('assignment_id', $this->assignment->id)->sum('max_points');
        $text = $body['text'];
        $this->assertStringContainsString('ผลการตรวจ: การบ้านเศษส่วน', $text);
        $this->assertStringContainsString('คะแนน '.self::num((float) $submission->effectiveTotal()).'/'.self::num($max), $text);
        $this->assertStringContainsString('ข้อ 2: ครูอธิบาย: ทำส่วนให้เท่ากันก่อนบวก', $text);
        $this->assertStringContainsString('ข้อ 1: ลองตรวจการคำนวณอีกครั้งนะ', $text);
        $this->assertStringNotContainsString('ข้อ 3:', $text, 'no explanation, no line');
        $this->assertStringNotContainsString('ข้อความเดิมของ AI', $text);
        $this->assertStringContainsString("https://teacherhelper.example/r/{$submission->id}", $text);
        $this->assertLessThan(strpos($text, 'ข้อ 2:'), strpos($text, 'ข้อ 1:'), 'question order');

        $post = ClassroomFeedbackPost::query()->sole();
        $this->assertSame([$submission->id, self::COURSE_ID, 'g-1', 'ann-77', ClassroomFeedbackPost::STATE_POSTED, null],
            [$post->submission_id, $post->course_id, $post->google_user_id, $post->announcement_id, $post->state, $post->last_error]);
        $this->assertTrue($post->published_at->equalTo($submission->published_at));
        $this->assertNotNull($post->posted_at);
        // The grade still goes to the app's own courseWork.
        $this->assertNotEmpty($this->sentTo('/studentSubmissions'));
        $this->assertNoSecretInLogs();
    }

    public function test_the_announcement_uses_the_total_taken_from_classroom(): void
    {
        $submission = $this->reviewedSubmission(0);
        $submission->forceFill(['total_override' => 3.5])->save();
        $this->fake();

        $this->publish($submission);

        $this->assertStringContainsString('คะแนน 3.5/', $this->announcements()[0]['text']);
    }

    public function test_web_coursework_gets_the_announcement_but_no_grade(): void
    {
        $this->posted->forceFill(['origin' => AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB])->save();
        $submission = $this->reviewedSubmission(1);
        $this->fake();

        $this->publish($submission);

        $this->assertCount(0, $this->sentTo('/studentSubmissions'), 'Classroom refuses grades of web courseWork');
        $this->assertCount(1, $this->announcements());
        $this->assertSame(['g-2'], $this->announcements()[0]['individualStudentsOptions']['studentIds']);
        $this->assertSame(ClassroomFeedbackPost::STATE_POSTED, ClassroomFeedbackPost::query()->sole()->state);
    }

    public function test_explanations_are_cut_to_3000_characters(): void
    {
        $submission = $this->reviewedSubmission(0);
        foreach ($submission->responses as $response) {
            $response->forceFill(['explanation' => str_repeat('ก', 1200)])->save();
        }
        $this->fake();

        $this->publish($submission);

        $text = $this->announcements()[0]['text'];
        $part = explode("\n\nดูผลละเอียดในแอป", explode("คำอธิบายรายข้อ\n", $text)[1])[0];
        $this->assertSame(ClassroomFeedback::MAX_EXPLANATION_CHARS, mb_strlen($part));
        $this->assertStringEndsWith('…', $part);
        $this->assertStringContainsString('/r/'.$submission->id, $text);
    }

    public function test_a_score_only_assignment_gets_no_explanations_section(): void
    {
        $this->assignment->forceFill(['score_only' => true])->save();
        $submission = $this->reviewedSubmission(0);
        foreach ($submission->responses as $response) {
            $response->forceFill(['explanation' => 'ข้อนี้ยังได้คะแนนไม่เต็ม'])->save();
        }
        $this->fake();

        $this->publish($submission);

        $text = $this->announcements()[0]['text'];
        $this->assertStringContainsString('คะแนน ', $text);
        $this->assertStringNotContainsString('คำอธิบายรายข้อ', $text);
        $this->assertStringNotContainsString('ข้อ 1:', $text);
        $this->assertStringContainsString('/r/'.$submission->id, $text);
    }

    public function test_nothing_is_sent_for_an_unmatched_student_or_an_assignment_not_in_classroom(): void
    {
        $unmatched = $this->reviewedSubmission(2);
        $this->fake();

        $this->publish($unmatched);

        $this->assertCount(0, $this->announcements());
        $this->assertSame(0, ClassroomFeedbackPost::query()->count());

        $this->posted->delete();
        $matched = $this->reviewedSubmission(0);
        $this->publish($matched);
        $this->assertCount(0, $this->announcements());
        $this->assertSame(0, ClassroomFeedbackPost::query()->count());
    }

    public function test_an_account_without_the_announcements_scope_needs_reconnect(): void
    {
        $scopes = array_values(array_diff(GoogleScopes::REQUIRED, [GoogleScopes::ANNOUNCEMENTS]));
        $this->account->forceFill(['scopes' => implode(' ', $scopes)])->save();
        $this->fake();

        $status = $this->asUser($this->teacher)->getJson('/api/v1/google/status')->assertOk();
        $status->assertJsonPath('data.needs_reconnect', true)
            ->assertJsonPath('data.reconnect_message', 'ต้องเชื่อมบัญชี Google ใหม่ เพื่อให้แอปส่งผลตรวจเป็นประกาศส่วนตัวถึงนักเรียนได้');
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.needs_reconnect', true);
        // Calls that need the account say why.
        $this->asUser($this->teacher)->getJson('/api/v1/google/courses')
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_reconnect_required')
            ->assertJsonPath('message', GoogleAccount::ANNOUNCEMENTS_RECONNECT_MESSAGE);

        $submission = $this->reviewedSubmission(0);
        $this->publish($submission);

        $this->assertCount(0, $this->announcements());
        $post = ClassroomFeedbackPost::query()->sole();
        $this->assertSame([ClassroomFeedbackPost::STATE_FAILED, GoogleAccount::ANNOUNCEMENTS_RECONNECT_MESSAGE], [$post->state, $post->last_error]);
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.feedback_failed', 1);
    }

    public function test_the_migration_marks_accounts_connected_before_the_scope(): void
    {
        $scopes = array_values(array_diff(GoogleScopes::REQUIRED, [GoogleScopes::ANNOUNCEMENTS]));
        $this->account->forceFill(['scopes' => implode(' ', $scopes)])->save();
        $other = $this->connectGoogle($this->makeTeacher());
        $expired = $this->connectGoogle($this->makeTeacher(), ['scopes' => '', 'last_error' => GoogleAccount::ERROR_INVALID_GRANT]);

        $migration = require database_path('migrations/2026_09_30_000004_add_classroom_feedback_posts.php');
        $this->assertSame(1, $migration->markAccountsWithoutAnnouncementsScope());

        $this->assertSame(GoogleAccount::ERROR_SCOPE_MISSING, $this->account->refresh()->last_error);
        $this->assertNull($other->refresh()->last_error);
        $this->assertSame(GoogleAccount::ERROR_INVALID_GRANT, $expired->refresh()->last_error);
    }

    public function test_a_connect_without_the_announcements_scope_is_refused(): void
    {
        $this->account->delete();
        $scopes = array_values(array_diff(GoogleScopes::REQUIRED, [GoogleScopes::ANNOUNCEMENTS]));
        $this->fake(['oauth2.googleapis.com/token' => Http::response(self::tokenBody($scopes))]);

        $this->asUser($this->teacher)->postJson('/api/v1/google/connect', ['server_auth_code' => '4/0AQlEd8x-one-time-code'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_scope_missing')
            ->assertJsonPath('errors.scopes', [GoogleScopes::ANNOUNCEMENTS]);
    }

    public function test_google_refusing_the_scope_marks_the_account_and_fails_the_row(): void
    {
        $submission = $this->reviewedSubmission(0);
        $this->fake([
            $this->announcementsUrl() => Http::response(self::googleError(403, 'PERMISSION_DENIED', 'Request had insufficient authentication scopes.', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'), 403),
        ]);

        $this->publish($submission);

        $post = ClassroomFeedbackPost::query()->sole();
        $this->assertSame([ClassroomFeedbackPost::STATE_FAILED, GoogleAccount::ANNOUNCEMENTS_RECONNECT_MESSAGE], [$post->state, $post->last_error]);
        $this->assertSame(GoogleAccount::ERROR_SCOPE_MISSING, $this->account->refresh()->last_error);
    }

    public function test_an_unreachable_google_is_retried_by_the_queue_then_fails(): void
    {
        Queue::fake();
        $submission = $this->reviewedSubmission(0);
        $this->publish($submission);
        $post = ClassroomFeedbackPost::query()->sole();
        Queue::assertPushed(PostClassroomFeedbackJob::class, fn (PostClassroomFeedbackJob $job) => $job->postId === $post->id);
        $this->fake([$this->announcementsUrl() => Http::response(self::googleError(503, 'UNAVAILABLE', 'Try again'), 503)]);

        $first = (new PostClassroomFeedbackJob($post->id))->withFakeQueueInteractions();
        $first->handle(app(ClassroomFeedback::class));
        $first->assertReleased(60);
        $this->assertSame(ClassroomFeedbackPost::STATE_QUEUED, $post->refresh()->state);

        $last = (new PostClassroomFeedbackJob($post->id))->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle(app(ClassroomFeedback::class));
        $last->assertNotReleased();
        $post->refresh();
        $this->assertSame(ClassroomFeedbackPost::STATE_FAILED, $post->state);
        $this->assertStringContainsString('ติดต่อ Google ไม่ได้', (string) $post->last_error);
    }

    public function test_the_teacher_sees_and_retries_failed_announcements(): void
    {
        $submission = $this->reviewedSubmission(0);
        $this->fake([$this->announcementsUrl() => Http::sequence()
            ->push(self::googleError(400, 'INVALID_ARGUMENT', 'bad'), 400)
            ->push(['id' => 'ann-2'])]);
        $this->publish($submission);
        $post = ClassroomFeedbackPost::query()->sole();
        $this->assertSame(ClassroomFeedbackPost::STATE_FAILED, $post->state);
        $this->assertStringContainsString('bad', (string) $post->last_error);

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-feedback")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.submission_id', $submission->id)
            ->assertJsonPath('data.0.student.id', $this->students[0]->id)
            ->assertJsonPath('data.0.student.student_number', 1)
            ->assertJsonPath('data.0.state', 'failed');
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.feedback_failed', 1);

        // The student was matched to a new account since: the retry uses the current match.
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => 'g-1b']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-feedback/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 1);

        $this->assertCount(2, $this->announcements());
        $this->assertSame(['g-1b'], $this->announcements()[1]['individualStudentsOptions']['studentIds']);
        $post->refresh();
        $this->assertSame([ClassroomFeedbackPost::STATE_POSTED, 'g-1b', null, 'ann-2'], [$post->state, $post->google_user_id, $post->last_error, $post->announcement_id]);
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.feedback_failed', 0);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-feedback/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);
    }

    public function test_a_retry_without_a_match_keeps_the_row_failed(): void
    {
        $submission = $this->reviewedSubmission(0);
        $this->fake([$this->announcementsUrl() => Http::response(self::googleError(404, 'NOT_FOUND', 'gone'), 404)]);
        $this->publish($submission);
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => null]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-feedback/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);

        $post = ClassroomFeedbackPost::query()->sole();
        $this->assertSame(ClassroomFeedbackPost::STATE_FAILED, $post->state);
        $this->assertStringContainsString('ยังไม่ได้จับคู่', (string) $post->last_error);
    }

    public function test_a_retry_after_the_work_was_reopened_drops_the_stale_row(): void
    {
        $submission = $this->reviewedSubmission(0);
        $this->fake([$this->announcementsUrl() => Http::response(self::googleError(404, 'NOT_FOUND', 'gone'), 404)]);
        $this->publish($submission);
        $this->assertSame(ClassroomFeedbackPost::STATE_FAILED, ClassroomFeedbackPost::query()->sole()->state);
        Submission::query()->whereKey($submission->id)->update(['status' => Submission::STATUS_NEEDS_REVIEW]);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/google-feedback/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', 0);
        $this->assertSame(0, ClassroomFeedbackPost::query()->count());
    }

    public function test_feedback_routes_are_the_teachers_own(): void
    {
        $other = $this->makeTeacher();
        $this->asUser($other)->getJson("/api/v1/assignments/{$this->assignment->id}/google-feedback")->assertNotFound();
        $this->asUser($other)->postJson("/api/v1/assignments/{$this->assignment->id}/google-feedback/retry")->assertNotFound();
    }

    public function test_a_student_rematched_before_the_job_runs_is_not_sent_the_result(): void
    {
        Queue::fake();
        $submission = $this->reviewedSubmission(0);
        $this->publish($submission);
        $post = ClassroomFeedbackPost::query()->sole();
        // The roster changed: g-1 is now someone else's account.
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => null]);
        ClassroomStudent::query()->where('student_id', $this->students[2]->id)->update(['google_user_id' => 'g-1']);
        $this->fake();

        $this->assertSame(ClassroomFeedback::FAILED, app(ClassroomFeedback::class)->post($post->id));

        $this->assertCount(0, $this->announcements());
        $this->assertStringContainsString('ไม่ได้จับคู่กับบัญชี Google เดิม', (string) $post->refresh()->last_error);
    }

    public function test_each_publish_gets_its_own_row_and_a_stale_one_is_dropped(): void
    {
        Queue::fake();
        $submission = $this->reviewedSubmission(0);
        $this->publish($submission);
        $first = ClassroomFeedbackPost::query()->sole();

        // Reopened and published again a minute later.
        $this->travel(1)->minutes();
        $submission->refresh()->forceFill(['status' => Submission::STATUS_REVIEWED, 'published_at' => null])->save();
        $this->publish($submission);
        $this->assertSame(2, ClassroomFeedbackPost::query()->count());
        $second = ClassroomFeedbackPost::query()->latest('id')->firstOrFail();
        $this->fake();

        $this->assertSame(ClassroomFeedback::SKIPPED, app(ClassroomFeedback::class)->post($first->id));
        $this->assertNull(ClassroomFeedbackPost::query()->find($first->id), 'the older publish was never sent');
        $this->assertSame(ClassroomFeedback::POSTED, app(ClassroomFeedback::class)->post($second->id));
        $this->assertSame(ClassroomFeedback::SKIPPED, app(ClassroomFeedback::class)->post($second->id), 'posted once');
        $this->assertCount(1, $this->announcements());
    }

    public function test_the_result_link_page_opens_the_app_and_shows_no_student_data(): void
    {
        $submission = $this->reviewedSubmission(0);

        $page = $this->get("/r/{$submission->id}")->assertOk();

        $page->assertSee('เปิดผลในแอป Krucheck');
        $page->assertSee("intent://r/{$submission->id}#Intent;scheme=eduvision;package=com.eduvision.app;end", false);
        $page->assertDontSee('นักเรียนคนที่');
        $page->assertDontSee('การบ้านเศษส่วน');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString("default-src 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $this->get('/r/abc')->assertNotFound();
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
    }
}
