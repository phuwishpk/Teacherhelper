<?php

namespace Tests\Feature\Console;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Response;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_seeder_creates_an_active_admin_from_config(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => 'admin-secret-1']);

        $this->seed(AdminSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertNull($admin->school_id);
        $this->assertTrue(Hash::check('admin-secret-1', $admin->password));
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));

        // Re-seeding with a new password rotates it instead of failing on the unique email.
        config(['eduvision.admin_password' => 'rotated-secret-2']);
        $this->seed(AdminSeeder::class);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $this->assertTrue(Hash::check('rotated-secret-2', $admin->fresh()->password));
    }

    public function test_admin_seeder_does_nothing_without_credentials(): void
    {
        config(['eduvision.admin_email' => '', 'eduvision.admin_password' => '']);

        $this->seed(AdminSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_admin_seeder_warns_when_it_skips(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => '']);

        $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('ADMIN_EMAIL / ADMIN_PASSWORD are not set in .env, so no admin was created')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_database_seeder_runs_end_to_end(): void
    {
        config(['eduvision.admin_email' => 'admin@example.com', 'eduvision.admin_password' => 'admin-secret-1']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('schools', ['teacher_join_code' => config('eduvision.seed_teacher_join_code')]);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
        $this->assertSame(13, Skill::query()->count());
    }

    public function test_demo_seeder_creates_an_admin_a_teacher_and_a_class_of_students(): void
    {
        config([
            'eduvision.admin_email' => 'admin@example.com',
            'eduvision.admin_password' => 'admin-secret-1',
            'eduvision.demo.teacher_email' => 'teacher@example.com',
            'eduvision.demo.teacher_password' => 'teacher-secret-1',
            'eduvision.demo.student_pin' => '246810',
        ]);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('DemoSeeder: teacher teacher@example.com ready')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $teacher = User::query()->where('email', 'teacher@example.com')->firstOrFail();
        $this->assertSame('teacher', $teacher->role);
        $this->assertSame('active', $teacher->status);
        $classroom = Classroom::query()->where('teacher_id', $teacher->id)->firstOrFail();
        $this->assertSame($teacher->school_id, $classroom->school_id);
        $this->assertSame(count(DemoSeeder::STUDENTS), $classroom->students()->count());

        // The app's own logins work with the seeded values.
        $this->postJson('/api/v1/auth/teacher/login', ['email' => 'teacher@example.com', 'password' => 'teacher-secret-1'])
            ->assertOk();
        $this->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $classroom->class_code, 'student_number' => 3, 'pin' => '246810']))
            ->assertOk()
            ->assertJsonPath('user.name', DemoSeeder::STUDENTS[2]);

        // Re-seeding rotates the password and the PIN and adds nothing twice.
        config(['eduvision.demo.teacher_password' => 'teacher-secret-2', 'eduvision.demo.student_pin' => '135790']);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, Classroom::query()->count());
        $this->assertSame(count(DemoSeeder::STUDENTS), User::query()->where('role', 'student')->count());
        $this->assertTrue(Hash::check('teacher-secret-2', $teacher->fresh()->password));
        $this->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $classroom->class_code, 'student_number' => 3, 'pin' => '246810']))
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $classroom->class_code, 'student_number' => 3, 'pin' => '135790']))
            ->assertOk();
    }

    public function test_demo_seeder_adds_a_course_with_results_a_review_queue_and_published_grades(): void
    {
        config([
            'eduvision.admin_email' => 'admin@example.com',
            'eduvision.admin_password' => 'admin-secret-1',
            'eduvision.demo.teacher_email' => 'teacher@example.com',
            'eduvision.demo.teacher_password' => 'teacher-secret-1',
            'eduvision.demo.student_pin' => '246810',
        ]);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('created with homework, an exam and published grades')
            ->assertSuccessful();

        $teacher = User::query()->where('email', 'teacher@example.com')->firstOrFail();
        $classroom = Classroom::query()->where('teacher_id', $teacher->id)->firstOrFail();
        $course = Course::query()->where('created_by', $teacher->id)->where('code', DemoSeeder::COURSE_CODE)->firstOrFail();
        $students = $classroom->students()->orderBy('classroom_students.student_number')->get();
        [$first, $second, $final] = Assignment::query()->where('course_id', $course->id)->orderBy('id')->get()->all();

        // Homework 1 is published for everybody and fed the skills.
        $this->assertSame(10, Submission::query()->where('assignment_id', $first->id)->where('status', 'published')->count());
        $this->assertGreaterThan(0, SkillObservation::query()->count());

        // The teacher's screens read the seeded rows through the real API.
        $this->asUser($teacher)->getJson("/api/v1/assignments/{$second->id}/review-queue")
            ->assertOk()->assertJsonPath('meta.counts.check', fn ($n) => $n > 0);
        $response = Response::query()->whereHas('submission', fn ($q) => $q->where('assignment_id', $second->id))->firstOrFail();
        $this->asUser($teacher)->getJson("/api/v1/responses/{$response->id}")->assertOk();
        $grid = $this->asUser($teacher)->getJson("/api/v1/courses/{$course->id}/gradebook?classroom_id={$classroom->id}")->assertOk()->json('data');
        $this->assertCount(10, $grid['rows']);
        $this->assertTrue($final->isManualExam());

        // Student 1 (full marks everywhere, 28/30 in the final) sees the result and grade 4.
        $submission = Submission::query()->where('assignment_id', $first->id)->where('student_id', $students[0]->id)->firstOrFail();
        $this->asUser($students[0])->getJson("/api/v1/student/results/{$submission->id}")
            ->assertOk()->assertJsonPath('data.total_score', fn ($v) => (float) $v === 10.0);
        $this->asUser($students[0])->getJson('/api/v1/student/grades')
            ->assertOk()->assertJsonPath('data.0.grade', fn ($g) => (float) $g === 4.0);

        // A second run leaves the content alone.
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('already there, content left as it is')
            ->assertSuccessful();
        $this->assertSame(3, Assignment::query()->count());
        $this->assertSame(20, Submission::query()->count());
    }

    public function test_demo_seeder_creates_no_teacher_or_student_without_its_settings(): void
    {
        config([
            'eduvision.admin_email' => '',
            'eduvision.admin_password' => '',
            'eduvision.demo.teacher_email' => 'teacher@example.com',
            'eduvision.demo.teacher_password' => 'teacher-secret-1',
            'eduvision.demo.student_pin' => '12345', // not 6 digits
        ]);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--no-interaction' => true])
            ->expectsOutputToContain('so no demo teacher or student was created')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Classroom::query()->count());
    }
}
