<?php

namespace Tests\Feature\Students;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DESIGN §24.15 step 1: a student without school_id gets the school of their
 * classroom; one in classrooms of two schools is left alone (and logged).
 */
class SchoolStudentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_without_a_school_get_the_school_of_their_classroom(): void
    {
        $classroom = $this->makeClassroom($this->makeTeacher());
        $orphan = $this->enrollStudent($classroom, 1)['student'];
        $twoSchools = $this->enrollStudent($classroom, 2)['student'];
        $this->makeClassroom($this->makeTeacher())->students()->attach($twoSchools->id, ['student_number' => 1]);
        DB::table('users')->whereIn('id', [$orphan->id, $twoSchools->id])->update(['school_id' => null]);

        $migration = require database_path('migrations/2026_10_01_000005_add_school_wide_students.php');
        (new ReflectionMethod($migration, 'fillStudentSchools'))->invoke($migration);

        $this->assertSame($classroom->school_id, User::find($orphan->id)->school_id);
        $this->assertNull(User::find($twoSchools->id)->school_id);
    }

    public function test_the_migration_rolls_back_and_forward(): void
    {
        // Step 2: the build-2 migration (classroom_course_requests) runs after this one.
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('users', 'student_code'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('student_merges'));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('classrooms', 'closed_at'));
    }
}
