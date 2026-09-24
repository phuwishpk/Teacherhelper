<?php

namespace Tests\Feature\Api;

use App\Domain\Students\CredentialIssuer;
use App\Http\Requests\Api\V1\BulkStoreStudentsRequest;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DESIGN §9.2: POST /classrooms/{id}/students (bulk) and GET /classrooms/{id}/roster.
 */
class ClassroomStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_add_creates_student_users_with_credentials_and_returns_each_pin_once(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);

        $response = $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", [
            'students' => [
                ['name' => 'เด็กหญิงหนึ่ง', 'student_number' => 1],
                ['name' => 'เด็กชายสอง', 'student_number' => 2],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'เด็กหญิงหนึ่ง')
            ->assertJsonPath('data.0.student_number', 1)
            ->assertJsonPath('data.1.student_number', 2)
            ->assertJsonStructure(['data' => [['student_id', 'student_number', 'name', 'status', 'pin']]]);

        foreach ($response->json('data') as $row) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $row['pin']);
            $this->assertDatabaseHas('users', [
                'id' => $row['student_id'],
                'role' => 'student',
                'school_id' => $teacher->school_id,
                'email' => null,
                'password' => null,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('classroom_students', [
                'classroom_id' => $classroom->id,
                'student_id' => $row['student_id'],
                'student_number' => $row['student_number'],
            ]);
            $this->assertDatabaseHas('student_credentials', ['student_id' => $row['student_id'], 'failed_pin_attempts' => 0]);
        }

        // The PIN never appears again: the roster has no pin field.
        $this->asUser($teacher)->getJson("/api/v1/classrooms/{$classroom->id}/roster")
            ->assertOk()
            ->assertJsonMissingPath('data.0.pin');
    }

    public function test_bulk_add_validates_rows_and_rejects_numbers_already_in_the_classroom(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $this->enrollStudent($classroom, 3);

        $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", ['students' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['students']);

        $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", [
            'students' => [['name' => '', 'student_number' => 0], ['name' => 'ซ้ำ', 'student_number' => 5], ['name' => 'ซ้ำ2', 'student_number' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['students.0.name', 'students.0.student_number', 'students.1.student_number']);

        $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", [
            'students' => [['name' => 'ใหม่', 'student_number' => 4], ['name' => 'ชนกับเลข 3', 'student_number' => 3]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_number_taken');

        // Nothing partial was written.
        $this->assertSame(1, $classroom->students()->count());
        $this->assertSame(1, User::query()->where('role', 'student')->count());
    }

    public function test_pins_are_hashed_with_a_low_bcrypt_cost_so_a_full_payload_enrols_within_the_app_timeout(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $rows = [];
        for ($n = 1; $n <= BulkStoreStudentsRequest::MAX_ROWS; $n++) {
            $rows[] = ['name' => 'นักเรียน '.$n, 'student_number' => $n];
        }

        $started = hrtime(true);
        $response = $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", ['students' => $rows])
            ->assertCreated()
            ->assertJsonCount(BulkStoreStudentsRequest::MAX_ROWS, 'data');
        $elapsed = (hrtime(true) - $started) / 1e9;

        // The app gives up after 20 s (receiveTimeout); cost-12 hashing needed ~27 s for 100 rows.
        $this->assertLessThan(10, $elapsed, sprintf('%d-row enrolment took %.1f s', BulkStoreStudentsRequest::MAX_ROWS, $elapsed));

        $first = $response->json('data.0');
        $hash = StudentCredential::query()->findOrFail($first['student_id'])->pin_hash;
        $info = password_get_info($hash);
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(CredentialIssuer::PIN_HASH_ROUNDS, $info['options']['cost']);
        $this->assertTrue(Hash::check($first['pin'], $hash));
    }

    public function test_a_number_taken_between_the_check_and_the_insert_is_still_a_422(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $rival = User::factory()->student($teacher->school)->create();

        // Simulates a concurrent bulk-add: number 5 lands after enroll()'s own
        // check ran and before its attach(), so uq_class_number fires.
        User::creating(function (User $user) use ($classroom, $rival) {
            if ($user->role === User::ROLE_STUDENT && ! $classroom->students()->wherePivot('student_number', 5)->exists()) {
                $classroom->students()->attach($rival->id, ['student_number' => 5]);
            }
        });

        $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/students", [
            'students' => [['name' => 'ชนกัน', 'student_number' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'code'])
            ->assertJsonPath('code', 'student_number_taken');

        $this->assertDatabaseMissing('users', ['name' => 'ชนกัน']);
    }

    public function test_roster_is_ordered_by_student_number_and_scoped_to_the_owner(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $this->enrollStudent($classroom, 12, 'สิบสอง');
        $this->enrollStudent($classroom, 2, 'สอง');
        $this->enrollStudent($classroom, 7, 'เจ็ด');

        $this->asUser($teacher)->getJson("/api/v1/classrooms/{$classroom->id}/roster")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.student_number', 2)
            ->assertJsonPath('data.0.name', 'สอง')
            ->assertJsonPath('data.1.student_number', 7)
            ->assertJsonPath('data.2.student_number', 12)
            ->assertJsonStructure(['data' => [['student_id', 'student_number', 'name', 'status']]]);

        $this->asUser($this->makeTeacher($teacher->school))->getJson("/api/v1/classrooms/{$classroom->id}/roster")->assertNotFound();
        $this->asGuest()->getJson("/api/v1/classrooms/{$classroom->id}/roster")->assertUnauthorized();
    }
}
