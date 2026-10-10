<?php

namespace Tests;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * phpunit.xml points the config/routes/events caches at paths that never
     * exist, so an `artisan optimize` run beside the suite cannot swap the
     * test settings for the .env ones (real Gemini key, the developer's
     * database). Refuse to go on if that ever breaks: this runs before
     * RefreshDatabase touches any connection.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached() || $app->routesAreCached() || $app->eventsAreCached()) {
            throw new RuntimeException('The suite loaded a cached config/routes/events file ('.$app->getCachedConfigPath().'). Run `php artisan optimize:clear` and check APP_*_CACHE in phpunit.xml.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach Google (or anything else) over the network.
        Http::preventStrayRequests();
    }

    protected function makeSchool(array $attributes = []): School
    {
        return School::factory()->create($attributes);
    }

    /** An active teacher of the given (or a new) school. */
    protected function makeTeacher(?School $school = null, array $attributes = []): User
    {
        return User::factory()->teacher($school ?? $this->makeSchool())->create($attributes);
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function makeClassroom(User $teacher, array $attributes = []): Classroom
    {
        return Classroom::factory()->for_teacher($teacher)->create($attributes);
    }

    /**
     * A course (DESIGN §20.1) of the teacher, bound to the given classrooms.
     *
     * @param  list<Classroom>  $classrooms
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCourse(User $teacher, array $classrooms = [], array $attributes = []): Course
    {
        $course = Course::create($attributes + [
            'school_id' => $teacher->school_id,
            'created_by' => $teacher->id,
            'subject_id' => $attributes['subject_id'] ?? Subject::query()->firstOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์'])->id,
            'code' => 'ค15101',
            'name' => 'คณิตศาสตร์ 5',
            'grade_level' => 5,
            'semester' => 0,
            'academic_year' => 2569,
        ]);
        $course->classrooms()->sync(array_map(fn (Classroom $c) => $c->id, $classrooms));

        return $course;
    }

    /**
     * Enrols one student the way POST /classrooms/{id}/students does and also
     * issues a QR token (returned in plain, like the card renderer would see it).
     *
     * The student has already replaced the initial password (DESIGN §29.10),
     * like a pupil who uses the app: `pin` is that password.
     *
     * @return array{student: User, pin: string, qr_token: string, username: string}
     */
    protected function enrollStudent(Classroom $classroom, int $number = 1, string $name = 'นักเรียนทดสอบ'): array
    {
        $created = app(StudentEnroller::class)->enroll($classroom, [['name' => $name, 'student_number' => $number]])[0];
        $qrToken = app(CredentialIssuer::class)->issueQrToken($created['student']);

        // Never the initial password, and different for every student.
        $pin = (string) random_int(200000, 999999);
        $created['student']->credential()->update(['pin_hash' => CredentialIssuer::hashPin($pin), 'must_change_password' => false]);

        return ['student' => $created['student'], 'pin' => $pin, 'qr_token' => $qrToken, 'username' => (string) $created['student']->username];
    }

    /**
     * The body of a student sign-in (DESIGN §29.10) from the way tests name a
     * student: class code + student number + pin become that student's
     * username and password. An unknown student gets a username nobody has.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function cred(array $body): array
    {
        if (array_key_exists('pin', $body)) {
            $body['password'] = $body['pin'];
        }
        if (array_key_exists('class_code', $body) || array_key_exists('student_number', $body)) {
            $classroom = Classroom::query()->where('class_code', ClassCodeGenerator::normalize((string) ($body['class_code'] ?? '')))->first();
            $student = $classroom?->students()->wherePivot('student_number', (int) ($body['student_number'] ?? 0))->first();
            $body['username'] = $student === null ? 'nobody-here' : (string) $student->username;
        }
        unset($body['pin'], $body['class_code'], $body['student_number']);

        return $body;
    }

    /** A real Sanctum token (so revocation can be observed) for the given abilities. */
    protected function tokenFor(User $user, ?array $abilities = null): string
    {
        $abilities ??= [$user->role];

        return $user->createToken('test', $abilities, now()->addDay())->plainTextToken;
    }

    /** Each request re-checks its bearer token like a real client (see TeacherAuthTest). */
    protected function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function asUser(User $user, ?array $abilities = null): static
    {
        $this->forgetGuards();

        return $this->withToken($this->tokenFor($user, $abilities));
    }

    /** No bearer at all (withToken() would otherwise linger in the default headers). */
    protected function asGuest(): static
    {
        $this->forgetGuards();

        return $this->withoutToken();
    }
}
