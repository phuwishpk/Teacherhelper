<?php

namespace App\Console\Commands;

use App\Models\SkillObservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * php artisan eduvision:export-observations [path] [--school=ID] [--since=YYYY-MM-DD]
 *
 * Writes skill_observations as CSV for the BKT notebook
 * (ml/notebooks/bkt_vs_ewma, DESIGN §7.5, §14.4). Columns: id, student_id,
 * skill_id, source, response_id, practice_attempt_id, score_ratio,
 * observed_at (ISO 8601, UTC). Student ids are opaque numbers; no names.
 * Default path: storage/app/private/exports/skill_observations-<stamp>.csv,
 * which an admin fetches through the Plesk file manager.
 */
class ExportObservationsCommand extends Command
{
    public const COLUMNS = ['id', 'student_id', 'skill_id', 'source', 'response_id', 'practice_attempt_id', 'score_ratio', 'observed_at'];

    protected $signature = 'eduvision:export-observations
                            {path? : CSV file to write (default storage/app/private/exports/...)}
                            {--school= : Only students of this school id}
                            {--since= : Only observations observed on or after this date}';

    protected $description = 'Export skill_observations as CSV for the BKT vs EWMA notebook';

    public function handle(): int
    {
        $query = SkillObservation::query()->orderBy('id');

        $school = $this->option('school');
        if ($school !== null && $school !== '') {
            if (! ctype_digit((string) $school)) {
                $this->error('--school must be a numeric school id');

                return self::INVALID;
            }
            $query->whereIn('student_id', User::query()->select('id')->where('school_id', (int) $school)->where('role', User::ROLE_STUDENT));
        }

        $since = $this->option('since');
        if ($since !== null && $since !== '') {
            try {
                $query->where('observed_at', '>=', CarbonImmutable::parse((string) $since)->startOfDay());
            } catch (\Throwable) {
                $this->error('--since must be a date, e.g. 2026-05-01');

                return self::INVALID;
            }
        }

        $path = (string) ($this->argument('path') ?? '');
        if ($path === '') {
            $path = storage_path('app/private/exports/skill_observations-'.now()->format('Ymd-His').'.csv');
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}");

            return self::FAILURE;
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error("Cannot write {$path}");

            return self::FAILURE;
        }

        fputcsv($handle, self::COLUMNS, ',', '"', '\\', "\n");
        $rows = 0;
        $query->chunkById(500, function (Collection $chunk) use ($handle, &$rows) {
            foreach ($chunk as $observation) {
                /** @var SkillObservation $observation */
                fputcsv($handle, [
                    $observation->id,
                    $observation->student_id,
                    $observation->skill_id,
                    $observation->source,
                    $observation->response_id,
                    $observation->practice_attempt_id,
                    number_format((float) $observation->score_ratio, 3, '.', ''),
                    $observation->observed_at?->toIso8601ZuluString(),
                ], ',', '"', '\\', "\n");
                $rows++;
            }
        });
        fclose($handle);

        $this->info("Wrote {$rows} observations to {$path}");

        return self::SUCCESS;
    }
}
