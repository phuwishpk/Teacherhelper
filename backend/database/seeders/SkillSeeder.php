<?php

namespace Database\Seeders;

use App\Domain\Skills\SkillCsvImporter;
use Illuminate\Database\Seeder;

/**
 * Local/demo data: imports database/data/skills_sample.csv (DESIGN §2.3
 * format) as curriculum indicators. Idempotent: the importer upserts on
 * skill_code. Production imports the real file through Filament.
 */
class SkillSeeder extends Seeder
{
    public const SAMPLE_CSV = __DIR__.'/../data/skills_sample.csv';

    public function run(SkillCsvImporter $importer): void
    {
        $result = $importer->importFile(self::SAMPLE_CSV);

        $this->command?->info(sprintf(
            'skills_sample.csv: %d created, %d updated, %d subjects created',
            $result->created,
            $result->updated,
            $result->subjectsCreated,
        ));
    }
}
