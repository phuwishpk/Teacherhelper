<?php

namespace Tests\Feature\Console;

use App\Domain\Gemini\Calibration\CalibrationManifest;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiReply;
use App\Domain\Gemini\GeminiRequest;
use App\Models\AiCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * DESIGN §21.10 eduvision:calibrate-gemini against FakeGeminiClient only
 * (never the real API in tests): the plan, the call cap, the per-level
 * verdicts, the JSON results and the recommended .env values.
 */
class CalibrateGeminiCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/eduvision-calibration-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/img');
        File::ensureDirectoryExists($this->dir.'/out');
        $synthetic = base_path('../docs/fixtures/calibration/synthetic');
        foreach (['short-001', 'short-002', 'short-003', 'work-001', 'work-001-final', 'page-001', 'document-001'] as $name) {
            copy("{$synthetic}/{$name}.webp", "{$this->dir}/img/{$name}.webp");
        }

        $short = fn (string $id, string $written) => ['id' => $id, 'kind' => 'short', 'source' => 'test', 'file' => "img/{$id}.webp",
            'question' => ['type' => 'short', 'prompt_text' => '12 + 8 เท่ากับเท่าไร', 'is_numeric' => true, 'answer_key' => ['accepted' => ['20'], 'numeric' => ['value' => 20, 'abs_tol' => 0]]],
            'label' => ['answer_text' => $written, 'key_match' => $written === '20' ? ['exact'] : ['different']],
            'cnn' => ['text' => $written, 'confidence' => 0.99]];
        $this->manifest = $this->dir.'/manifest.json';
        file_put_contents($this->manifest, json_encode([
            'version' => 1,
            'subject' => 'คณิตศาสตร์',
            'grade_level' => 5,
            'items' => [
                $short('short-001', '20'),
                $short('short-002', '20'),
                $short('short-003', '21'),
                ['id' => 'work-001', 'kind' => 'work', 'source' => 'test', 'file' => 'img/work-001.webp', 'final_file' => 'img/work-001-final.webp',
                    'question' => ['type' => 'show_work', 'prompt_text' => '3x + 5 = 20', 'max_points' => 3, 'answer_key' => ['final' => ['accepted' => ['5']], 'reference_steps' => ['3x = 15', 'x = 5']]],
                    'label' => ['final_answer_text' => '5', 'final_answer_match' => ['exact'], 'steps_valid' => [true, true]]],
                ['id' => 'page-001', 'kind' => 'page', 'source' => 'test', 'file' => 'img/page-001.webp', 'questions' => [
                    ['position' => 1, 'type' => 'short', 'prompt_text' => '16 + 26', 'answer_key' => ['accepted' => ['42']], 'label' => ['answer_text' => '41', 'key_match' => ['different']]],
                    ['position' => 2, 'type' => 'short', 'prompt_text' => 'น้ำแข็งละลายกลายเป็นอะไร', 'answer_key' => ['accepted' => ['น้ำ']], 'label' => ['answer_text' => 'น้ำ', 'key_match' => ['exact']]],
                ]],
                ['id' => 'document-001', 'kind' => 'document', 'source' => 'test', 'file' => 'img/document-001.webp', 'questions' => [
                    ['position' => 1, 'type' => 'short', 'prompt_text' => '37 + 63', 'label' => ['answer' => '100']],
                    ['position' => 4, 'type' => 'mcq', 'prompt_text' => 'ข้อ 4', 'label' => ['answer' => 'B']],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
        config(['services.gemini.calibration.min_samples' => 2]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** The fake, except that nothing is read at `low`: every low-level call fails. */
    private function blurryAtLow(): FakeGeminiClient
    {
        $client = new class('gemini-3.8-flash') extends FakeGeminiClient
        {
            public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
            {
                $low = array_filter($requests, fn (GeminiRequest $r) => ($r->images[0]->mediaResolution ?? null) === 'low');
                $replies = parent::generate(array_diff_key($requests, $low), $apiKey);
                foreach (array_keys($low) as $key) {
                    $replies[$key] = GeminiReply::error('HTTP 500: unreadable');
                }

                return $replies;
            }
        };
        $this->app->instance(GeminiClient::class, $client);

        return $client;
    }

    private function calibrate(array $options): PendingCommand
    {
        return $this->artisan('eduvision:calibrate-gemini', ['--manifest' => $this->manifest, '--out' => $this->dir.'/out'] + $options);
    }

    public function test_each_level_is_compared_with_high_and_the_lowest_passing_one_is_recommended(): void
    {
        $this->blurryAtLow();

        $this->calibrate(['--kind' => ['short', 'page']])
            ->expectsOutputToContain('GEMINI_MEDIA_SHORT=medium   # low failed, medium passed')
            ->expectsOutputToContain('GEMINI_MEDIA_PAGE=medium')
            ->expectsOutputToContain('CNN skip (§21.3): 3 samples with a reading, 2 decided')
            ->assertSuccessful();

        $files = collect(File::files($this->dir.'/out'))->map->getFilename()->sort()->values()->all();
        $date = now()->format('Y-m-d');
        $this->assertSame(["{$date}-page-high.json", "{$date}-page-low.json", "{$date}-page-medium.json", "{$date}-short-high.json", "{$date}-short-low.json", "{$date}-short-medium.json"], $files);

        $low = json_decode((string) file_get_contents("{$this->dir}/out/{$date}-short-low.json"), true);
        $this->assertSame([false, 0], [$low['verdict']['pass'], $low['summary']['found']]);
        $medium = json_decode((string) file_get_contents("{$this->dir}/out/{$date}-short-medium.json"), true);
        $this->assertEquals([true, 0, 0], [$medium['verdict']['pass'], $medium['verdict']['answer_drop'], $medium['verdict']['score_flips']]);
        $this->assertSame(3, $medium['summary']['samples']);
        $this->assertArrayHasKey('input_tokens', $medium['usage']);

        // ai_calls: every request labelled calibration at its own level.
        $calls = AiCall::query()->get();
        $this->assertSame(['calibration'], $calls->pluck('feature')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['high', 'low', 'medium'], $calls->pluck('media_resolution')->unique()->values()->all());
    }

    public function test_every_kind_runs_through_the_production_prompts(): void
    {
        $client = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $client);

        $this->calibrate(['--level' => ['medium']])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['extract_batch', 'extract', 'extract_page', 'answer_key_read'], array_values(array_unique(array_map(fn ($r) => $r->purpose, $client->requests))));
        foreach ($client->requests as $request) {
            foreach ($request->images as $image) {
                $this->assertContains($image->mediaResolution, ['high', 'medium']);
            }
        }
        $this->assertSame(8, AiCall::query()->count(), '4 kinds x (high + medium), one call each');
    }

    public function test_the_plan_must_fit_the_call_cap(): void
    {
        $client = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $client);

        $this->calibrate(['--max-calls' => 5])->expectsOutputToContain('calls: 12 planned')->assertFailed();
        $this->calibrate(['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $client->requests);
    }

    public function test_the_cap_holds_even_when_invalid_output_is_retried(): void
    {
        $client = new class('gemini-3.8-flash') extends FakeGeminiClient
        {
            public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
            {
                $this->requests = [...$this->requests, ...array_values($requests)];

                return array_map(fn () => GeminiReply::ok('not json'), $requests);
            }
        };
        $this->app->instance(GeminiClient::class, $client);

        $this->calibrate(['--kind' => ['short'], '--level' => ['low'], '--max-calls' => 2])->assertSuccessful();
        $this->assertCount(2, $client->requests, 'high: invalid + its retry; low: nothing left');
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->calibrate(['--kind' => ['short']])->expectsOutputToContain('GEMINI_API_KEY is empty')->assertFailed();
    }

    public function test_a_bad_option_or_manifest_is_refused(): void
    {
        $this->calibrate(['--kind' => ['essay']])->assertFailed();
        $this->calibrate(['--level' => ['ultra']])->assertFailed();
        $this->artisan('eduvision:calibrate-gemini', ['--manifest' => $this->dir.'/missing.json'])->assertFailed();
    }

    public function test_the_shipped_golden_set_has_enough_labelled_samples_per_kind(): void
    {
        $manifest = CalibrationManifest::load(base_path('../docs/fixtures/calibration/manifest.json'));
        foreach (CalibrationManifest::KINDS as $kind) {
            $this->assertGreaterThanOrEqual(40, $manifest->sampleCount($kind), "{$kind}: GEMINI_CALIBRATION_MIN_SAMPLES");
        }
        // Labels agree with the keys: a short answer labelled right is an accepted answer.
        foreach ($manifest->units('short') as $unit) {
            $sample = $unit->samples[0];
            $accepted = (array) $sample->question->answer_key['accepted'];
            $this->assertSame(in_array($sample->label['answer_text'], $accepted, true), in_array('exact', $sample->label['key_match'], true), $sample->id);
        }
    }
}
