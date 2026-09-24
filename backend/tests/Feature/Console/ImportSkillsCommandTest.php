<?php

namespace Tests\Feature\Console;

use App\Domain\Skills\SkillCsvImporter;
use App\Domain\Skills\SkillImportException;
use App\Models\School;
use App\Models\Skill;
use App\Models\Subject;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * eduvision:import-skills and the CSV importer behind it (DESIGN §2.3, §8.2).
 */
class ImportSkillsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sample_csv_imports_and_is_idempotent(): void
    {
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])
            ->expectsOutputToContain('Imported 13 skills (13 created, 0 updated), 2 new subjects')
            ->assertSuccessful();

        $this->assertSame(2, Subject::query()->count());
        $this->assertSame(13, Skill::query()->count());
        $this->assertDatabaseHas('subjects', ['code' => 'ค', 'name' => 'คณิตศาสตร์']);

        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $this->assertNull($standard->grade_level);
        $this->assertNull($standard->parent_id);
        $indicator = Skill::query()->where('code', 'ค 1.1 ป.5/1')->firstOrFail();
        $this->assertSame($standard->id, $indicator->parent_id);
        $this->assertSame(5, $indicator->grade_level);
        $this->assertNull($indicator->school_id);

        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])
            ->expectsOutputToContain('(0 created, 13 updated)')
            ->assertSuccessful();
        $this->assertSame(13, Skill::query()->count());
    }

    public function test_a_bad_file_is_rejected_as_a_whole_with_line_numbers(): void
    {
        $path = $this->tmpCsv(implode("\n", [
            'subject_code,skill_code,parent_code,grade_level,name',
            'ค,ค 1.1 ป.5/1,,5,ถูกต้อง',
            'ค,,,,ไม่มีรหัส',
            'ค,ค 1.1 ป.5/3,,13,ชั้นผิด',
            'ค,ค 1.1 ป.5/1,,5,รหัสซ้ำ',
        ]));

        $this->artisan('eduvision:import-skills', ['csv' => $path])
            ->expectsOutputToContain('บรรทัด 3')
            ->expectsOutputToContain('บรรทัด 4')
            ->expectsOutputToContain('บรรทัด 5')
            ->assertFailed();

        $this->assertSame(0, Skill::query()->count());
        $this->assertSame(0, Subject::query()->count());

        $this->artisan('eduvision:import-skills', ['csv' => '/nonexistent.csv'])->assertFailed();
        $this->artisan('eduvision:import-skills', ['csv' => $path, '--school' => 'abc'])->assertExitCode(2);
    }

    public function test_school_sub_skills_hang_under_curriculum_indicators(): void
    {
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])->assertSuccessful();
        $school = School::factory()->create();

        $path = $this->tmpCsv(implode("\n", [
            'subject_code,skill_code,parent_code,grade_level,name',
            'ค,ค 1.1 ป.5/1 ก,ค 1.1 ป.5/1,5,บวกเศษส่วนตัวส่วนเท่ากัน',
            'ค,ค 1.1 ป.5/1 ข,ค 1.1 ป.5/1,5,บวกเศษส่วนตัวส่วนไม่เท่ากัน',
        ]));

        $this->artisan('eduvision:import-skills', ['csv' => $path, '--school' => (string) $school->id])
            ->expectsOutputToContain("for school {$school->id}")
            ->assertSuccessful();

        $parent = Skill::query()->where('code', 'ค 1.1 ป.5/1')->whereNull('school_id')->firstOrFail();
        $sub = Skill::query()->where('code', 'ค 1.1 ป.5/1 ก')->firstOrFail();
        $this->assertSame($school->id, $sub->school_id);
        $this->assertSame($parent->id, $sub->parent_id);
        $this->assertSame(15, Skill::query()->count());

        // Unknown school id is refused before anything is written.
        $this->artisan('eduvision:import-skills', ['csv' => $path, '--school' => '9999'])->assertFailed();
    }

    public function test_the_importer_tolerates_a_bom_with_a_warning_and_enforces_uniqueness_per_school(): void
    {
        $importer = app(SkillCsvImporter::class);
        $csv = "\xEF\xBB\xBFsubject_code,skill_code,parent_code,grade_level,name\nค,ค 1.1 ป.5/1,,5,ชื่อแรก\n";

        $result = $importer->importString($csv);
        $this->assertSame(1, $result->created);
        $this->assertNotEmpty($result->warnings);

        // Same code, different school: allowed (DESIGN §8.2 uniqueness is per school_id).
        $school = School::factory()->create();
        $importer->importString("subject_code,skill_code,parent_code,grade_level,name\nค,ค 1.1 ป.5/1,,5,ของโรงเรียน\n", $school->id);
        $this->assertSame(2, Skill::query()->where('code', 'ค 1.1 ป.5/1')->count());

        // Same code, same school: updated, not duplicated.
        $importer->importString("subject_code,skill_code,parent_code,grade_level,name\nค,ค 1.1 ป.5/1,,5,ชื่อใหม่\n");
        $this->assertSame(2, Skill::query()->where('code', 'ค 1.1 ป.5/1')->count());
        $this->assertSame('ชื่อใหม่', Skill::query()->where('code', 'ค 1.1 ป.5/1')->whereNull('school_id')->value('name'));

        $this->expectException(SkillImportException::class);
        $importer->importString("wrong,header\n");
    }

    private function tmpCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'skills').'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
