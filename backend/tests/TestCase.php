<?php

namespace Tests;

use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
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
     * Enrols one student the way POST /classrooms/{id}/students does and also
     * issues a QR token (returned in plain, like the card renderer would see it).
     *
     * @return array{student: User, pin: string, qr_token: string}
     */
    protected function enrollStudent(Classroom $classroom, int $number = 1, string $name = 'นักเรียนทดสอบ'): array
    {
        $created = app(StudentEnroller::class)->enroll($classroom, [['name' => $name, 'student_number' => $number]])[0];
        $qrToken = app(CredentialIssuer::class)->issueQrToken($created['student']);

        return ['student' => $created['student'], 'pin' => $created['pin'], 'qr_token' => $qrToken];
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
