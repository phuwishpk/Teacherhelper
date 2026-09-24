<?php

namespace App\Console\Commands;

use App\Domain\Skills\SkillCsvImporter;
use App\Domain\Skills\SkillImportException;
use Illuminate\Console\Command;

/**
 * php artisan eduvision:import-skills {csv} [--school=ID]
 *
 * Imports curriculum indicators (DESIGN §2.3 CSV: subject_code,skill_code,
 * parent_code,grade_level,name; UTF-8 without BOM). With --school the rows
 * become that school's own sub-skills (DESIGN §8.2 school_id). Re-running
 * updates rows with the same (school, skill_code) instead of duplicating them.
 */
class ImportSkillsCommand extends Command
{
    protected $signature = 'eduvision:import-skills
                            {csv : Path to the CSV file}
                            {--school= : School id; omit for curriculum-wide indicators}';

    protected $description = 'Import subjects and skills (curriculum indicators) from a CSV file';

    public function handle(SkillCsvImporter $importer): int
    {
        $path = (string) $this->argument('csv');
        $school = $this->option('school');
        $schoolId = $school === null || $school === '' ? null : (int) $school;

        if ($schoolId !== null && (string) $schoolId !== (string) $school) {
            $this->error('--school must be a numeric school id');

            return self::INVALID;
        }

        try {
            $result = $importer->importFile($path, $schoolId);
        } catch (SkillImportException $e) {
            $this->error('Import rejected; nothing was written:');
            foreach ($e->errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        $this->info(sprintf(
            'Imported %d skills (%d created, %d updated), %d new subjects%s.',
            $result->rows(),
            $result->created,
            $result->updated,
            $result->subjectsCreated,
            $schoolId === null ? '' : " for school {$schoolId}",
        ));

        return self::SUCCESS;
    }
}
