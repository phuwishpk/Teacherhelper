<?php

namespace Tests\Feature\Classrooms;

use App\Domain\Auth\Google\GoogleCerts;
use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Auth\GoogleSignInFixtures;
use Tests\TestCase;

/**
 * DESIGN §29.13.3: a pupil joins a classroom with its class code (or the QR
 * of the join link), claims the seat the teacher made for them, and an
 * unknown Google account holding a code becomes a student at once.
 */
class ClassJoinTest extends TestCase
{
    use GoogleSignInFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher(null, ['name' => 'ครูสมศรี', 'school_name' => 'โรงเรียนบ้านหนองบัว']);
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'class_code' => 'ABC234']);
    }

    public function test_the_public_page_names_the_classroom_and_its_teacher_without_the_roster(): void
    {
        $this->enrollStudent($this->classroom, 1, 'ด.ช. ก้อง');

        $this->getJson('/api/v1/join/abc234')->assertOk()->assertExactJson(['data' => [
            'name' => 'ป.5/1',
            'grade_level' => $this->classroom->grade_level,
            'academic_year' => $this->classroom->academic_year,
            'teacher_name' => 'ครูสมศรี',
            'school_name' => 'โรงเรียนบ้านหนองบัว',
            'open' => true,
        ]]);

        $this->classroom->forceFill(['join_open' => false])->save();
        $this->getJson('/api/v1/join/ABC234')->assertOk()->assertJsonPath('data.open', false);
        $this->getJson('/api/v1/join/ZZZZZZ')->assertNotFound()->assertJsonPath('code', 'classroom_not_found');
        $this->getJson('/api/v1/join/AB')->assertNotFound();
    }

    public function test_a_pupil_of_another_teacher_joins_with_the_next_number(): void
    {
        // Two seats the teacher made and nobody has used yet.
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", ['students' => [
            ['name' => 'ด.ช. ก้อง', 'student_number' => 1],
            ['name' => 'ด.ญ. แก้ว', 'student_number' => 4],
        ]])->assertCreated();
        $other = $this->makeTeacher();
        $pupil = $this->enrollStudent($this->makeClassroom($other), 7, 'ด.ช. บี')['student'];

        $this->asUser($pupil)->getJson('/api/v1/student/join/abc234')->assertOk()
            ->assertJsonPath('data.member', false)
            ->assertJsonPath('data.open', true)
            ->assertJsonCount(2, 'data.seats');
        $this->asUser($pupil)->postJson('/api/v1/student/join', ['code' => 'abc 234'])->assertOk()
            ->assertJsonPath('data.joined', true)
            ->assertJsonPath('data.student_number', 5);
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->classroom->id, 'student_id' => $pupil->id, 'student_number' => 5]);

        // Joining again changes nothing; the page says so.
        $this->asUser($pupil)->postJson('/api/v1/student/join', ['code' => 'ABC234'])->assertOk()
            ->assertJsonPath('data.joined', false)
            ->assertJsonPath('data.member', true);
        $this->asUser($pupil)->getJson('/api/v1/student/join/ABC234')->assertOk()->assertJsonPath('data.member', true)->assertJsonCount(0, 'data.seats');

        // The classroom's teacher sees, edits and resets the pupil like any of theirs (StudentVisibility).
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($this->teacher)->patchJson("/api/v1/students/{$pupil->id}", ['name' => 'ด.ช. บี ใจดี'])->assertOk();
        $this->asUser($this->teacher)->postJson("/api/v1/students/{$pupil->id}/pin")->assertOk();
        $this->asUser($this->teacher)->getJson('/api/v1/school-students?q=บี')->assertOk()->assertJsonPath('data.0.id', $pupil->id);
        // The account keeps the workspace it was made in.
        $this->assertSame($other->school_id, $pupil->fresh()->school_id);
        // A teacher who has nothing to do with the pupil still sees nothing.
        $this->asUser($this->makeTeacher())->patchJson("/api/v1/students/{$pupil->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_closed_joining_or_a_closed_classroom_refuses(): void
    {
        $pupil = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];

        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$this->classroom->id}", ['join_open' => false])->assertOk()->assertJsonPath('data.join_open', false);
        $this->asUser($pupil)->postJson('/api/v1/student/join', ['code' => 'ABC234'])->assertStatus(409)->assertJsonPath('code', 'join_closed');
        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$this->classroom->id}", ['join_open' => true])->assertOk()->assertJsonPath('data.join_open', true);

        $this->classroom->forceFill(['closed_at' => now()])->save();
        $this->asUser($pupil)->getJson('/api/v1/student/join/ABC234')->assertOk()->assertJsonPath('data.open', false);
        $this->asUser($pupil)->postJson('/api/v1/student/join', ['code' => 'ABC234'])->assertStatus(409)->assertJsonPath('code', 'join_closed');
        $this->asUser($pupil)->postJson('/api/v1/student/join', ['code' => 'NOPE99'])->assertNotFound()->assertJsonPath('code', 'classroom_not_found');
        $this->asUser($pupil)->postJson('/api/v1/student/join', [])->assertStatus(422);
    }

    public function test_a_pupil_claims_the_seat_the_teacher_made_for_them(): void
    {
        $seats = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", ['students' => [
            ['name' => 'ด.ช. ก้อง ใจดี', 'student_number' => 3, 'student_code' => '12345'],
            ['name' => 'ด.ญ. แก้ว', 'student_number' => 4],
        ]])->assertCreated()->json('data');
        $kong = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 9, 'ก้อง')['student'];
        // แก้ว signed in and set her own password: her seat is taken.
        $kaew = User::query()->findOrFail($seats[1]['student_id']);
        app(CredentialIssuer::class)->setPassword($kaew, 'kaew-pass-1');

        $this->assertSame(
            [['student_id' => $seats[0]['student_id'], 'student_number' => 3, 'name' => 'ด.ช. ก้อง ใจดี', 'needs_code' => true]],
            $this->asUser($kong)->getJson('/api/v1/student/join/ABC234')->assertOk()->json('data.seats'),
        );

        // A seat with a student code wants it; a used seat is refused.
        $this->asUser($kong)->postJson('/api/v1/student/join', ['code' => 'ABC234', 'student_id' => $seats[0]['student_id']])
            ->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        $this->asUser($kong)->postJson('/api/v1/student/join', ['code' => 'ABC234', 'student_id' => $seats[0]['student_id'], 'student_code' => '999'])
            ->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        $this->asUser($kong)->postJson('/api/v1/student/join', ['code' => 'ABC234', 'student_id' => $kaew->id])
            ->assertStatus(409)->assertJsonPath('code', 'seat_taken');

        $this->asUser($kong)->postJson('/api/v1/student/join', ['code' => 'ABC234', 'student_id' => $seats[0]['student_id'], 'student_code' => ' 12345 '])
            ->assertOk()->assertJsonPath('data.joined', true)->assertJsonPath('data.student_number', 3);

        $kong->refresh();
        $this->assertSame('ก้อง', $kong->name, 'a teacher-made account keeps its own name');
        $this->assertSame('12345', $kong->student_code, 'the seat\'s student code follows');
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->classroom->id, 'student_id' => $kong->id, 'student_number' => 3]);
        $seatUser = User::query()->findOrFail($seats[0]['student_id']);
        $this->assertSame($kong->id, $seatUser->merged_into_id);
        $this->assertSame(User::STATUS_DISABLED, $seatUser->status);
        $this->assertDatabaseMissing('student_credentials', ['student_id' => $seatUser->id]);
        $roster = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()->json('data');
        $this->assertSame([3, 4], array_column($roster, 'student_number'));
        $this->assertSame([$kong->id, $kaew->id], array_column($roster, 'student_id'));
    }

    public function test_an_unknown_google_account_with_a_class_code_becomes_a_student_and_takes_the_seat_name(): void
    {
        $this->configureSignIn();
        Http::fake([GoogleCerts::URL => Http::response(['keys' => [self::rsaKey()['jwk']]], 200, ['Cache-Control' => 'max-age=3600'])]);
        $seat = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", ['students' => [
            ['name' => 'ด.ช. ก้อง ใจดี', 'student_number' => 3],
        ]])->assertCreated()->json('data.0');
        $claims = ['sub' => 'kid-sub', 'email' => 'kong@gmail.com', 'name' => 'Kong J.'];

        $this->forgetGuards();
        $ticket = $this->withoutToken()->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($claims), 'intent' => 'student'])
            ->assertNotFound()->assertJsonPath('code', 'google_not_linked')->json('link_ticket');
        $this->postJson('/api/v1/auth/google/join', ['link_ticket' => $ticket, 'join_code' => 'ABC234'])
            ->assertStatus(422)->assertJsonPath('code', 'notice_required');
        $this->postJson('/api/v1/auth/google/join', ['link_ticket' => $ticket, 'join_code' => 'ZZZZZZ', 'accept_notice' => true])
            ->assertNotFound()->assertJsonPath('code', 'classroom_not_found');
        $this->classroom->forceFill(['join_open' => false])->save();
        $this->postJson('/api/v1/auth/google/join', ['link_ticket' => $ticket, 'join_code' => 'ABC234', 'accept_notice' => true])
            ->assertStatus(409)->assertJsonPath('code', 'join_closed');
        $this->classroom->forceFill(['join_open' => true])->save();
        $this->assertSame(1, User::query()->where('role', User::ROLE_STUDENT)->count(), 'nothing made yet');

        $response = $this->postJson('/api/v1/auth/google/join', ['link_ticket' => $ticket, 'join_code' => 'ABC234', 'accept_notice' => true, 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.name', 'Kong J.')
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('user.school', null);
        $student = User::query()->findOrFail($response->json('user.id'));
        $this->assertSame($this->teacher->school_id, $student->school_id, 'made in the classroom owner\'s workspace');
        $this->assertMatchesRegularExpression('/^s\d{7}$/', (string) $student->username);
        $this->assertNull($student->email);
        $this->assertSame('google_signup', $student->googleIdentity()->value('linked_via'));
        $this->assertDatabaseHas('student_credentials', ['student_id' => $student->id, 'must_change_password' => false]);
        $token = $response->json('token');
        // The ticket is spent.
        $this->postJson('/api/v1/auth/google/join', ['link_ticket' => $ticket, 'join_code' => 'ABC234', 'accept_notice' => true])
            ->assertStatus(422)->assertJsonPath('code', 'link_ticket_invalid');

        // Signed in, the pupil picks the seat and takes the name the teacher typed.
        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/student/join/ABC234')->assertOk()->assertJsonPath('data.seats.0.name', 'ด.ช. ก้อง ใจดี');
        $this->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/student/join', ['code' => 'ABC234', 'student_id' => $seat['student_id']])
            ->assertOk()->assertJsonPath('data.student_number', 3);
        $this->assertSame('ด.ช. ก้อง ใจดี', $student->fresh()->name);
        $this->assertSame(1, User::query()->where('role', User::ROLE_STUDENT)->where('status', User::STATUS_ACTIVE)->count(), 'the seat account is merged away');

        // From now on the Google account signs this student in.
        $this->forgetGuards();
        $this->withoutToken()->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($claims), 'intent' => 'student'])
            ->assertOk()->assertJsonPath('user.id', $student->id);
    }

    public function test_the_owner_gets_the_join_qr_and_the_link(): void
    {
        $qr = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/join-qr")->assertOk()->json('data');

        $this->assertSame('ABC234', $qr['code']);
        $this->assertStringEndsWith('/app/#/join/ABC234', $qr['url']);
        $this->assertGreaterThan(20, count($qr['matrix']));
        foreach ($qr['matrix'] as $row) {
            $this->assertSame(count($qr['matrix']), strlen($row), 'square');
            $this->assertMatchesRegularExpression('/^[01]+$/', $row);
        }
        $this->assertSame('1111111', substr($qr['matrix'][0], 0, 7), 'the finder pattern opens the first row');

        $this->asUser($this->makeTeacher())->getJson("/api/v1/classrooms/{$this->classroom->id}/join-qr")->assertNotFound();
    }
}
