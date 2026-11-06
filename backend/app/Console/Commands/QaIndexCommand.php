<?php

namespace App\Console\Commands;

use App\Domain\Qa\TestIndex;
use Illuminate\Console\Command;

/**
 * Writes the test names behind each automated case of the Test Lab
 * (DESIGN §39) to a JSON file. The deploy workflow runs it from the full
 * checkout, because the deploy tree has no tests/ folder:
 *   php backend/artisan eduvision:qa-index --output=<deploy>/backend/resources/qa/test-index.json
 */
class QaIndexCommand extends Command
{
    protected $signature = 'eduvision:qa-index {--output= : where to write (default backend/resources/qa/test-index.json)}';

    protected $description = 'Index the tests behind the Test Lab automated cases (DESIGN §39)';

    public function handle(): int
    {
        $refs = TestIndex::build();
        $output = (string) ($this->option('output') ?: base_path(TestIndex::MANIFEST));
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        $count = array_sum(array_map('count', $refs));
        file_put_contents($output, json_encode(['generated_at' => now()->toIso8601String(), 'refs' => $refs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->info(count($refs)." files, {$count} tests -> {$output}");

        return self::SUCCESS;
    }
}
