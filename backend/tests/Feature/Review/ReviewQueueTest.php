<?php

namespace Tests\Feature\Review;

use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\ScanGrader;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /assignments/{id}/review-queue (DESIGN §9.5, §11.8, §13): manual rows
 * first, then flagged ones, then review_priority descending; band tabs,
 * missing-key count, keyset pages.
 */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(3);
    }

    /** @return array<string, Response> */
    private function mixedQueue(): array
    {
        [$s1, $s2, $s3] = $this->students;

        return [
            'look' => $this->answer($s1, 'q1', ['review_priority' => 0.3, 'priority_band' => ReviewPriority::BAND_LOOK]),
            'confident' => $this->confidentAnswer($s1, 'q2'),
            'check_high' => $this->answer($s1, 'q3', ['review_priority' => 0.9, 'priority_band' => ReviewPriority::BAND_CHECK]),
            'manual_key' => $this->manualAnswer($s2, 'q1'),
            'suspicious' => $this->answer($s2, 'q2', [
                'review_priority' => 1.0,
                'priority_band' => ReviewPriority::BAND_CHECK,
                'fuzzy_trace' => ['system' => 'short', 'suspicious_instruction' => true, 'priority' => ['flag' => 'suspicious', 'p' => 1.0, 'band' => 'check']],
            ]),
            'identity' => $this->confidentAnswer($s2, 'q3', ['fuzzy_trace' => ['system' => 'show_work', 'identity_mismatch' => true]]),
            'look_higher' => $this->answer($s3, 'q1', ['review_priority' => 0.45, 'priority_band' => ReviewPriority::BAND_LOOK]),
            'queued' => $this->answer($s3, 'q2', ['grading_state' => Response::STATE_QUEUED, 'ai_score' => null, 'ai_understanding' => null, 'review_priority' => null, 'priority_band' => null, 'fuzzy_trace' => null]),
            'manual_error' => $this->manualAnswer($s3, 'q3', ScanGrader::REASON_AI_ERROR),
        ];
    }

    private function queueUrl(string $query = ''): string
    {
        return "/api/v1/assignments/{$this->assignment->id}/review-queue".($query === '' ? '' : '?'.$query);
    }

    public function test_manual_first_then_flagged_then_priority_descending(): void
    {
        $r = $this->mixedQueue();

        $res = $this->asUser($this->teacher)->getJson($this->queueUrl())->assertOk();

        $this->assertSame([
            $r['manual_key']->id,    // manual, student 2
            $r['manual_error']->id,  // manual, student 3
            $r['suspicious']->id,    // flagged, p 1.0
            $r['identity']->id,      // flagged, p 0.05
            $r['check_high']->id,    // 0.9
            $r['look_higher']->id,   // 0.45
            $r['look']->id,          // 0.3
            $r['confident']->id,     // 0.05
            $r['queued']->id,        // not graded yet
        ], $res->json('data.*.id'));

        $manual = $res->json('data.0');
        $this->assertSame('manual', $manual['grading_state']);
        $this->assertSame('ai_key_missing', $manual['manual_reason']);
        $this->assertSame(['id' => $this->students[1]->id, 'name' => 'นักเรียนคนที่ 2', 'student_number' => 2], $manual['student']);
        $this->assertSame('ai_error', $res->json('data.1.manual_reason'));
        $this->assertSame(['suspicious'], $res->json('data.2.flags'));
        $this->assertTrue($res->json('data.2.suspicious'));
        $this->assertSame(['identity_mismatch'], $res->json('data.3.flags'));
        $this->assertSame('check', $res->json('data.3.tab'), 'a flagged confident row sits on the check tab');
        $this->assertFalse($res->json('data.3.bulk_approvable'));
        $this->assertTrue($res->json('data.7.bulk_approvable'));
        $this->assertNull($res->json('data.4.manual_reason'));
        $this->assertSame((int) $this->q['q3']->position, $res->json('data.4.question_position'));
        $this->assertSame('show_work', $res->json('data.4.question_type'));
        $this->assertEquals(5, $res->json('data.4.max_points'));

        $res->assertJsonPath('meta.missing_ai_key_count', 1)
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.bulk_approvable_count', 1)
            ->assertJsonPath('meta.counts.check.total', 6)
            ->assertJsonPath('meta.counts.look.total', 2)
            ->assertJsonPath('meta.counts.confident.total', 1);
    }

    public function test_band_narrows_the_queue_to_one_tab(): void
    {
        $r = $this->mixedQueue();
        $teacher = $this->asUser($this->teacher);

        $this->assertSame(
            [$r['manual_key']->id, $r['manual_error']->id, $r['suspicious']->id, $r['identity']->id, $r['check_high']->id, $r['queued']->id],
            $teacher->getJson($this->queueUrl('band=check'))->assertOk()->json('data.*.id'),
        );
        $this->assertSame([$r['look_higher']->id, $r['look']->id], $this->asUser($this->teacher)->getJson($this->queueUrl('band=look'))->json('data.*.id'));
        $this->assertSame([$r['confident']->id], $this->asUser($this->teacher)->getJson($this->queueUrl('band=confident'))->json('data.*.id'));

        $this->asUser($this->teacher)->getJson($this->queueUrl('band=urgent'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_pages_follow_the_cursor_without_gaps_or_repeats(): void
    {
        $this->mixedQueue();
        $all = $this->asUser($this->teacher)->getJson($this->queueUrl())->json('data.*.id');

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $res = $this->asUser($this->teacher)->getJson($this->queueUrl(http_build_query(array_filter(['per_page' => 4, 'cursor' => $cursor]))))->assertOk();
            $seen = [...$seen, ...$res->json('data.*.id')];
            $cursor = $res->json('meta.next_cursor');
            $pages++;
            if ($pages === 1) {
                // A row graded between two pages changes nothing already delivered.
                Response::query()->where('grading_state', Response::STATE_QUEUED)->update(['grading_state' => 'scored', 'review_priority' => 0.99, 'priority_band' => 'check', 'ai_score' => 1, 'ai_understanding' => 'partial']);
            }
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame(count(array_unique($seen)), count($seen), 'no row is repeated');
        $this->assertSame(array_slice($all, 0, 4), array_slice($seen, 0, 4));
        $this->assertEqualsCanonicalizing($all, $seen, 'the row graded meanwhile moved to a later page and still came');

        $this->asUser($this->teacher)->getJson($this->queueUrl('cursor=not-a-cursor'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cursor');
    }

    public function test_meta_lists_submissions_and_rescans_waiting_for_confirmation(): void
    {
        [$s1, $s2] = $this->students;
        $this->confidentAnswer($s1, 'q1', ['final_score' => 2, 'final_understanding' => 'good', 'reviewed_at' => now(), 'reviewed_by' => $this->teacher->id]);
        $this->answer($s1, 'q2');
        $this->answer($s2, 'q1');
        $published = $this->submission($s2);
        $published->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 1])->save();
        $pending = $this->scanFor($published, 1, Scan::STATE_PENDING_CONFIRM);

        $res = $this->asUser($this->teacher)->getJson($this->queueUrl())->assertOk();

        $res->assertJsonPath('meta.submissions.0.student.student_number', 1)
            ->assertJsonPath('meta.submissions.0.response_count', 2)
            ->assertJsonPath('meta.submissions.0.reviewed_count', 1)
            ->assertJsonPath('meta.submissions.0.total_score', 3)
            ->assertJsonPath('meta.submissions.1.status', 'published')
            ->assertJsonPath('meta.submissions.1.total_score', 1)
            ->assertJsonPath('meta.pending_confirm_scans.0.scan_id', $pending->id)
            ->assertJsonPath('meta.pending_confirm_scans.0.student.student_number', 2);
        $this->assertSame('published', $res->json('data.1.submission_status'));
    }

    public function test_only_the_classroom_teacher_sees_the_queue(): void
    {
        $this->mixedQueue();

        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->getJson($this->queueUrl())->assertNotFound();
        $this->asUser($this->makeTeacher())->getJson($this->queueUrl())->assertNotFound();
        $this->asUser($this->students[0])->getJson($this->queueUrl())->assertForbidden();
        $this->asGuest()->getJson($this->queueUrl())->assertUnauthorized();
    }
}
