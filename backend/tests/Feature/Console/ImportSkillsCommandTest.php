<?php

namespace Tests\Feature\Console;

use App\Domain\Skills\SkillCsvImporter;
use App\Domain\Skills\SkillImportException;
use App\Models\School;
use App\Models\Skill;
use App\Models\Subject;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * eduvision:import-skills and the CSV importer behind it (DESIGN §2.3,
 * §8.2, §20.2, §20.10).
 */
class ImportSkillsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'subject_code,level,code,parent_code,grade_level,name';

    public function test_the_sample_csv_imports_with_levels_and_is_idempotent(): void
    {
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])
            ->expectsOutputToContain('Imported 13 skills (13 created, 0 updated, 0 unchanged), 2 new subjects')
            ->assertSuccessful();

        $this->assertSame(2, Subject::query()->count());
        $this->assertSame(13, Skill::query()->count());
        $this->assertDatabaseHas('subjects', ['code' => 'ค', 'name' => 'คณิตศาสตร์']);

        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $this->assertSame([Skill::LEVEL_STANDARD, null, null], [$standard->level, $standard->grade_level, $standard->parent_id]);
        $indicator = Skill::query()->where('code', 'ค 1.1 ป.5/1')->firstOrFail();
        $this->assertSame([$standard->id, 5, Skill::LEVEL_INDICATOR, Skill::SOURCE_CURRICULUM], [$indicator->parent_id, $indicator->grade_level, $indicator->level, $indicator->source]);
        $this->assertNull($indicator->school_id);

        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])
            ->expectsOutputToContain('(0 created, 0 updated, 13 unchanged)')
            ->assertSuccessful();
        $this->assertSame(13, Skill::query()->count());
    }

    public function test_the_documented_template_imports_cleanly(): void
    {
        $result = app(SkillCsvImporter::class)->importFile(base_path('../docs/curriculum/template.csv'));

        $this->assertSame([3, [], []], [$result->created, $result->errors, $result->warnings]);
        $this->assertSame(
            [Skill::LEVEL_STRAND, Skill::LEVEL_STANDARD, Skill::LEVEL_INDICATOR],
            Skill::query()->orderBy('id')->pluck('level')->all(),
        );
        $this->assertSame(Skill::query()->where('code', 'ค 1.1')->value('id'), Skill::query()->where('code', 'ค 1.1 ป.5/1')->value('parent_id'));
    }

    public function test_bad_rows_are_skipped_and_reported_by_line_while_the_rest_imports(): void
    {
        $path = $this->tmpCsv(implode("\n", [
            'subject_code,skill_code,parent_code,grade_level,name',
            'ค,ค 1.1 ป.5/1,,5,ถูกต้อง',
            'ค,,,,ไม่มีรหัส',
            'ค,ค 1.1 ป.5/3,,13,ชั้นผิด',
            'ค,ค 1.1 ป.5/1,,5,รหัสซ้ำ',
            'ค,ค 1.1 ป.5/4,,5',
        ]));

        $this->artisan('eduvision:import-skills', ['csv' => $path])
            ->expectsOutputToContain('Imported 1 skills (1 created')
            ->expectsOutputToContain('บรรทัด 3')
            ->expectsOutputToContain('บรรทัด 4')
            ->expectsOutputToContain('บรรทัด 5')
            ->expectsOutputToContain('บรรทัด 6')
            ->assertFailed();

        $this->assertSame(['ค 1.1 ป.5/1'], Skill::query()->pluck('code')->all());
        $this->assertSame('ถูกต้อง', Skill::query()->value('name'));

        $result = app(SkillCsvImporter::class)->importFile($path);
        $this->assertSame([3, 4, 5, 6], array_column($result->errors, 'line'));
        $this->assertSame(1, $result->unchanged);
        $this->assertStringContainsString('ซ้ำกับบรรทัด 2', $result->errorLines()[2]);
    }

    public function test_a_file_that_cannot_be_imported_at_all_writes_nothing(): void
    {
        $importer = app(SkillCsvImporter::class);
        foreach ([
            "subject_code,code,skill_code,name\nค,a,b,x\n" => 'มีทั้งคอลัมน์ code และ skill_code',
            "subject_code,code,grade_level\nค,a,5\n" => 'ไม่มีคอลัมน์ name',
            "wrong,header\n" => 'ไม่มีคอลัมน์ subject_code',
            self::HEADER."\n" => 'ไม่มีข้อมูล',
            '' => 'ไฟล์ว่าง',
        ] as $csv => $expected) {
            try {
                $importer->importString($csv);
                $this->fail('expected the file to be rejected: '.$expected);
            } catch (SkillImportException $e) {
                $this->assertStringContainsString($expected, implode(' ', $e->errors));
            }
        }
        $this->assertSame(0, Skill::query()->count());
        $this->assertSame(0, Subject::query()->count());

        $this->artisan('eduvision:import-skills', ['csv' => '/nonexistent.csv'])->assertFailed();
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV, '--school' => 'abc'])->assertExitCode(2);
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV, '--school' => '9999'])->assertFailed();
    }

    public function test_the_new_format_links_parents_listed_after_their_children_in_any_column_order(): void
    {
        $result = app(SkillCsvImporter::class)->importString(implode("\n", [
            'name,code,level,parent_code,grade_level,subject_code',
            'แสดงวิธีหาคำตอบ,ค 1.1 ป.5/1,indicator,ค 1.1,5,ค',
            'หาผลบวกย่อย,ค 1.1 ป.5/1 ก,sub_indicator,ค 1.1 ป.5/1,5,ค',
            '"เข้าใจความหลากหลายของการแสดงจำนวน, ระบบจำนวน",ค 1.1,STANDARD,ค 1,,ค',
            'จำนวนและพีชคณิต,ค 1,strand,,,ค',
        ]));

        $this->assertSame([4, 0, 0], [$result->created, $result->updated, $result->unchanged]);
        $this->assertSame([], $result->errors);
        $strand = Skill::query()->where('code', 'ค 1')->firstOrFail();
        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $indicator = Skill::query()->where('code', 'ค 1.1 ป.5/1')->firstOrFail();
        $sub = Skill::query()->where('code', 'ค 1.1 ป.5/1 ก')->firstOrFail();
        $this->assertSame([Skill::LEVEL_STRAND, null], [$strand->level, $strand->parent_id]);
        $this->assertSame([Skill::LEVEL_STANDARD, $strand->id], [$standard->level, $standard->parent_id]);
        $this->assertSame('เข้าใจความหลากหลายของการแสดงจำนวน, ระบบจำนวน', $standard->name);
        $this->assertSame([Skill::LEVEL_INDICATOR, $standard->id], [$indicator->level, $indicator->parent_id]);
        $this->assertSame([Skill::LEVEL_SUB_INDICATOR, $indicator->id], [$sub->level, $sub->parent_id]);
    }

    public function test_level_parent_and_cycle_problems_are_reported_per_line(): void
    {
        $result = app(SkillCsvImporter::class)->importString(implode("\n", [
            self::HEADER,
            'ค,strand,ค 1,ค 9,,สาระมี parent',
            'ค,chapter,ค 2,,,ระดับผิด',
            'ค,indicator,ค 1.1 ป.5/1,ไม่มีตัวนี้,5,parent หาย',
            'ค,standard,ก,ข,,วน ก',
            'ค,standard,ข,ก,,วน ข',
        ]));

        $this->assertSame(3, $result->created);
        $byLine = [];
        foreach ($result->errors as $error) {
            $byLine[$error['line']] = $error['message'];
        }
        $this->assertStringContainsString('strand', $byLine[2]);
        $this->assertStringContainsString('level ต้องเป็น', $byLine[3]);
        $this->assertStringContainsString('ไม่พบ parent_code "ไม่มีตัวนี้"', $byLine[4]);
        $this->assertStringContainsString('วนกลับ', $byLine[5]);
        // The row with a missing parent is still saved, without a parent.
        $this->assertNull(Skill::query()->where('code', 'ค 1.1 ป.5/1')->value('parent_id'));
        // The first row of the cycle is refused, the other keeps its parent.
        $a = Skill::query()->where('code', 'ก')->firstOrFail();
        $b = Skill::query()->where('code', 'ข')->firstOrFail();
        $this->assertSame([null, $a->id], [$a->parent_id, $b->parent_id]);
    }

    public function test_re_importing_updates_changed_rows_and_never_deletes_missing_ones(): void
    {
        $importer = app(SkillCsvImporter::class);
        $importer->importString(implode("\n", [
            self::HEADER,
            'ค,standard,ค 1.1,,,มาตรฐานเดิม',
            'ค,standard,ค 1.2,,,มาตรฐานสอง',
            'ค,indicator,ค 1.1 ป.5/1,ค 1.1,5,ชื่อเดิม',
            'ค,indicator,ค 1.1 ป.5/2,ค 1.1,5,ตัวที่จะหายจากไฟล์',
        ]));

        $result = $importer->importString(implode("\n", [
            self::HEADER,
            'ค,standard,ค 1.1,,,มาตรฐานเดิม',
            'ค,standard,ค 1.2,,,มาตรฐานสอง',
            'ค,indicator,ค 1.1 ป.5/1,ค 1.2,5,ชื่อเดิม',
            'ค,indicator,ค 1.1 ป.5/3,ค 1.1,5,ตัวใหม่',
        ]));

        $this->assertSame([1, 1, 2], [$result->created, $result->updated, $result->unchanged]);
        $this->assertSame(Skill::query()->where('code', 'ค 1.2')->value('id'), Skill::query()->where('code', 'ค 1.1 ป.5/1')->value('parent_id'));
        $this->assertDatabaseHas('skills', ['code' => 'ค 1.1 ป.5/2', 'name' => 'ตัวที่จะหายจากไฟล์']);
        $this->assertSame(5, Skill::query()->count());
    }

    public function test_school_rows_hang_under_curriculum_indicators_in_the_earlier_format(): void
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
        $this->assertSame([$school->id, $parent->id], [$sub->school_id, $sub->parent_id]);
        $this->assertSame([Skill::LEVEL_SUB_INDICATOR, Skill::SOURCE_SCHOOL_ADMIN], [$sub->level, $sub->source]);
        $this->assertSame(15, Skill::query()->count());
    }

    public function test_the_importer_tolerates_a_bom_with_a_warning_and_keeps_codes_unique_per_school(): void
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
        $result = $importer->importString("subject_code,skill_code,parent_code,grade_level,name\nค,ค 1.1 ป.5/1,,5,ชื่อใหม่\n");
        $this->assertSame(1, $result->updated);
        $this->assertSame(2, Skill::query()->where('code', 'ค 1.1 ป.5/1')->count());
        $this->assertSame('ชื่อใหม่', Skill::query()->where('code', 'ค 1.1 ป.5/1')->whereNull('school_id')->value('name'));
    }

    public function test_only_one_import_runs_at_a_time(): void
    {
        $lock = Cache::lock(SkillCsvImporter::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            app(SkillCsvImporter::class)->importFile(SkillSeeder::SAMPLE_CSV);
            $this->fail('a second import must wait for the first');
        } catch (SkillImportException $e) {
            $this->assertStringContainsString('กำลังทำอยู่', $e->errors[0]);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, Skill::query()->count());

        app(SkillCsvImporter::class)->importFile(SkillSeeder::SAMPLE_CSV);
        $this->assertSame(13, Skill::query()->count());
    }

    /**
     * DESIGN §20.10: a synthetic curriculum of 20,000 rows (8 subjects, 24
     * strands, 96 standards, the rest indicators in reverse order so every
     * parent comes after its children) imports in bounded time and upserts
     * without duplicating on a second run.
     */
    public function test_a_20000_row_file_imports_in_bounded_time_and_re_imports_as_unchanged(): void
    {
        $lines = [self::HEADER];
        $subjects = array_keys(SkillCsvImporter::SUBJECT_NAMES);
        $indicators = [];
        $n = 0;
        foreach ($subjects as $subject) {
            for ($strand = 1; $strand <= 3; $strand++) {
                for ($standard = 1; $standard <= 4; $standard++) {
                    for ($i = 1; $n < 20000 - 8 * 3 * 5 && $i <= 208; $i++, $n++) {
                        $grade = ($i % 12) + 1;
                        $indicators[] = "{$subject},indicator,{$subject} {$strand}.{$standard} ป.{$grade}/{$i},{$subject} {$strand}.{$standard},{$grade},ตัวชี้วัดสังเคราะห์ที่ {$i} ของมาตรฐาน {$strand}.{$standard}";
                    }
                }
            }
        }
        $lines = [...$lines, ...array_reverse($indicators)];
        foreach ($subjects as $subject) {
            for ($strand = 1; $strand <= 3; $strand++) {
                for ($standard = 1; $standard <= 4; $standard++) {
                    $lines[] = "{$subject},standard,{$subject} {$strand}.{$standard},{$subject} {$strand},,มาตรฐานสังเคราะห์ {$strand}.{$standard}";
                }
                $lines[] = "{$subject},strand,{$subject} {$strand},,,สาระสังเคราะห์ {$strand}";
            }
        }
        $rows = count($lines) - 1;
        $this->assertSame(20000, $rows);
        $path = $this->tmpCsv(implode("\n", $lines));

        $started = microtime(true);
        $result = app(SkillCsvImporter::class)->importFile($path);
        $seconds = microtime(true) - $started;

        $this->assertSame([$rows, 0, 0, []], [$result->created, $result->updated, $result->unchanged, $result->errors]);
        $this->assertLessThan(30.0, $seconds, "20,000 rows took {$seconds} s");
        $this->assertSame($rows, Skill::query()->count());
        $this->assertSame(0, Skill::query()->where('level', Skill::LEVEL_INDICATOR)->whereNull('parent_id')->count());
        $this->assertSame(8, Subject::query()->count());

        $again = app(SkillCsvImporter::class)->importFile($path);
        $this->assertSame([0, 0, $rows], [$again->created, $again->updated, $again->unchanged]);
        $this->assertSame($rows, Skill::query()->count());
    }

    private function tmpCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'skills').'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
