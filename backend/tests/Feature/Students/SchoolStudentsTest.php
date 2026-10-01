<?php

namespace Tests\Feature\Students;

use App\Domain\Students\CredentialIssuer;
use App\Jobs\RenderLoginCardsJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\LoginCardPrint;
use App\Models\StudentCredential;
use App\Models\Submission;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §24.4: one student account per school. Enrolling new and existing
 * students (StudentEnroller through the API), one PIN across classrooms,
 * the school-wide search, editing a student, the roster number and removal,
 * cards for some students only, and the duplicate candidates.
 */
class SchoolStudentsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $room1;

    private Classroom $room2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น']);
        $this->room1 = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'academic_year' => 2569]);
        $this->room2 = $this->makeClassroom($this->teacher, ['name' => 'ชุมนุมคณิต', 'academic_year' => 2569]);
    }

    public function test_new_and_existing_students_in_one_request(): void
    {
        $existing = $this->enrollStudent($this->room1, 1, 'เด็กชายสมชาย ใจดี');
        $oldToken = $existing['student']->createToken('phone', ['student'])->plainTextToken;

        $response = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['student_id' => $existing['student']->id, 'student_number' => 7],
            ['name' => 'เด็กหญิงสมศรี ดีใจ', 'student_number' => 8, 'student_code' => ' ๑๒๓๔ ab '],
        ]])->assertCreated();

        $response->assertJsonPath('data.0.student_id', $existing['student']->id)
            ->assertJsonPath('data.0.existing', true)
            ->assertJsonPath('data.0.pin', null)
            ->assertJsonPath('data.1.existing', false)
            ->assertJsonPath('data.1.student_code', '1234AB');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('data.1.pin'));
        $this->assertSame($this->room2->school_id, User::find($response->json('data.1.student_id'))->school_id);

        // One credential: the old PIN and session still work, now through either classroom.
        $this->assertSame(1, StudentCredential::query()->where('student_id', $existing['student']->id)->count());
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $existing['student']->id]);
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->room2->id, 'student_id' => $existing['student']->id, 'student_number' => 7]);
        $this->assertNotEmpty($oldToken);
    }

    public function test_reissue_pin_gives_a_new_pin_and_ends_the_sessions(): void
    {
        $existing = $this->enrollStudent($this->room1, 1, 'สมชาย');
        $existing['student']->createToken('phone', ['student']);

        $pin = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['student_id' => $existing['student']->id, 'student_number' => 3, 'reissue_pin' => true],
        ]])->assertCreated()->json('data.0.pin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $existing['student']->id]);
        $this->asGuest()->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room1->class_code, 'student_number' => 1, 'pin' => $existing['pin']])->assertStatus(422);
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room2->class_code, 'student_number' => 3, 'pin' => $pin])->assertOk();
    }

    public function test_a_student_code_another_student_holds_names_them(): void
    {
        $holder = $this->enrollStudent($this->room1, 1, 'เจ้าของเลข')['student'];
        $holder->forceFill(['student_code' => '5001'])->save();

        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['name' => 'คนใหม่', 'student_number' => 1],
            ['name' => 'คนใหม่ซ้ำเลข', 'student_number' => 2, 'student_code' => '๕๐๐๑'],
        ]])->assertStatus(422)
            ->assertJsonPath('code', 'student_code_taken')
            ->assertJsonPath('existing_student.id', $holder->id)
            ->assertJsonPath('existing_student.name', 'เจ้าของเลข')
            ->assertJsonStructure(['errors' => ['students.1.student_code']]);
        $this->assertSame(0, $this->room2->students()->count(), 'all or nothing');

        // The same code twice in one payload, or a code with other characters.
        $this->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['name' => 'ก', 'student_number' => 1, 'student_code' => 'x1'],
            ['name' => 'ข', 'student_number' => 2, 'student_code' => 'X1'],
        ]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['name' => 'ก', 'student_number' => 1, 'student_code' => 'ห้า/1'],
        ]])->assertStatus(422)->assertJsonStructure(['errors' => ['students.0.student_code']]);
    }

    public function test_existing_students_must_be_active_students_of_the_school_not_yet_enrolled(): void
    {
        $here = $this->enrollStudent($this->room1, 1, 'อยู่แล้ว')['student'];
        $otherSchool = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];
        $merged = $this->enrollStudent($this->room1, 2)['student'];
        $merged->forceFill(['status' => 'disabled', 'merged_into_id' => $here->id])->save();
        $colleague = $this->makeTeacher($this->teacher->school);

        $this->asUser($this->teacher);
        foreach ([$otherSchool, $merged, $colleague] as $user) {
            $this->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [['student_id' => $user->id, 'student_number' => 1]]])
                ->assertStatus(422)
                ->assertJsonPath('code', 'student_not_in_school')
                ->assertJsonStructure(['errors' => ['students.0.student_id']]);
        }
        $this->postJson("/api/v1/classrooms/{$this->room1->id}/students", ['students' => [
            ['name' => 'คนใหม่', 'student_number' => 9],
            ['student_id' => $here->id, 'student_number' => 5],
        ]])->assertStatus(422)->assertJsonPath('code', 'already_enrolled')->assertJsonStructure(['errors' => ['students.1.student_id']]);
        $this->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['student_id' => $here->id, 'student_number' => 5, 'name' => 'ทั้งสองแบบ'],
        ]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(2, $this->room1->students()->count());
    }

    public function test_pin_login_through_either_classroom_is_one_account_with_one_lockout(): void
    {
        $student = $this->enrollStudent($this->room1, 4, 'สองห้อง');
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->room2->id}/students", ['students' => [
            ['student_id' => $student['student']->id, 'student_number' => 11],
        ]])->assertCreated();

        $this->asGuest();
        $a = $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room1->class_code, 'student_number' => 4, 'pin' => $student['pin']])->assertOk();
        $b = $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room2->class_code, 'student_number' => 11, 'pin' => $student['pin']])->assertOk();
        $this->assertSame($student['student']->id, $a->json('user.id'));
        $this->assertSame($student['student']->id, $b->json('user.id'));
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $student['student']->id)->count());

        // Wrong PINs through both classrooms count on the student, not on the classroom.
        $wrong = $student['pin'] === '000000' ? '111111' : '000000';
        for ($i = 0; $i < CredentialIssuer::MAX_FAILED_PIN_ATTEMPTS - 1; $i++) {
            $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room1->class_code, 'student_number' => 4, 'pin' => $wrong])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room2->class_code, 'student_number' => 11, 'pin' => $wrong])->assertStatus(423);
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => $this->room1->class_code, 'student_number' => 4, 'pin' => $student['pin']])->assertStatus(423);
    }

    public function test_the_school_wide_search(): void
    {
        $somchai = $this->enrollStudent($this->room1, 1, 'เด็กชายสมชาย ใจดี')['student'];
        $somchai->forceFill(['student_code' => '12345'])->save();
        $this->enrollStudent($this->room2, 1, 'สมศรี มีสุข');
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirRoom = $this->makeClassroom($colleague, ['name' => 'ป.6/2']);
        $theirStudent = $this->enrollStudent($theirRoom, 3, 'นายสมชาย ใจเย็น')['student'];
        $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1, 'สมชาย ต่างโรงเรียน');
        $merged = $this->enrollStudent($this->room1, 9, 'สมชาย ถูกรวม')['student'];
        $merged->forceFill(['status' => 'disabled', 'merged_into_id' => $somchai->id])->save();

        $data = $this->asUser($colleague)->getJson('/api/v1/school-students?q='.urlencode('สมชาย'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$somchai->id, $theirStudent->id], array_column($data, 'id'));
        $row = collect($data)->firstWhere('id', $somchai->id);
        $this->assertSame(['id', 'name', 'student_code', 'has_google', 'classrooms'], array_keys($row));
        $this->assertSame([['id' => $this->room1->id, 'name' => 'ป.5/1', 'academic_year' => 2569, 'student_number' => 1, 'closed' => false]], $row['classrooms']);
        $this->assertStringNotContainsString('pin', strtolower(json_encode($data)));

        // Words in any order, titles ignored; or the student code by prefix.
        $this->getJson('/api/v1/school-students?q='.urlencode('ด.ช. ใจดี สมชาย'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $somchai->id);
        $this->getJson('/api/v1/school-students?q=123')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_code', '12345');
        $this->getJson('/api/v1/school-students?q=ส')->assertStatus(422);
        $this->getJson('/api/v1/school-students')->assertStatus(422);
    }

    public function test_the_editor_changes_name_and_code(): void
    {
        $student = $this->enrollStudent($this->room1, 1, 'ชื่อเดิม')['student'];
        $other = $this->enrollStudent($this->room1, 2, 'อีกคน')['student'];
        $other->forceFill(['student_code' => '777'])->save();

        $this->asUser($this->teacher)->patchJson("/api/v1/students/{$student->id}", ['name' => 'ชื่อใหม่', 'student_code' => 'a-1'])
            ->assertOk()->assertJsonPath('data.name', 'ชื่อใหม่')->assertJsonPath('data.student_code', 'A-1');
        $this->patchJson("/api/v1/students/{$student->id}", ['student_code' => '๗๗๗'])
            ->assertStatus(422)->assertJsonPath('code', 'student_code_taken')->assertJsonPath('existing_student.id', $other->id);
        $this->patchJson("/api/v1/students/{$student->id}", ['student_code' => ''])->assertOk()->assertJsonPath('data.student_code', null);

        // A colleague of the school sees the student but may not edit; another school does not see them.
        $this->asUser($this->makeTeacher($this->teacher->school))->patchJson("/api/v1/students/{$student->id}", ['name' => 'x'])
            ->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($this->makeTeacher())->patchJson("/api/v1/students/{$student->id}", ['name' => 'x'])->assertNotFound();

        // Only through an open classroom: once it is closed the teacher no longer edits (§24.2).
        $this->room1->forceFill(['closed_at' => now()])->save();
        $this->asUser($this->teacher)->patchJson("/api/v1/students/{$student->id}", ['name' => 'x'])->assertStatus(403);
        $this->postJson("/api/v1/students/{$student->id}/pin")->assertStatus(403);
    }

    public function test_a_code_taken_at_the_same_moment_answers_422_not_500(): void
    {
        $student = $this->enrollStudent($this->room1, 1, 'คนแก้')['student'];
        $other = $this->enrollStudent($this->room1, 2, 'คนที่ได้ก่อน')['student'];
        // The other request saves the same code between the check and this save. (Its write
        // is rolled back with this request's savepoint in the test, so the answer cannot
        // name the holder here; on a real race it does.)
        $raced = false;
        User::updating(function (User $user) use ($other, &$raced) {
            if (! $raced && $user->student_code === '555') {
                $raced = true;
                DB::table('users')->where('id', $other->id)->update(['student_code' => '555']);
            }
        });

        $this->asUser($this->teacher)->patchJson("/api/v1/students/{$student->id}", ['student_code' => '555'])
            ->assertStatus(422)->assertJsonPath('code', 'student_code_taken');
        $this->assertTrue($raced);
        $this->assertNull($student->refresh()->student_code);
    }

    public function test_the_roster_number_changes_and_a_student_leaves_while_they_have_no_work(): void
    {
        $student = $this->enrollStudent($this->room1, 1, 'ก')['student'];
        $this->enrollStudent($this->room1, 2, 'ข');

        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}", ['student_number' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'student_number_taken');
        $this->patchJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}", ['student_number' => 15])
            ->assertOk()->assertJsonPath('data.student_number', 15)->assertJsonPath('data.student_id', $student->id);

        $assignment = Assignment::factory()->for_classroom($this->room1)->create();
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'status' => Submission::STATUS_AWAITING_SCAN]);
        $this->deleteJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}")->assertStatus(409)->assertJsonPath('code', 'student_has_data');
        $submission->delete();
        GradebookEntry::create(['classroom_id' => $this->room1->id, 'student_id' => $student->id, 'assignment_id' => $assignment->id, 'score' => 5, 'updated_by' => $this->teacher->id]);
        $this->deleteJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}")->assertStatus(409);
        GradebookEntry::query()->update(['score' => null]);

        $this->deleteJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}")->assertNoContent();
        $this->assertDatabaseMissing('classroom_students', ['classroom_id' => $this->room1->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'active']);
        $this->deleteJson("/api/v1/classrooms/{$this->room1->id}/students/{$student->id}")->assertNotFound();
    }

    public function test_login_cards_for_some_students_only(): void
    {
        $a = $this->enrollStudent($this->room1, 1, 'ก')['student'];
        $b = $this->enrollStudent($this->room1, 2, 'ข')['student'];
        $outsider = $this->enrollStudent($this->room2, 1, 'ค')['student'];
        $tokenB = DB::table('student_credentials')->where('student_id', $b->id)->value('qr_token_hash');

        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->room1->id}/login-cards", ['student_ids' => [$outsider->id]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['student_ids.0']]);

        Queue::fake();
        $this->postJson("/api/v1/classrooms/{$this->room1->id}/login-cards", ['student_ids' => [$a->id]])->assertStatus(202);
        Queue::assertPushed(RenderLoginCardsJob::class, fn (RenderLoginCardsJob $job) => $job->studentIds === [$a->id]);
        Queue::assertPushed(RenderLoginCardsJob::class, 1);

        // Rendered: only A's card (and QR token) changes.
        $hashA = DB::table('student_credentials')->where('student_id', $a->id)->value('qr_token_hash');
        $print = LoginCardPrint::query()->latest('id')->firstOrFail();
        app()->call([new RenderLoginCardsJob($print->id, [$a->id]), 'handle']);
        $this->assertNotSame($hashA, DB::table('student_credentials')->where('student_id', $a->id)->value('qr_token_hash'));
        $this->assertSame($tokenB, DB::table('student_credentials')->where('student_id', $b->id)->value('qr_token_hash'));
    }

    public function test_duplicate_candidates_of_the_homeroom_teacher(): void
    {
        $a = $this->enrollStudent($this->room1, 1, 'เด็กชายสมชาย ใจดี')['student'];
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirRoom = $this->makeClassroom($colleague);
        $b = $this->enrollStudent($theirRoom, 1, 'ด.ช. สมชาย  ใจดี')['student'];
        $c = $this->enrollStudent($this->room2, 1, 'Somchai Jaidee')['student'];
        $d = $this->enrollStudent($theirRoom, 2, 'ใครก็ไม่รู้')['student'];
        DB::table('classroom_students')->where('student_id', $c->id)->update(['google_user_id' => 'g-1', 'google_email' => 'Somchai@School.ac.th']);
        DB::table('classroom_students')->where('student_id', $d->id)->update(['google_user_id' => 'g-1', 'google_email' => 'somchai@school.ac.th']);
        // Two colleagues' students only: not shown to this teacher.
        $this->enrollStudent($theirRoom, 3, 'ซ้ำกัน');
        $this->enrollStudent($theirRoom, 4, 'ซ้ำกัน');

        $pairs = $this->asUser($this->teacher)->getJson('/api/v1/students/duplicate-candidates')->assertOk()->json('data');
        $byPair = collect($pairs)->mapWithKeys(fn ($p) => [$p['a']['id'].'-'.$p['b']['id'] => $p['reasons']]);
        $this->assertSame(['name'], $byPair[$a->id.'-'.$b->id]);
        $this->assertSame(['google_user', 'email'], $byPair[$c->id.'-'.$d->id]);
        $this->assertCount(2, $pairs);
        $this->assertSame(['id', 'name', 'student_code', 'has_google', 'classrooms'], array_keys($pairs[0]['a']));
        // has_google is a linked Google sign-in account (§24.9), not a Classroom roster match.
        $this->assertFalse(collect($pairs)->firstWhere('a.id', $c->id)['a']['has_google']);
        UserGoogleIdentity::create(['user_id' => $c->id, 'google_sub' => 'g-1', 'email' => 'somchai@school.ac.th', 'linked_via' => 'self', 'linked_at' => now()]);
        $pairs = $this->asUser($this->teacher)->getJson('/api/v1/students/duplicate-candidates')->assertOk()->json('data');
        $this->assertTrue(collect($pairs)->firstWhere('a.id', $c->id)['a']['has_google']);
    }
}
