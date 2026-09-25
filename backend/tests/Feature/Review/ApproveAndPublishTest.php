<?php

namespace Tests\Feature\Review;

use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Events\SubmissionPublished;
use App\Models\Appeal;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * POST /assignments/{id}/approve-confident, /submissions/{id}/publish and
 * /assignments/{id}/publish (DESIGN §9.5, §13).
 */
class ApproveAndPublishTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(3);
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);
    }

    public function test_bulk_approval_takes_only_confident_unflagged_unreviewed_answers(): void
    {
        [$s1, $s2, $s3] = $this->students;
        $ok1 = $this->confidentAnswer($s1, 'q1');
        $ok2 = $this->confidentAnswer($s2, 'q1', ['ai_error_types' => ['careless']]);
        $look = $this->answer($s1, 'q2');
        $manual = $this->manualAnswer($s1, 'q3');
        $suspicious = $this->confidentAnswer($s1, 'q4', ['fuzzy_trace' => ['system' => 'mcq', 'suspicious_instruction' => true]]);
        $identity = $this->confidentAnswer($s2, 'q2', ['fuzzy_trace' => ['identity_mismatch' => true]]);
        $reviewed = $this->confidentAnswer($s2, 'q3', ['final_score' => 3, 'final_understanding' => 'partial', 'reviewed_at' => now(), 'reviewed_by' => $this->teacher->id]);
        $appealed = $this->confidentAnswer($s2, 'q4');
        Appeal::create(['response_id' => $appealed->id, 'student_id' => $s2->id]);
        $published = $this->confidentAnswer($s3, 'q1');
        $this->submission($s3)->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")
            ->assertOk()
            ->assertJsonPath('data.approved', 2);

        foreach ([$ok1, $ok2] as $r) {
            $r->refresh();
            $this->assertSame([$r->ai_score, $r->ai_understanding, $r->ai_error_types, $this->teacher->id], [$r->final_score, $r->final_understanding, $r->final_error_types, $r->reviewed_by]);
            $this->assertNotNull($r->reviewed_at);
        }
        foreach ([$look, $manual, $suspicious, $identity, $appealed, $published] as $r) {
            $this->assertNull($r->refresh()->reviewed_at, "response {$r->id} must not be approved in bulk");
        }
        $this->assertSame(3.0, $reviewed->refresh()->final_score, 'already reviewed: the teacher value stays');

        $events = ScoreEvent::query()->orderBy('id')->get();
        $this->assertSame(['bulk_approve', 'bulk_approve'], $events->pluck('action')->all());
        $this->assertSame([$ok1->id, $ok2->id], $events->pluck('response_id')->all());
        $this->assertSame([2.0, 2.0], [$events[0]->old_score, $events[0]->new_score]);

        // Nothing left: 0.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")->assertJsonPath('data.approved', 0);
    }

    public function test_bulk_approval_completes_a_submission(): void
    {
        $this->confidentAnswer($this->students[0], 'q1');
        $this->confidentAnswer($this->students[0], 'q2');

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")->assertJsonPath('data.approved', 2);

        $this->assertSame('reviewed', $this->submission($this->students[0])->refresh()->status);
    }

    public function test_a_submission_is_published_only_when_every_answer_is_reviewed(): void
    {
        $s1 = $this->students[0];
        $this->answerSheet($s1);
        $submission = $this->submission($s1);
        $url = "/api/v1/submissions/{$submission->id}/publish";

        $this->asUser($this->teacher)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('code', 'submission_not_reviewed')
            ->assertJsonPath('message', 'ยังตรวจทานไม่ครบ เหลืออีก 4 ข้อ')
            ->assertJsonPath('errors.unreviewed', ['4'])
            ->assertJsonMissingPath('errors.missing_pages');
        $this->asUser($s1)->getJson('/api/v1/student/results')->assertOk()->assertJsonCount(0, 'data');

        $this->reviewAll($submission->fresh());
        Response::query()->where('question_id', $this->q['q3']->id)->update(['final_score' => 4]);

        // q1 1 + q2 1 + q3 4 (teacher) + q4 0.5
        $res = $this->asUser($this->teacher)->postJson($url)->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.total_score', 6.5)
            ->assertJsonPath('data.published_by', $this->teacher->id);
        $this->assertNotNull($res->json('data.published_at'));

        $sent = $this->notifier->ofType(PushMessage::RESULTS_PUBLISHED);
        $this->assertCount(1, $sent);
        $this->assertSame([$s1->id], $sent[0][0]);
        $this->assertSame('ผลการบ้าน การบ้านเศษส่วน ออกแล้ว', $sent[0][1]->body);
        $this->assertSame(['type' => 'results_published', 'submission_id' => (string) $submission->id, 'assignment_id' => (string) $this->assignment->id], $sent[0][1]->data());

        // Again: nothing changes, nobody is told twice.
        $this->asUser($this->teacher)->postJson($url)->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertCount(1, $this->notifier->ofType(PushMessage::RESULTS_PUBLISHED));

        $this->asUser($s1)->getJson('/api/v1/student/results')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_sheet_with_a_page_not_scanned_yet_is_not_published(): void
    {
        Event::fake([SubmissionPublished::class]);
        [$s1, $s2] = $this->students;
        // s1: page 1 scanned and fully reviewed, page 2 (q3, q4) never scanned.
        $this->answer($s1, 'q1');
        $this->answer($s1, 'q2');
        $partial = $this->submission($s1);
        $this->reviewAll($partial);
        // s2: the whole sheet, reviewed.
        $this->answerSheet($s2);
        $this->reviewAll($this->submission($s2));

        $queue = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")->assertOk();
        $queue->assertJsonPath('meta.submissions.0.id', $partial->id)
            ->assertJsonPath('meta.submissions.0.response_count', 2)
            ->assertJsonPath('meta.submissions.0.reviewed_count', 2)
            ->assertJsonPath('meta.submissions.0.question_count', 4)
            ->assertJsonPath('meta.submissions.0.missing_pages', [2])
            ->assertJsonPath('meta.submissions.0.publishable', false)
            ->assertJsonPath('meta.submissions.1.missing_pages', [])
            ->assertJsonPath('meta.submissions.1.publishable', true);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$partial->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('code', 'submission_not_reviewed')
            ->assertJsonPath('message', 'ยังไม่ได้สแกนหน้า 2 (ข้อ 3, 4)')
            ->assertJsonPath('errors.missing_pages', ['2'])
            ->assertJsonPath('errors.missing_questions', [(string) $this->q['q3']->position, (string) $this->q['q4']->position])
            ->assertJsonPath('errors.unreviewed', ['0']);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")
            ->assertOk()
            ->assertExactJson(['data' => ['published' => 1, 'already_published' => 0, 'skipped' => 1]]);

        $this->assertSame('reviewed', $partial->refresh()->status);
        $this->assertNull($partial->published_at);
        $this->assertNull($partial->total_score);
        Event::assertDispatched(SubmissionPublished::class, 1);
        Event::assertDispatched(SubmissionPublished::class, fn (SubmissionPublished $e) => $e->studentId === $s2->id);
        $this->asUser($s1)->getJson('/api/v1/student/results')->assertOk()->assertJsonCount(0, 'data');

        // Missing and unreviewed together: both are named.
        $this->answer($s1, 'q3');
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$partial->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('message', 'ยังไม่ได้สแกนหน้า 2 (ข้อ 4) และยังตรวจทานไม่ครบ เหลืออีก 1 ข้อ');
    }

    public function test_a_question_deleted_after_printing_is_not_expected(): void
    {
        $s1 = $this->students[0];
        $this->answer($s1, 'q1');
        $this->answer($s1, 'q2');
        $this->answer($s1, 'q3');
        $submission = $this->submission($s1);
        $this->reviewAll($submission);
        $this->q['q4']->delete();

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_a_submission_without_answers_is_not_published(): void
    {
        $submission = $this->submission($this->students[0], Submission::STATUS_AWAITING_SCAN);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")
            ->assertStatus(409)
            ->assertJsonPath('code', 'submission_not_reviewed');
    }

    public function test_publishing_the_assignment_publishes_every_reviewed_submission(): void
    {
        Event::fake([SubmissionPublished::class]);
        [$s1, $s2, $s3] = $this->students;
        $this->answerSheet($s1);
        $this->answerSheet($s2);
        $this->answer($s3, 'q1');
        $this->reviewAll($this->submission($s1));
        $this->reviewAll($this->submission($s3));
        $this->submission($s3)->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")
            ->assertOk()
            ->assertExactJson(['data' => ['published' => 1, 'already_published' => 1, 'skipped' => 1]]);

        $this->assertSame('published', $this->submission($s1)->refresh()->status);
        $this->assertSame('needs_review', $this->submission($s2)->refresh()->status);
        Event::assertDispatched(SubmissionPublished::class, 1);
        Event::assertDispatched(SubmissionPublished::class, fn (SubmissionPublished $e) => $e->studentId === $s1->id && $e->publishedBy === $this->teacher->id);
    }

    public function test_only_the_classroom_teacher_publishes_or_approves(): void
    {
        $this->confidentAnswer($this->students[0], 'q1');
        $submission = $this->submission($this->students[0]);
        $this->reviewAll($submission);
        $colleague = $this->makeTeacher($this->teacher->school);

        $this->asUser($colleague)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertForbidden();
        $this->asUser($this->makeTeacher())->postJson("/api/v1/submissions/{$submission->id}/publish")->assertNotFound();
        $this->asUser($colleague)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")->assertNotFound();
        $this->asUser($colleague)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")->assertNotFound();
        $this->asUser($this->students[0])->postJson("/api/v1/submissions/{$submission->id}/publish")->assertForbidden();

        $this->assertSame('reviewed', $submission->refresh()->status);
    }
}
