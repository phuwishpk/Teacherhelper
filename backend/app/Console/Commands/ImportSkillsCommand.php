<?php

namespace App\Console\Commands;

use App\Domain\Skills\SkillCsvImporter;
use App\Domain\Skills\SkillImportException;
use Illuminate\Console\Command;

/**
 * php artisan eduvision:import-skills {csv} [--school=ID]
 *
 * Imports the core-curriculum indicators (DESIGN §20.2, docs/curriculum:
 * subject_code,level,code,parent_code,grade_level,name, or the earlier
 * subject_code,skill_code,parent_code,grade_level,name; UTF-8). With
 * --school the rows become that school's own rows (DESIGN §8.2 school_id).
 * Re-running updates rows with the same (school, code) instead of
 * duplicating them. Rows with errors are skipped and listed with their line;
 * the exit code is then 1 (the other rows are imported).
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

        $started = microtime(true);
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
            'Imported %d skills (%d created, %d updated, %d unchanged), %d new subjects%s in %.1f s.',
            $result->rows(),
            $result->created,
            $result->updated,
            $result->unchanged,
            $result->subjectsCreated,
            $schoolId === null ? '' : " for school {$schoolId}",
            microtime(true) - $started,
        ));

        if ($result->hasErrors()) {
            $this->error(count($result->errors).' rows had errors:');
            foreach ($result->errorLines() as $line) {
                $this->line('  - '.$line);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
