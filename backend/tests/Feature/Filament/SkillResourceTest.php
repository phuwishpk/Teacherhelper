<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Skills\Pages\ManageSkills;
use App\Filament\Resources\Skills\SkillResource;
use App\Models\School;
use App\Models\Skill;
use App\Models\Subject;
use Database\Seeders\SkillSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §7.5 / §20.2: the curriculum is read-only in Filament and imported
 * from CSV; an admin may edit a school's own rows.
 */
class SkillResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());
    }

    public function test_skills_are_listed_read_only(): void
    {
        $subject = Subject::factory()->create(['code' => 'ค']);
        $skill = Skill::factory()->create(['subject_id' => $subject->id, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน', 'grade_level' => 5]);

        $this->get('/admin/skills')->assertOk();

        Livewire::test(ManageSkills::class)
            ->assertCanSeeTableRecords([$skill])
            ->assertSee('เศษส่วน')
            ->assertActionExists('import');

        $this->assertFalse(SkillResource::canCreate());
        $this->assertFalse(SkillResource::canEdit($skill));
        $this->assertFalse(SkillResource::canDelete($skill));
    }

    public function test_admin_imports_a_csv_from_the_skills_page(): void
    {
        $file = UploadedFile::fake()->createWithContent('skills.csv', file_get_contents(SkillSeeder::SAMPLE_CSV));

        Livewire::test(ManageSkills::class)
            ->callAction('import', data: ['csv' => $file, 'school_id' => null])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(13, Skill::query()->count());
        $this->assertSame(2, Subject::query()->count());
    }

    public function test_admin_imports_school_sub_skills_and_sees_errors_for_a_bad_file(): void
    {
        $this->artisan('eduvision:import-skills', ['csv' => SkillSeeder::SAMPLE_CSV])->assertSuccessful();
        $school = School::factory()->create();

        $good = UploadedFile::fake()->createWithContent('sub.csv', "subject_code,skill_code,parent_code,grade_level,name\nค,ค 1.1 ป.5/1 ก,ค 1.1 ป.5/1,5,ย่อย\n");
        Livewire::test(ManageSkills::class)
            ->callAction('import', data: ['csv' => $good, 'school_id' => $school->id])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('skills', ['code' => 'ค 1.1 ป.5/1 ก', 'school_id' => $school->id]);

        $bad = UploadedFile::fake()->createWithContent('bad.csv', "subject_code,skill_code,parent_code,grade_level,name\nค,,,,ไม่มีรหัส\n");
        Livewire::test(ManageSkills::class)
            ->callAction('import', data: ['csv' => $bad, 'school_id' => null])
            ->assertNotified();
        $this->assertSame(14, Skill::query()->count());
    }

    public function test_an_admin_edits_a_teacher_added_indicator_but_not_the_curriculum(): void
    {
        $subject = Subject::factory()->create(['code' => 'ค']);
        $curriculum = Skill::factory()->create(['subject_id' => $subject->id, 'code' => 'ค 1.1', 'level' => Skill::LEVEL_STANDARD]);
        $teacher = $this->makeTeacher();
        $added = Skill::factory()->create([
            'subject_id' => $subject->id, 'parent_id' => $curriculum->id, 'school_id' => $teacher->school_id,
            'code' => 'ค 1.1/ค1', 'name' => 'ครูตั้งชื่อ', 'source' => Skill::SOURCE_TEACHER, 'created_by' => $teacher->id,
        ]);

        $this->assertFalse(SkillResource::canEdit($curriculum));
        $this->assertTrue(SkillResource::canEdit($added));

        Livewire::test(ManageSkills::class)
            ->assertSee('ครูเพิ่มเอง')
            ->callTableAction('edit', $added, data: ['code' => 'ค 1.1/ค1', 'name' => 'admin แก้ชื่อ', 'grade_level' => 5])
            ->assertHasNoTableActionErrors();
        $this->assertSame('admin แก้ชื่อ', $added->refresh()->name);
    }

    public function test_an_import_with_bad_rows_reports_them_and_keeps_the_good_ones(): void
    {
        $file = UploadedFile::fake()->createWithContent('mixed.csv', "subject_code,level,code,parent_code,grade_level,name\nค,indicator,ค 1.1 ป.5/1,,5,ดี\nค,chapter,ค 9,,,ผิด\n");

        Livewire::test(ManageSkills::class)
            ->callAction('import', data: ['csv' => $file, 'school_id' => null])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(['ค 1.1 ป.5/1'], Skill::query()->pluck('code')->all());
    }
}
