<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Schools\Pages\CreateSchool;
use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Models\School;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §7.5: schools and their teacher_join_code are managed in Filament.
 */
class SchoolResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());
    }

    public function test_schools_are_listed_with_their_join_code(): void
    {
        $school = School::factory()->create(['name' => 'โรงเรียนทดสอบ', 'teacher_join_code' => 'JOIN2569']);
        $this->makeTeacher($school);

        $this->get('/admin/schools')->assertOk();

        Livewire::test(ListSchools::class)
            ->assertCanSeeTableRecords([$school])
            ->assertSee('JOIN2569')
            ->assertTableColumnStateSet('teachers_count', 1, $school);
    }

    public function test_admin_creates_a_school_with_a_generated_join_code(): void
    {
        Livewire::test(CreateSchool::class)
            ->fillForm(['name' => 'โรงเรียนใหม่', 'teacher_join_code' => 'abcd2345', 'allow_training_data' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('schools', ['name' => 'โรงเรียนใหม่', 'teacher_join_code' => 'ABCD2345', 'allow_training_data' => true]);

        // Join codes are unique and exactly 8 characters.
        Livewire::test(CreateSchool::class)
            ->fillForm(['name' => 'ซ้ำ', 'teacher_join_code' => 'ABCD2345'])
            ->call('create')
            ->assertHasFormErrors(['teacher_join_code']);
        Livewire::test(CreateSchool::class)
            ->fillForm(['name' => 'สั้น', 'teacher_join_code' => 'ABC'])
            ->call('create')
            ->assertHasFormErrors(['teacher_join_code']);
    }

    public function test_admin_edits_retention_settings(): void
    {
        $school = School::factory()->create();

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->fillForm(['crop_retention_until' => '2027-03-31', 'allow_training_data' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $school->refresh();
        $this->assertSame('2027-03-31', $school->crop_retention_until?->toDateString());
        $this->assertTrue($school->allow_training_data);
    }
}
