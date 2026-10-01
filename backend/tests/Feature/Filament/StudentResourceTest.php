<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Students\Pages\DuplicateStudents;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Students\StudentMergeSupport;
use App\Models\Classroom;
use App\Models\StudentMerge;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §24.5, §24.13: an admin of the school finds students, compares two
 * accounts and merges them in Filament with the same StudentMerger as the app,
 * and reviews the likely duplicates.
 */
class StudentResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $room1;

    private Classroom $room2;

    private User $keep;

    private User $merge;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->teacher = $this->makeTeacher();
        $this->room1 = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->room2 = $this->makeClassroom($this->makeTeacher($this->teacher->school), ['name' => 'ป.6/1']);
        $this->keep = $this->enrollStudent($this->room1, 1, 'สมชาย ใจดี')['student'];
        $this->merge = $this->enrollStudent($this->room2, 7, 'ด.ช.สมชาย ใจดี')['student'];
        $this->admin = $this->makeAdmin(['school_id' => $this->teacher->school_id]);
        $this->actingAs($this->admin);
    }

    public function test_the_list_shows_only_students_of_the_admins_school(): void
    {
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1, 'โรงเรียนอื่น')['student'];

        $this->get('/admin/students')->assertOk();
        Livewire::test(ManageStudents::class)
            ->assertCanSeeTableRecords([$this->keep, $this->merge])
            ->assertCanNotSeeTableRecords([$elsewhere, $this->teacher, $this->admin])
            ->searchTable('ใจดี')
            ->assertCanSeeTableRecords([$this->keep, $this->merge]);
    }

    public function test_admin_merges_two_accounts_after_the_comparison(): void
    {
        $this->assertStringContainsString('เลือกบัญชีที่จะรวม', $this->previewHtml($this->keep, null));
        $html = $this->previewHtml($this->keep, $this->merge);
        $this->assertStringContainsString('ถูกรวม: ด.ช.สมชาย ใจดี', $html);
        $this->assertStringContainsString('ป.6/1 (', $html);
        $this->assertStringContainsString('ย้อนกลับไม่ได้', $html);

        Livewire::test(ManageStudents::class)
            ->mountAction(TestAction::make('merge')->table($this->keep))
            ->fillForm(['merge_id' => $this->merge->id])
            ->callMountedAction()
            ->assertHasActionErrors(['confirm']);
        $this->assertNull($this->merge->refresh()->merged_into_id);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('merge')->table($this->keep), ['merge_id' => $this->merge->id, 'confirm' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('รวมบัญชี ด.ช.สมชาย ใจดี เข้า สมชาย ใจดี แล้ว');

        $this->merge->refresh();
        $this->assertSame($this->keep->id, $this->merge->merged_into_id);
        $this->assertSame('disabled', $this->merge->status);
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->room2->id, 'student_id' => $this->keep->id, 'student_number' => 7]);
        $this->assertSame($this->admin->id, StudentMerge::query()->sole()->merged_by);

        Livewire::test(ManageStudents::class)
            ->assertSee('ถูกรวมเข้า สมชาย ใจดี')
            ->assertTableActionHidden('merge', $this->merge);
    }

    public function test_a_conflict_keeps_the_modal_open_and_writes_nothing(): void
    {
        $this->keep->forceFill(['student_code' => '6601'])->save();
        $this->merge->forceFill(['student_code' => '6602'])->save();

        $html = $this->previewHtml($this->keep, $this->merge);
        $this->assertStringContainsString('รวมไม่ได้', $html);
        $this->assertStringContainsString('เลขประจำตัวต่างกัน', $html);

        Livewire::test(ManageStudents::class)
            ->callAction(TestAction::make('merge')->table($this->keep), ['merge_id' => $this->merge->id, 'confirm' => true])
            ->assertNotified('รวมบัญชีไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน');

        $this->assertNull($this->merge->refresh()->merged_into_id);
        $this->assertSame(0, StudentMerge::query()->count());
    }

    public function test_an_admin_of_another_school_cannot_merge(): void
    {
        $this->actingAs($this->makeAdmin(['school_id' => $this->makeSchool()->id]));

        Livewire::test(ManageStudents::class)->assertCanNotSeeTableRecords([$this->keep]);
        $this->assertFalse(auth()->user()->can('mergeStudents', [$this->keep, $this->merge]));
    }

    public function test_the_duplicates_page_lists_pairs_and_merges_either_way(): void
    {
        DB::table('classroom_students')->where('student_id', $this->keep->id)->update(['google_email' => 'som@school.ac.th']);
        DB::table('classroom_students')->where('student_id', $this->merge->id)->update(['google_email' => 'SOM@school.ac.th']);

        $this->get('/admin/students/duplicates')->assertOk()->assertSee('อีเมล Google เดียวกัน');

        Livewire::test(DuplicateStudents::class)
            ->assertSee('ด.ช.สมชาย ใจดี')
            ->assertSee('ชื่อเดียวกัน')
            ->callAction(TestAction::make('merge')->arguments(['keep' => $this->merge->id, 'merge' => $this->keep->id]), ['confirm' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('รวมบัญชี สมชาย ใจดี เข้า ด.ช.สมชาย ใจดี แล้ว');

        $this->assertSame($this->merge->id, $this->keep->refresh()->merged_into_id);
        Livewire::test(DuplicateStudents::class)->assertSee('ไม่พบบัญชีที่อาจซ้ำ');
    }

    public function test_a_system_admin_picks_the_school_on_the_duplicates_page(): void
    {
        $this->actingAs($this->makeAdmin());
        $otherSchool = $this->makeSchool(['name' => 'ฮฮฮ โรงเรียนท้ายรายการ']);

        Livewire::test(DuplicateStudents::class)
            ->set('schoolId', $this->teacher->school_id)
            ->assertSee('ด.ช.สมชาย ใจดี')
            ->set('schoolId', $otherSchool->id)
            ->assertSee('ไม่พบบัญชีที่อาจซ้ำ');
    }

    public function test_the_duplicates_page_refuses_a_pair_outside_the_admins_school(): void
    {
        $elsewhere = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1, 'สมชาย ใจดี')['student'];

        Livewire::test(DuplicateStudents::class)
            ->assertActionHidden(TestAction::make('merge')->arguments(['keep' => $this->keep->id, 'merge' => $elsewhere->id]));
        $this->assertSame(['preview' => null, 'error' => 'รวมได้เฉพาะบัญชีของโรงเรียนเดียวกัน'], StudentMergeSupport::previewData($this->keep, $elsewhere));

        $this->assertNull($elsewhere->refresh()->merged_into_id);
        $this->assertSame(0, StudentMerge::query()->count());
    }

    private function previewHtml(User $keep, ?User $merge): string
    {
        return view('filament.student-merge-preview', StudentMergeSupport::previewData($keep, $merge))->render();
    }
}
