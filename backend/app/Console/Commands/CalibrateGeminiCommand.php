<?php

namespace App\Console\Commands;

use App\Domain\Gemini\Calibration\BudgetedClient;
use App\Domain\Gemini\Calibration\CalibrationManifest;
use App\Domain\Gemini\Calibration\CalibrationRunner;
use App\Domain\Gemini\Calibration\CalibrationScorer;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Models\AiCall;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The calibration harness of DESIGN §21.10: sends the labelled golden
 * fixtures (docs/fixtures/calibration/manifest.json) to the REAL Gemini API
 * at `high` and at each lower level under test, compares what was read with
 * the labels, and says which media resolution each kind of image may use.
 *
 *   php artisan eduvision:calibrate-gemini                       # every kind, low + medium vs high
 *   php artisan eduvision:calibrate-gemini --kind=short --level=low
 *   php artisan eduvision:calibrate-gemini --dry-run             # the plan and the call count only
 *
 * A developer runs it with their own key (GEMINI_API_KEY, or --teacher=<id>
 * for a key saved in the app); never in tests or CI (tests bind the fake).
 * --max-calls caps the requests, retries included (default 60). Thresholds
 * come from .env (GEMINI_CALIBRATION_MIN_SAMPLES, _MAX_DROP,
 * _MAX_SCORE_FLIPS). Results: one JSON per kind and level in
 * storage/app/calibration/{date}-{kind}-{level}.json. The command only
 * prints the .env values it recommends: the developer changes .env after
 * reading the results. With `short` it also checks the CNN-skip rule
 * (§21.3) on the samples that carry a digit reading (no Gemini call).
 * Prints only the last 4 characters of a key.
 */
class CalibrateGeminiCommand extends Command
{
    protected $signature = 'eduvision:calibrate-gemini
        {--kind=* : short, work, page or document (default: all four)}
        {--level=* : low, medium or high to compare with high (default: low and medium)}
        {--manifest= : the manifest.json (default: docs/fixtures/calibration/manifest.json)}
        {--max-calls=60 : at most this many Gemini requests, retries included}
        {--teacher= : use the key this teacher (user id) saved instead of GEMINI_API_KEY}
        {--out= : directory for the JSON results (default: storage/app/calibration)}
        {--dry-run : print the plan and the number of calls, send nothing}';

    protected $description = 'Measure Gemini reading accuracy per media resolution on the golden fixtures (DESIGN §21.10)';

    public function handle(GeminiClient $client, GeminiKeyResolver $keys, CalibrationRunner $runner): int
    {
        $kinds = $this->option('kind') ?: CalibrationManifest::KINDS;
        $levels = $this->option('level') ?: ['low', 'medium'];
        foreach ($kinds as $kind) {
            if (! in_array($kind, CalibrationManifest::KINDS, true)) {
                $this->error("unknown --kind={$kind} (short, work, page, document)");

                return self::FAILURE;
            }
        }
        foreach ($levels as $level) {
            if (! in_array($level, CalibrationScorer::LEVELS, true)) {
                $this->error("unknown --level={$level} (low, medium, high)");

                return self::FAILURE;
            }
        }
        $levels = array_values(array_intersect(['low', 'medium', 'high'], $levels));
        $targets = array_values(array_diff($levels, ['high']));

        $path = (string) ($this->option('manifest') ?: base_path('../docs/fixtures/calibration/manifest.json'));
        try {
            $manifest = CalibrationManifest::load($path);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $limits = [
            'min_samples' => max(1, (int) config('services.gemini.calibration.min_samples', 40)),
            'max_drop' => max(0.0, (float) config('services.gemini.calibration.max_drop', 0.02)),
            'max_score_flips' => max(0, (int) config('services.gemini.calibration.max_score_flips', 0)),
        ];

        $planned = 0;
        $this->line('plan (every kind runs at high as the baseline):');
        foreach ($kinds as $kind) {
            $calls = $runner->callCount($manifest, $kind);
            $runs = 1 + count($targets);
            $planned += $calls * $runs;
            $this->line(sprintf('  %-8s %3d samples  levels: %-20s %d call(s) per level', $kind, $manifest->sampleCount($kind), implode(', ', ['high', ...$targets]), $calls));
        }
        $maxCalls = max(1, (int) $this->option('max-calls'));
        $this->line("calls: {$planned} planned (+ a retry for any invalid output), cap {$maxCalls}");
        $this->line(sprintf('thresholds: min %d samples, max drop %s, max score flips %d', $limits['min_samples'], $limits['max_drop'], $limits['max_score_flips']));
        if ($planned > $maxCalls) {
            $this->error('the plan needs more calls than --max-calls: narrow --kind / --level or raise --max-calls');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $fake = $client instanceof FakeGeminiClient;
        $this->line('client: '.($fake ? 'FakeGeminiClient (GEMINI_FAKE=true): the numbers are NOT a measurement' : 'HttpGeminiClient').', model '.$client->model());
        $teacher = $this->option('teacher');
        $key = $teacher !== null ? $keys->teacherKey((int) $teacher) : $keys->serverKey();
        if ($key === null) {
            $this->error($teacher !== null ? "teacher {$teacher} has no usable saved key" : 'GEMINI_API_KEY is empty: set it in .env or pass --teacher=<user id>');

            return self::FAILURE;
        }
        $this->line("key: {$key->source} ••••{$key->last4()}");

        $budget = new BudgetedClient($client, $maxCalls);
        $gateway = new GeminiGateway($budget);
        $out = (string) ($this->option('out') ?: storage_path('app/calibration'));
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("cannot create {$out}");

            return self::FAILURE;
        }
        $date = now()->format('Y-m-d');

        $table = [];
        $recommend = [];
        foreach ($kinds as $kind) {
            $samples = [];
            foreach ($manifest->units($kind) as $unit) {
                foreach ($unit->samples as $sample) {
                    $samples[$sample->id] = $sample;
                }
            }
            if ($samples === []) {
                $this->warn("{$kind}: no samples in the manifest");

                continue;
            }

            $rows = [];
            $passed = [];
            foreach (['high', ...$targets] as $level) {
                $before = (int) AiCall::query()->max('id');
                $run = $runner->run($gateway, $key, $manifest, $kind, $level);
                $rows[$level] = array_values(array_map(
                    fn ($sample) => CalibrationScorer::score($sample, $run['results'][$sample->id] ?? null, $kind === 'document'),
                    $samples,
                ));
                $tokens = AiCall::query()->where('id', '>', $before)->where('feature', CalibrationRunner::FEATURE)
                    ->selectRaw('COUNT(*) AS requests, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(image_count) AS images')
                    ->first();
                $summary = CalibrationScorer::summary($rows[$level]);
                $verdict = $level === 'high' ? null : CalibrationScorer::verdict($rows['high'], $rows[$level], $limits);
                if ($verdict !== null) {
                    $passed[$level] = $verdict['pass'];
                }
                $usage = [
                    'requests' => (int) ($tokens->requests ?? 0),
                    'input_tokens' => (int) ($tokens->input_tokens ?? 0),
                    'output_tokens' => (int) ($tokens->output_tokens ?? 0),
                    'images' => (int) ($tokens->images ?? 0),
                ];
                file_put_contents("{$out}/{$date}-{$kind}-{$level}.json", json_encode([
                    'kind' => $kind,
                    'level' => $level,
                    'model' => $client->model(),
                    'fake' => $fake,
                    'measured_at' => now()->toIso8601String(),
                    'thresholds' => $limits,
                    'summary' => $summary,
                    'verdict' => $verdict,
                    'usage' => $usage,
                    'errors' => $run['errors'],
                    'samples' => $rows[$level],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                foreach ($run['errors'] as $error) {
                    $this->warn("  {$kind} {$level}: {$error}");
                }
                $table[] = [
                    $kind,
                    $level,
                    $summary['samples'],
                    $summary['found'],
                    self::percent($summary['answer_accuracy']),
                    self::percent($summary['category_accuracy']),
                    $verdict === null ? 'baseline' : self::signedPoints(-$verdict['answer_drop']).' / '.self::signedPoints(-$verdict['category_drop']),
                    $verdict === null ? '-' : (string) $verdict['score_flips'],
                    $usage['images'] > 0 ? (string) intdiv($usage['input_tokens'], $usage['images']) : '-',
                    $verdict === null ? '-' : ($verdict['pass'] ? 'PASS' : 'fail: '.implode('; ', $verdict['reasons'])),
                ];
            }
            $recommend[$kind] = [CalibrationScorer::recommend($passed), $passed];
        }

        $this->table(['kind', 'level', 'samples', 'read', 'answer', 'category', 'Δ vs high', 'flips', 'input tok/image', 'verdict'], $table);

        $this->line('recommended .env values, to set by hand after checking the JSON in '.$out.':');
        foreach ($recommend as $kind => [$level, $passed]) {
            $tested = $passed === [] ? 'nothing below high tested' : implode(', ', array_map(fn ($l, $ok) => "{$l} ".($ok ? 'passed' : 'failed'), array_keys($passed), $passed));
            $this->line(sprintf('  %s=%s   # %s', CalibrationScorer::ENV[$kind], $level, $tested));
        }

        if (in_array('short', $kinds, true)) {
            $shortSamples = [];
            foreach ($manifest->units('short') as $unit) {
                array_push($shortSamples, ...$unit->samples);
            }
            $cnn = CalibrationScorer::cnnSkip($shortSamples, (float) config('eduvision.grading.cnn_skip_min_confidence', 0.97), $limits);
            if ($cnn['samples'] === 0) {
                $this->line('CNN skip (§21.3): no sample carries a digit reading (cnn) yet: keep GRADING_CNN_SKIP_ENABLED=false');
            } else {
                $this->line(sprintf(
                    'CNN skip (§21.3): %d samples with a reading, %d decided at confidence >= %s, %d wrongly -> %s',
                    $cnn['samples'], $cnn['decided'], $cnn['min_confidence'], $cnn['wrong'],
                    $cnn['pass'] ? 'GRADING_CNN_SKIP_ENABLED=true may be set' : 'keep GRADING_CNN_SKIP_ENABLED=false',
                ));
            }
        }
        $this->line("requests sent: {$budget->sent()} of the {$maxCalls} allowed");

        return self::SUCCESS;
    }

    private static function percent(?float $rate): string
    {
        return $rate === null ? '-' : number_format($rate * 100, 1).'%';
    }

    private static function signedPoints(float $delta): string
    {
        return ($delta > 0 ? '+' : '').number_format($delta * 100, 1);
    }
}
