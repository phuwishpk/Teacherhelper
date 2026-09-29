<?php

namespace Tests\Feature\Grading;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Grading\AutoRules;
use App\Domain\Grading\FeedbackTemplates;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Response;
use App\Models\ScoreEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §21.3: answers decided by code before any Gemini call. An empty
 * answer box (ink_ratio < GRADING_BLANK_INK_MAX) gets 0 as "ไม่ได้ตอบ";
 * behind GRADING_CNN_SKIP_ENABLED a sure digit reading of an accepted
 * answer gets full marks as "อ่านด้วย CNN", a sample of them for the teacher.
 */
class AutoRulesTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->open->rubricCriteria()->createMany([
            ['position' => 1, 'description' => 'บอกได้ว่าคลอโรฟิลล์สะท้อนแสงสีเขียว', 'points' => 3, 'is_core' => true, 'source' => 'teacher'],
            ['position' => 2, 'description' => 'ใช้คำศัพท์ถูกต้อง', 'points' => 1, 'is_core' => false, 'source' => 'teacher'],
        ]);
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
    }

    /**
     * Posts a page with the readings changed per question id.
     *
     * @param  array<int, array<string, mixed>>  $changes  question id => region fields
     */
    private function scan(int $page, array $changes): int
    {
        $meta = $this->metaFor($page);
        foreach ($meta['regions'] as $i => $region) {
            if (isset($changes[$region['question_id']])) {
                $meta['regions'][$i] = array_merge($region, $changes[$region['question_id']]);
            }
        }

        return (int) $this->postScan($meta)->assertStatus(201)->json('scan_id');
    }

    private function runJob(int $scanId): void
    {
        $this->app->call([(new GradeScanJob($scanId))->withFakeQueueInteractions(), 'handle']);
    }

    /** @return list<string> purposes of the extraction requests sent to Gemini */
    private function extractions(): array
    {
        return array_values(array_filter(
            array_map(fn ($r) => $r->purpose, $this->gemini->requests),
            fn (string $p) => str_starts_with($p, 'extract'),
        ));
    }

    private function response(string $question): Response
    {
        return Response::query()->where('question_id', $this->{$question}->id)->sole();
    }

    public function test_an_empty_answer_box_scores_zero_without_gemini_in_the_look_band(): void
    {
        $this->runJob($this->scan(2, [$this->open->id => ['ink_ratio' => 0.003]]));

        $open = $this->response('open');
        $this->assertSame(['scored', 'blank_ink', 0.0, 'not_yet', ['no_answer']], [$open->grading_state, $open->auto_rule, $open->ai_score, $open->ai_understanding, $open->ai_error_types]);
        $this->assertSame(['look', AutoRules::LOOK_P], [$open->priority_band, $open->review_priority]);
        $this->assertSame([FeedbackTemplates::BLANK, 'template'], [$open->explanation, $open->explanation_source]);
        $this->assertTrue($open->extraction['blank']);
        $this->assertSame([['criterion_id' => 1, 'level' => 'not_met'], ['criterion_id' => 2, 'level' => 'not_met']], $open->extraction['criteria']);
        $this->assertSame('blank_ink', $open->fuzzy_trace['auto_rule']);
        $this->assertSame(1, ScoreEvent::query()->where('response_id', $open->id)->where('action', 'ai_scored')->count());

        // Only the show_work answer went to Gemini: one question in the call, no ai_calls row for the blank one.
        $this->assertSame(['extract'], $this->extractions());
        $this->assertSame(['extract'], AiCall::query()->where('purpose', 'like', 'extract%')->pluck('purpose')->all());

        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$open->id}")->assertOk();
        $detail->assertJsonPath('data.auto_rule', 'blank_ink');
        $this->assertStringContainsString('ไม่ได้ตอบ', implode("\n", $detail->json('data.why')));

        $row = collect($this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")->assertOk()->json('data'))
            ->firstWhere('id', $open->id);
        $this->assertSame(['blank_ink', 'look', false], [$row['auto_rule'], $row['tab'], $row['bulk_approvable']]);
    }

    public function test_a_box_with_a_little_ink_or_no_ink_reading_goes_to_gemini(): void
    {
        $this->runJob($this->scan(2, [$this->open->id => ['ink_ratio' => 0.006]]));
        $this->assertNull($this->response('open')->auto_rule);
        $this->assertNotNull($this->response('open')->extraction['transcription'] ?? null);
    }

    public function test_show_work_with_a_final_answer_box_is_never_skipped_on_the_working_area_ink(): void
    {
        $this->runJob($this->scan(2, [$this->work->id => ['ink_ratio' => 0.0]]));

        $work = $this->response('work');
        $this->assertNull($work->auto_rule, 'the final box may hold an answer the ink ratio does not measure');
        $this->assertSame(['extract_batch'], $this->extractions());
    }

    public function test_the_cnn_skip_is_off_by_default(): void
    {
        $this->runJob($this->scan(1, [$this->short->id => ['cnn' => ['text' => '20', 'confidence' => 0.99]]]));

        $this->assertNull($this->response('short')->auto_rule);
        $this->assertSame(['extract'], $this->extractions());
    }

    public function test_a_sure_digit_reading_of_an_accepted_answer_gets_full_marks_without_gemini(): void
    {
        config(['eduvision.grading.cnn_skip_enabled' => true, 'eduvision.grading.cnn_skip_sample_rate' => 0.0]);
        $this->runJob($this->scan(1, [$this->short->id => ['cnn' => ['text' => '๒๐', 'confidence' => 0.98]]]));

        $short = $this->response('short');
        $this->assertSame(['scored', 'cnn_match', 2.0, 'good', []], [$short->grading_state, $short->auto_rule, $short->ai_score, $short->ai_understanding, $short->ai_error_types]);
        $this->assertSame(['confident', 0.0], [$short->priority_band, $short->review_priority]);
        $this->assertSame([FeedbackTemplates::praise($short->id), 'template'], [$short->explanation, $short->explanation_source]);
        $this->assertSame(['๒๐', 'exact'], [$short->extraction['answer_text'], $short->extraction['key_match']]);
        $this->assertSame([], $this->gemini->requests);
        $this->assertSame(0, AiCall::query()->count());

        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$short->id}")->assertOk();
        $detail->assertJsonPath('data.auto_rule', 'cnn_match')->assertJsonPath('data.bulk_approvable', true);
        $this->assertStringContainsString('อ่านด้วย CNN', implode("\n", $detail->json('data.why')));
    }

    public function test_the_sampled_share_goes_to_the_teacher(): void
    {
        config(['eduvision.grading.cnn_skip_enabled' => true, 'eduvision.grading.cnn_skip_sample_rate' => 1.0]);
        $this->runJob($this->scan(1, [$this->short->id => ['cnn' => ['text' => '20', 'confidence' => 0.97]]]));

        $short = $this->response('short');
        $this->assertSame(['cnn_match', 'look', AutoRules::LOOK_P], [$short->auto_rule, $short->priority_band, $short->review_priority]);
        $this->assertTrue($short->fuzzy_trace['signals']['sampled']);
        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$short->id}")->assertOk();
        $this->assertStringContainsString('สุ่มให้ครูดู', implode("\n", $detail->json('data.why')));
    }

    public function test_an_unsure_or_different_digit_reading_goes_to_gemini(): void
    {
        config(['eduvision.grading.cnn_skip_enabled' => true]);
        $this->runJob($this->scan(1, [$this->short->id => ['cnn' => ['text' => '20', 'confidence' => 0.96]]]));
        $this->assertNull($this->response('short')->auto_rule);

        $this->runJob($this->scan(1, [$this->short->id => ['cnn' => ['text' => '21', 'confidence' => 0.99]]]));
        $this->assertNull($this->response('short')->auto_rule);
        $this->assertSame(['extract', 'extract'], $this->extractions());
    }

    public function test_code_decided_answers_need_no_key_the_rest_waits_for_one(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->runJob($this->scan(2, [$this->open->id => ['ink_ratio' => 0.0]]));

        $this->assertSame(['scored', 'blank_ink'], [$this->response('open')->grading_state, $this->response('open')->auto_rule]);
        $this->assertSame(['manual', 'ai_key_missing'], [$this->response('work')->grading_state, $this->response('work')->manualReason()]);
        $this->assertSame([], $this->gemini->requests);
    }

    public function test_a_rescan_clears_the_rule_of_the_previous_image(): void
    {
        $this->runJob($this->scan(2, [$this->open->id => ['ink_ratio' => 0.0]]));
        $this->assertSame('blank_ink', $this->response('open')->auto_rule);

        $this->runJob($this->scan(2, []));
        $this->assertNull($this->response('open')->auto_rule);
    }

    public function test_the_sample_is_stable_per_answer_and_close_to_the_rate(): void
    {
        $picked = 0;
        foreach (range(1, 5000) as $id) {
            $picked += AutoRules::sampled($id, 0.10) ? 1 : 0;
            $this->assertSame(AutoRules::sampled($id, 0.10), AutoRules::sampled($id, 0.10));
        }
        $this->assertEqualsWithDelta(500, $picked, 75);
        $this->assertFalse(AutoRules::sampled(7, 0.0));
        $this->assertTrue(AutoRules::sampled(7, 1.0));
    }
}
