<?php

namespace Tests\Feature\Students;

use App\Models\Classroom;
use App\Models\ClassroomStudent;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "นำนักเรียนจากห้องเดิม" (DESIGN §24.6, §24.16 build 4):
 * POST /classrooms/{id}/students/from-classroom.
 */
class StudentsFromClassroomTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $lastYear;

    private Classroom $newRoom;

    /** @var array<int, User> by number in last year's classroom */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        // Last year's classroom belongs to another teacher and is closed already.
        $this->lastYear = $this->makeClassroom($this->makeTeacher($this->teacher->school), ['name' => 'ป.4/1', 'academic_year' => 2568]);
        foreach ([3 => 'ด.ญ. ขวัญ ใจดี', 7 => 'ด.ช. กล้า หาญ', 9 => 'ด.ช. อนุ ชา'] as $n => $name) {
            $this->students[$n] = $this->enrollStudent($this->lastYear, $n, $name)['student'];
        }
        $this->lastYear->forceFill(['closed_at' => now()])->save();
        $this->newRoom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'academic_year' => 2569]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function copy(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->teacher)->postJson("/api/v1/classrooms/{$this->newRoom->id}/students/from-classroom", [
            'source_classroom_id' => $this->lastYear->id,
            'student_ids' => array_map(fn (User $s) => $s->id, array_values($this->students)),
            'numbering' => 'keep',
            'pin' => 'keep',
            ...$overrides,
        ]);
    }

    /** @return array<int, int> student id => number in the new classroom */
    private function numbers(): array
    {
        return ClassroomStudent::query()->where('classroom_id', $this->newRoom->id)->pluck('student_number', 'student_id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])->all();
    }

    public function test_keep_numbers_and_pins_enrols_the_same_accounts(): void
    {
        $pins = StudentCredential::query()->pluck('pin_hash', 'student_id')->all();
        $users = User::query()->count();

        $res = $this->copy()->assertCreated();

        $this->assertSame([3, 7, 9], array_column($res->json('data.enrolled'), 'student_number'));
        $this->assertSame([null, null, null], array_column($res->json('data.enrolled'), 'pin'));
        $this->assertSame([true, true, true], array_column($res->json('data.enrolled'), 'existing'));
        $this->assertSame([], $res->json('data.skipped'));
        $this->assertSame($users, User::query()->count(), 'no new account');
        $this->assertSame($pins, StudentCredential::query()->pluck('pin_hash', 'student_id')->all(), 'the PINs stay');
        $this->assertSame([$this->students[3]->id => 3, $this->students[7]->id => 7, $this->students[9]->id => 9], $this->numbers());
    }

    public function test_sorted_numbers_and_new_pins(): void
    {
        $token = $this->tokenFor($this->students[7]);
        // Somebody holds number 1 already: the sorted numbers follow the highest.
        $this->enrollStudent($this->newRoom, 1, 'ด.ช. มีอยู่ แล้ว');

        $res = $this->copy(['numbering' => 'sorted', 'pin' => 'new'])->assertCreated();

        // ThaiNameSorter: กล้า, ขวัญ, อนุ (titles ignored).
        $this->assertSame([[$this->students[7]->id, 2], [$this->students[3]->id, 3], [$this->students[9]->id, 4]], array_map(fn ($r) => [$r['student_id'], $r['student_number']], $res->json('data.enrolled')));
        foreach ($res->json('data.enrolled') as $row) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $row['pin']);
        }
        // A new PIN signs the student out everywhere (§7.4).
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_kept_number_already_taken_goes_after_the_highest(): void
    {
        $this->enrollStudent($this->newRoom, 7, 'ด.ช. เจ็ด เดิม');

        $this->copy()->assertCreated();

        $numbers = $this->numbers();
        $this->assertSame([3, 10, 9], [$numbers[$this->students[3]->id], $numbers[$this->students[7]->id], $numbers[$this->students[9]->id]]);
    }

    public function test_students_already_here_or_no_longer_active_are_skipped(): void
    {
        $this->newRoom->students()->attach($this->students[3]->id, ['student_number' => 1]);
        $this->students[9]->forceFill(['status' => User::STATUS_DISABLED])->save();

        $res = $this->copy()->assertCreated();

        $this->assertSame([$this->students[7]->id], array_column($res->json('data.enrolled'), 'student_id'));
        $this->assertSame([
            ['student_id' => $this->students[3]->id, 'name' => 'ด.ญ. ขวัญ ใจดี', 'reason' => 'already_enrolled'],
            ['student_id' => $this->students[9]->id, 'name' => 'ด.ช. อนุ ชา', 'reason' => 'not_active'],
        ], $res->json('data.skipped'));
    }

    public function test_refusals(): void
    {
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher), 1)['student'];
        $this->copy(['student_ids' => [$this->students[3]->id, $outsider->id]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['student_ids.1']]);
        $this->copy(['source_classroom_id' => $this->newRoom->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['source_classroom_id']]);
        $this->copy(['numbering' => 'random'])->assertStatus(422)->assertJsonStructure(['errors' => ['numbering']]);
        // A classroom of another school is not found.
        $this->copy(['source_classroom_id' => $this->makeClassroom($this->makeTeacher())->id])->assertNotFound();
        $this->assertSame([], $this->numbers(), 'nothing was enrolled');

        // Only the homeroom teacher of the target, and only while it is open.
        $subject = $this->makeTeacher($this->teacher->school);
        $this->makeCourse($subject, [$this->newRoom]);
        $this->copy([], $subject)->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');
        $this->copy([], $this->makeTeacher($this->teacher->school))->assertNotFound();
        $this->newRoom->forceFill(['closed_at' => now()])->save();
        $this->copy()->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
    }
}
