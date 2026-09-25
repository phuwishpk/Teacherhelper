<?php

namespace Tests\Feature\Review;

use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Events\AppealResolved;
use App\Models\Appeal;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Appeals (DESIGN §9.5, §9.7, §13): once per published answer, answered by
 * the classroom teacher, logged to score_events, pushed to both sides.
 */
class AppealsTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private RecordingNotifier $notifier;

    private Response $work;

    private Response $short;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(2);
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);

        $student = $this->students[0];
        $this->work = $this->answer($student, 'q3');   // 2.5 / 5
        $this->short = $this->answer($student, 'q1');  // 1 / 2
        $submission = $this->submission($student);
        $this->reviewAll($submission);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'published_by' => $this->teacher->id, 'total_score' => 3.5])->save();
    }

    private function appealUrl(Response $response): string
    {
        return "/api/v1/student/responses/{$response->id}/appeal";
    }

    private function openAppeal(?Response $response = null, ?string $reason = 'ขั้นที่ 3 ถูกแล้วค่ะ'): Appeal
    {
        $id = $this->asUser($this->students[0])->postJson($this->appealUrl($response ?? $this->work), ['reason' => $reason])
            ->assertCreated()
            ->json('data.id');

        return Appeal::query()->findOrFail($id);
    }

    public function test_a_student_appeals_a_published_answer_once(): void
    {
        $this->asUser($this->students[0])->postJson($this->appealUrl($this->work), ['reason' => '  ขั้นที่ 3 ถูกแล้วค่ะ '])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reason', 'ขั้นที่ 3 ถูกแล้วค่ะ')
            ->assertJsonPath('data.response_id', $this->work->id);

        $this->asUser($this->students[0])->postJson($this->appealUrl($this->work), [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'appeal_exists');
        $this->assertSame(1, Appeal::query()->count());

        $sent = $this->notifier->ofType(PushMessage::APPEAL_OPENED);
        $this->assertCount(1, $sent);
        $this->assertSame([[$this->teacher->id], 'มีคำขอให้ตรวจใหม่ 1 รายการ'], [$sent[0][0], $sent[0][1]->body]);

        // A second answer: the count grows.
        $this->asUser($this->students[0])->postJson($this->appealUrl($this->short))->assertCreated()->assertJsonPath('data.reason', null);
        $this->assertSame('มีคำขอให้ตรวจใหม่ 2 รายการ', $this->notifier->ofType(PushMessage::APPEAL_OPENED)[1][1]->body);
    }

    public function test_no_appeal_on_unpublished_or_someone_elses_answers(): void
    {
        $other = $this->answer($this->students[1], 'q1');
        $this->reviewAll($this->submission($this->students[1]));

        $this->asUser($this->students[1])->postJson($this->appealUrl($other))->assertNotFound();      // not published
        $this->asUser($this->students[1])->postJson($this->appealUrl($this->work))->assertNotFound(); // not theirs
        $this->asUser($this->teacher)->postJson($this->appealUrl($this->work))->assertForbidden();
        $this->asUser($this->students[0])->postJson($this->appealUrl($this->work), ['reason' => str_repeat('ก', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(0, Appeal::query()->count());
    }

    public function test_the_teacher_lists_appeals_with_their_context(): void
    {
        $appeal = $this->openAppeal();
        $resolved = $this->openAppeal($this->short);
        $resolved->forceFill(['status' => Appeal::STATUS_REJECTED, 'resolved_at' => now()])->save();

        $res = $this->asUser($this->teacher)->getJson('/api/v1/appeals?status=open')->assertOk();
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appeal->id)
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.reason', 'ขั้นที่ 3 ถูกแล้วค่ะ')
            ->assertJsonPath('data.0.assignment_title', 'การบ้านเศษส่วน')
            ->assertJsonPath('data.0.assignment_id', $this->assignment->id)
            ->assertJsonPath('data.0.submission_id', $this->work->submission_id)
            ->assertJsonPath('data.0.question_position', (int) $this->q['q3']->position)
            ->assertJsonPath('data.0.max_points', 5)
            ->assertJsonPath('data.0.final_score', 2.5)
            ->assertJsonPath('data.0.student', ['id' => $this->students[0]->id, 'name' => 'นักเรียนคนที่ 1', 'student_number' => 1])
            ->assertJsonPath('meta.next_cursor', null);

        $this->asUser($this->teacher)->getJson('/api/v1/appeals')->assertJsonCount(2, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/appeals?status=rejected')->assertJsonCount(1, 'data');
        $this->asUser($this->teacher)->getJson("/api/v1/appeals?assignment_id={$this->assignment->id}")->assertJsonCount(2, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/appeals?assignment_id=999999')->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/appeals?status=maybe')->assertStatus(422);

        $this->asUser($this->makeTeacher($this->teacher->school))->getJson('/api/v1/appeals')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->students[0])->getJson('/api/v1/appeals')->assertForbidden();
    }

    public function test_accepting_with_a_new_score_updates_the_published_result(): void
    {
        $appeal = $this->openAppeal();

        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appeal->id}", [
            'status' => 'accepted',
            'teacher_note' => 'ครูตรวจแล้ว ขั้นที่ 3 ถูกจริง',
            'final_score' => 4,
            'final_understanding' => 'good',
        ])->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.teacher_note', 'ครูตรวจแล้ว ขั้นที่ 3 ถูกจริง')
            ->assertJsonPath('data.final_score', 4)
            ->assertJsonPath('data.resolved_by', $this->teacher->id);

        $this->work->refresh();
        $this->assertSame([4.0, 'good'], [$this->work->final_score, $this->work->final_understanding]);
        $submission = $this->submission($this->students[0])->refresh();
        $this->assertSame(['published', 5.0], [$submission->status, $submission->total_score]);

        $event = ScoreEvent::query()->sole();
        $this->assertSame(['appeal_accepted', 2.5, 4.0, 'partial', 'good', 'ครูตรวจแล้ว ขั้นที่ 3 ถูกจริง', $this->teacher->id], [
            $event->action, $event->old_score, $event->new_score, $event->old_understanding, $event->new_understanding, $event->reason, $event->actor_user_id,
        ]);

        $sent = $this->notifier->ofType(PushMessage::APPEAL_RESOLVED);
        $this->assertCount(1, $sent);
        $this->assertSame([$this->students[0]->id], $sent[0][0]);
        $this->assertSame('ครูตอบคำขอตรวจใหม่แล้ว', $sent[0][1]->body);
        $this->assertSame((string) $this->work->submission_id, $sent[0][1]->data()['submission_id']);

        $this->asUser($this->students[0])->getJson("/api/v1/student/results/{$this->work->submission_id}")
            ->assertOk()
            ->assertJsonPath('total_score', null) // wrapped in data
            ->assertJsonPath('data.total_score', 5)
            ->assertJsonPath('data.responses.1.final_score', 4)
            ->assertJsonPath('data.responses.1.appeal.status', 'accepted')
            ->assertJsonPath('data.responses.1.appeal.teacher_note', 'ครูตรวจแล้ว ขั้นที่ 3 ถูกจริง')
            ->assertJsonPath('data.responses.1.can_appeal', false)
            ->assertJsonPath('data.responses.0.can_appeal', true);

        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'rejected'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'appeal_resolved');
    }

    public function test_accepting_without_a_score_or_rejecting_keeps_the_score(): void
    {
        Event::fake([AppealResolved::class]);
        $accepted = $this->openAppeal($this->short);
        $rejected = $this->openAppeal();

        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$rejected->id}", ['status' => 'rejected', 'final_score' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('final_score');
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$rejected->id}", ['status' => 'rejected', 'teacher_note' => 'ขั้นที่ 3 ยังผิดเครื่องหมาย'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$accepted->id}", ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertSame([2.5, 1.0], [$this->work->refresh()->final_score, $this->short->refresh()->final_score]);
        $this->assertSame(
            [['appeal_rejected', 2.5, 2.5, 'ขั้นที่ 3 ยังผิดเครื่องหมาย'], ['appeal_accepted', 1.0, 1.0, null]],
            ScoreEvent::query()->orderBy('id')->get()->map(fn (ScoreEvent $e) => [$e->action, $e->old_score, $e->new_score, $e->reason])->all(),
        );
        Event::assertDispatched(AppealResolved::class, fn (AppealResolved $e) => ! $e->scoreChanged);
    }

    public function test_the_new_score_must_fit_the_question(): void
    {
        $appeal = $this->openAppeal();

        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'accepted', 'final_score' => 6])
            ->assertStatus(422)->assertJsonValidationErrors('final_score');
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('open', $appeal->refresh()->status);
        $this->assertSame(0, ScoreEvent::query()->count());
    }

    public function test_only_the_classroom_teacher_answers(): void
    {
        $appeal = $this->openAppeal();

        $this->asUser($this->makeTeacher($this->teacher->school))->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'accepted'])->assertNotFound();
        $this->asUser($this->makeTeacher())->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'accepted'])->assertNotFound();
        $this->asUser($this->students[0])->patchJson("/api/v1/appeals/{$appeal->id}", ['status' => 'accepted'])->assertForbidden();
        $this->assertSame('open', $appeal->refresh()->status);
    }

    public function test_an_open_appeal_keeps_a_reopened_answer_out_of_bulk_approval(): void
    {
        $this->openAppeal();
        // A confirmed rescan reopened the submission and the page was graded again.
        $this->submission($this->students[0])->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW, 'published_at' => null])->save();
        $this->work->forceFill(['final_score' => null, 'reviewed_at' => null, 'priority_band' => 'confident', 'review_priority' => 0.05])->save();

        $rows = collect($this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")->json('data'));
        $row = $rows->firstWhere('id', $this->work->id);
        $this->assertSame([true, ['appeal_open'], false], [$row['has_open_appeal'], $row['flags'], $row['bulk_approvable']]);
        $this->assertFalse($rows->firstWhere('id', $this->short->id)['has_open_appeal']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")
            ->assertJsonPath('data.approved', 0);
    }
}
