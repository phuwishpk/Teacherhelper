<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ExportObservationsCommand;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * eduvision:export-observations (DESIGN §7.5, §14.4): the CSV the BKT
 * notebook reads (student_id, skill_id, source, score_ratio, observed_at).
 */
class ExportObservationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/eduvision-obs-'.uniqid().'/skill_observations.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    public function test_it_writes_every_observation_with_iso_timestamps(): void
    {
        $skill = Skill::factory()->create();
        $a = User::factory()->student()->create();
        $b = User::factory()->student()->create();
        SkillObservation::create(['student_id' => $a->id, 'skill_id' => $skill->id, 'source' => 'homework', 'score_ratio' => 0.5, 'observed_at' => '2026-06-01 01:00:00']);
        SkillObservation::create(['student_id' => $b->id, 'skill_id' => $skill->id, 'source' => 'practice', 'score_ratio' => 1, 'observed_at' => '2026-06-08 02:30:00']);

        $this->artisan('eduvision:export-observations', ['path' => $this->path])
            ->expectsOutputToContain('Wrote 2 observations')
            ->assertSuccessful();

        $lines = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents($this->path))));
        $this->assertSame(ExportObservationsCommand::COLUMNS, $lines[0]);
        $this->assertSame([(string) $a->id, (string) $skill->id, 'homework', '', '', '0.500', '2026-06-01T01:00:00Z'], array_slice($lines[1], 1));
        $this->assertSame([(string) $b->id, (string) $skill->id, 'practice', '', '', '1.000', '2026-06-08T02:30:00Z'], array_slice($lines[2], 1));

        // --school keeps only that school's students; --since cuts by date.
        $this->artisan('eduvision:export-observations', ['path' => $this->path, '--school' => $b->school_id])->expectsOutputToContain('Wrote 1 observations')->assertSuccessful();
        $this->artisan('eduvision:export-observations', ['path' => $this->path, '--since' => '2026-06-05'])->expectsOutputToContain('Wrote 1 observations')->assertSuccessful();
        $this->artisan('eduvision:export-observations', ['path' => $this->path, '--since' => 'yesterday-ish'])->assertFailed();
        $this->artisan('eduvision:export-observations', ['path' => $this->path, '--school' => 'abc'])->assertFailed();
    }
}
