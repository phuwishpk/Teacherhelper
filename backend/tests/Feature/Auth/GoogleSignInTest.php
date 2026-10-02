<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Google\GoogleCerts;
use App\Domain\Auth\Google\GoogleSignInTickets;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * DESIGN §24.9 / §24.16 build 3: sign-in with a Google ID token for every
 * role, the automatic links (teacher e-mail, Classroom roster), the link
 * ticket (teacher registration, student PIN/QR confirmation), linking and
 * unlinking, the school's domains and student switch, and 503 when off.
 * Tokens are signed with test keys; Google is faked.
 */
class GoogleSignInTest extends TestCase
{
    use GoogleSignInFixtures;
    use RefreshDatabase;

    private School $school;

    private bool $certsDown = false;

    private string $nextIdToken = '';

    private User $teacher;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSignIn();
        // Closures, so a test can switch what Google answers (a second Http::fake would not override).
        Http::fake([
            GoogleCerts::URL => fn () => $this->certsDown
                ? Http::response('', 503)
                : Http::response(['keys' => [self::rsaKey()['jwk']]], 200, ['Cache-Control' => 'max-age=3600']),
            'https://oauth2.googleapis.com/token' => fn () => Http::response([
                'access_token' => 'ya29.signin-access-token', 'expires_in' => 3599, 'scope' => 'openid email profile', 'token_type' => 'Bearer', 'id_token' => $this->nextIdToken,
            ]),
        ]);
        $this->school = $this->makeSchool(['teacher_join_code' => 'JOIN2569']);
        $this->teacher = $this->makeTeacher($this->school, ['email' => 'kru.somsri@school.ac.th', 'name' => 'ครูสมศรี']);
        $this->classroom = $this->makeClassroom($this->teacher, ['class_code' => 'ABC123']);
    }

    // ---- configuration ----

    public function test_every_route_answers_503_first_when_not_configured(): void
    {
        config(['services.google_signin.client_ids' => '']);
        $student = $this->enrollStudent($this->classroom)['student'];

        $this->getJson('/api/v1/auth/google/config')->assertOk()
            ->assertExactJson(['data' => ['enabled' => false, 'web_flow' => false, 'notice_version' => 'gsi-1']]);
        foreach (['auth/google', 'auth/google/web-url', 'auth/google/ticket', 'auth/google/link-with-pin', 'auth/google/link-with-qr'] as $uri) {
            $this->postJson('/api/v1/'.$uri, [])->assertStatus(503)->assertJsonPath('code', 'google_signin_not_configured');
        }
        foreach ([[$this->teacher, 'GET'], [$this->teacher, 'POST'], [$this->teacher, 'DELETE'], [$student, 'GET'], [$this->makeAdmin(), 'DELETE']] as [$user, $method]) {
            $this->asUser($user)->json($method, '/api/v1/me/google-identity', [])->assertStatus(503)->assertJsonPath('code', 'google_signin_not_configured');
        }
        $this->asUser($this->teacher)->deleteJson("/api/v1/students/{$student->id}/google-identity")->assertStatus(503);
        // Guests still get 401 on the routes that need a token.
        $this->asGuest()->getJson('/api/v1/me/google-identity')->assertUnauthorized();
        // A registration with a ticket needs sign-in too; without one it works as before.
        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['google_link_ticket' => str_repeat('a', 48)]))
            ->assertStatus(503)->assertJsonPath('code', 'google_signin_not_configured');
        $this->postJson('/api/v1/auth/teacher/register', $this->registration())->assertCreated();
    }

    public function test_config_reports_the_flows(): void
    {
        $this->getJson('/api/v1/auth/google/config')->assertOk()
            ->assertExactJson(['data' => ['enabled' => true, 'web_flow' => false, 'notice_version' => 'gsi-1']]);

        $this->configureSignIn(web: true);
        $this->getJson('/api/v1/auth/google/config')->assertOk()->assertJsonPath('data.web_flow', true);
    }

    // ---- linked accounts ----

    public function test_a_linked_teacher_gets_a_teacher_token(): void
    {
        $this->linkGoogle($this->teacher, email: 'old@school.ac.th');

        $response = $this->signIn(['email' => 'kru.somsri@school.ac.th', 'name' => 'สมศรี ใหม่'], 'staff', ['device_name' => 'pixel'])->assertOk();

        $this->assertSame($this->teacher->id, $response->json('user.id'));
        $this->assertSame('teacher', $response->json('user.role'));
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['teacher'], $token->abilities);
        $this->assertSame('pixel', $token->name);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $identity = $this->teacher->googleIdentity()->first();
        $this->assertSame('kru.somsri@school.ac.th', $identity->email);
        $this->assertSame('สมศรี ใหม่', $identity->name);
        $this->assertNotNull($identity->last_login_at);
        // The token opens the teacher API.
        $this->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/v1/classrooms')->assertOk();
    }

    public function test_a_linked_admin_gets_an_admin_token_whatever_the_intent(): void
    {
        $admin = $this->makeAdmin(['email' => 'admin@school.ac.th']);
        $this->linkGoogle($admin, 'admin-sub', 'admin@school.ac.th');

        $response = $this->signIn(['sub' => 'admin-sub', 'email' => 'admin@school.ac.th'], 'student')->assertOk();

        $this->assertSame('admin', $response->json('user.role'));
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['admin'], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->forgetGuards();
        $this->withToken($response->json('token'))->postJson('/api/v1/auth/admin-handoff')->assertOk();
        $this->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/v1/classrooms')->assertForbidden();
    }

    public function test_a_linked_student_needs_the_school_switch(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $this->linkGoogle($student, 'student-sub', 'nong@school.ac.th');
        $claims = ['sub' => 'student-sub', 'email' => 'nong@school.ac.th'];

        $this->signIn($claims, 'student')->assertForbidden()->assertJsonPath('code', 'student_google_disabled');

        $this->allowStudents($this->school);
        $response = $this->signIn($claims, 'staff')->assertOk();
        $this->assertSame($student->id, $response->json('user.id'));
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(['student'], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDays(180)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        // Turning the switch off keeps the link but stops its use.
        $this->school->forceFill(['student_google_signin' => false])->save();
        $this->signIn($claims, 'student')->assertForbidden()->assertJsonPath('code', 'student_google_disabled');
        $this->assertNotNull($student->googleIdentity()->first());
    }

    public function test_inactive_or_merged_accounts_are_refused(): void
    {
        $pending = $this->makeTeacher($this->school, ['status' => 'pending']);
        $disabled = $this->makeTeacher($this->school, ['status' => 'disabled']);
        $this->linkGoogle($pending, 'pending-sub', 'p@school.ac.th');
        $this->linkGoogle($disabled, 'disabled-sub', 'd@school.ac.th');
        $this->allowStudents($this->school);
        $keep = $this->enrollStudent($this->classroom, 1)['student'];
        $merged = $this->enrollStudent($this->classroom, 2)['student'];
        $merged->forceFill(['status' => 'disabled', 'merged_into_id' => $keep->id])->save();
        $this->linkGoogle($merged, 'merged-sub', 'm@school.ac.th');

        $this->signIn(['sub' => 'pending-sub', 'email' => 'p@school.ac.th'])->assertForbidden()
            ->assertJsonPath('code', 'account_not_active')->assertJsonPath('message', 'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ');
        $this->signIn(['sub' => 'disabled-sub', 'email' => 'd@school.ac.th'])->assertForbidden()->assertJsonPath('code', 'account_not_active');
        $this->signIn(['sub' => 'merged-sub', 'email' => 'm@school.ac.th'], 'student')->assertForbidden()->assertJsonPath('code', 'account_not_active');
    }

    public function test_the_school_domains_apply_at_every_sign_in(): void
    {
        $this->linkGoogle($this->teacher, email: 'kru@gmail.com');
        $this->school->forceFill(['google_signin_domains' => ['school.ac.th']])->save();

        $this->signIn(['email' => 'kru@gmail.com'])->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');
        // Exact match only: a subdomain is another domain.
        $this->signIn(['email' => 'kru@mail.school.ac.th'])->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');
        $this->signIn(['email' => 'Kru@School.ac.th'])->assertOk();
        // A system admin is not limited by any school.
        $admin = $this->makeAdmin();
        $this->linkGoogle($admin, 'admin-sub', 'admin@gmail.com');
        $this->signIn(['sub' => 'admin-sub', 'email' => 'admin@gmail.com'])->assertOk();
    }

    public function test_a_bad_token_is_one_neutral_error(): void
    {
        $this->signIn(['aud' => 'other'])->assertStatus(422)->assertJsonPath('code', 'google_token_invalid')
            ->assertJsonPath('message', 'ยืนยันบัญชี Google ไม่สำเร็จ กรุณาลองเข้าสู่ระบบด้วย Google อีกครั้ง');
        $this->signIn(['email_verified' => false])->assertStatus(422)->assertJsonPath('code', 'google_email_unverified');
        $this->postJson('/api/v1/auth/google', ['id_token' => 'x.y.z'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->certsDown = true;
        DB::table('cache')->delete();
        $this->signIn()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');
    }

    // ---- first sign-in of staff ----

    public function test_a_teacher_is_linked_automatically_by_a_matching_email(): void
    {
        $response = $this->signIn(['email' => 'KRU.Somsri@school.ac.th'])->assertOk();

        $this->assertSame($this->teacher->id, $response->json('user.id'));
        $identity = $this->teacher->googleIdentity()->first();
        $this->assertSame('teacher_email', $identity->linked_via);
        $this->assertNull($identity->linked_by);
        $this->assertSame('110000000000000000001', $identity->google_sub);
        $this->assertSame('gsi-1', $identity->notice_version);
    }

    public function test_a_pending_teacher_is_linked_but_refused_until_approved(): void
    {
        $this->teacher->forceFill(['status' => 'pending'])->save();

        $this->signIn()->assertForbidden()->assertJsonPath('code', 'account_not_active');
        $this->assertNotNull($this->teacher->googleIdentity()->first());

        $this->teacher->forceFill(['status' => 'active'])->save();
        $this->signIn()->assertOk();
    }

    public function test_no_automatic_link_across_domains_or_onto_another_google_account(): void
    {
        $this->school->forceFill(['google_signin_domains' => ['other.ac.th']])->save();
        $this->signIn()->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');
        $this->assertNull($this->teacher->googleIdentity()->first());

        $this->school->forceFill(['google_signin_domains' => null])->save();
        $this->linkGoogle($this->teacher, 'first-sub', 'first@gmail.com');
        $this->signIn()->assertNotFound()->assertJsonPath('code', 'google_not_linked')->assertJsonMissingPath('registration');
        $this->assertSame('first-sub', $this->teacher->googleIdentity()->first()->google_sub);
    }

    public function test_an_admin_is_never_linked_automatically(): void
    {
        $admin = $this->makeAdmin(['email' => 'admin@school.ac.th']);

        $response = $this->signIn(['email' => 'admin@school.ac.th'])->assertNotFound()
            ->assertJsonPath('code', 'google_not_linked')
            ->assertJsonPath('message', 'เข้าสู่ระบบด้วยรหัสผ่านก่อน แล้วกดเชื่อมบัญชี Google ในหน้าผู้ดูแลระบบ');

        $this->assertArrayNotHasKey('link_ticket', $response->json());
        $this->assertNull($admin->googleIdentity()->first());
    }

    public function test_an_unknown_account_gets_a_link_ticket_and_the_registration_prefill(): void
    {
        $response = $this->signIn(['email' => 'new.teacher@school.ac.th', 'name' => 'ครูใหม่'])->assertNotFound()
            ->assertJsonPath('code', 'google_not_linked')
            ->assertJsonPath('registration', ['name' => 'ครูใหม่', 'email' => 'new.teacher@school.ac.th']);

        $this->assertSame(['message', 'errors', 'code', 'link_ticket', 'registration'], array_keys($response->json()));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $response->json('link_ticket'));
        $this->assertSame(0, UserGoogleIdentity::count());
    }

    public function test_a_teacher_registers_with_the_link_ticket(): void
    {
        $ticket = $this->signIn(['sub' => 'new-sub', 'email' => 'new.teacher@school.ac.th'])->assertNotFound()->json('link_ticket');

        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['google_link_ticket' => $ticket]))
            ->assertCreated()->assertJsonPath('user.status', 'pending');
        $user = User::query()->where('email', 'somchai@example.com')->firstOrFail();
        $identity = $user->googleIdentity()->first();
        $this->assertSame('registration', $identity->linked_via);
        $this->assertSame($user->id, $identity->linked_by);
        $this->assertSame('new-sub', $identity->google_sub);

        // Pending until an admin approves; then Google signs in.
        $this->signIn(['sub' => 'new-sub', 'email' => 'new.teacher@school.ac.th'])->assertForbidden()->assertJsonPath('code', 'account_not_active');
        $user->forceFill(['status' => 'active'])->save();
        $this->signIn(['sub' => 'new-sub', 'email' => 'new.teacher@school.ac.th'])->assertOk()->assertJsonPath('user.id', $user->id);

        // The ticket was spent.
        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['email' => 'two@example.com', 'google_link_ticket' => $ticket]))
            ->assertStatus(422)->assertJsonPath('code', 'link_ticket_invalid')->assertJsonStructure(['errors' => ['google_link_ticket']]);
        $this->assertNull(User::query()->where('email', 'two@example.com')->first());
    }

    public function test_registration_checks_the_domain_of_the_school_and_keeps_the_ticket(): void
    {
        $this->school->forceFill(['google_signin_domains' => ['school.ac.th']])->save();
        $ticket = $this->signIn(['sub' => 'gmail-sub', 'email' => 'someone@gmail.com'])->assertNotFound()->json('link_ticket');

        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['google_link_ticket' => $ticket]))
            ->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');
        $this->assertNull(User::query()->where('email', 'somchai@example.com')->first());

        $this->postJson('/api/v1/auth/teacher/register', $this->registration(['google_link_ticket' => str_repeat('0', 48)]))
            ->assertStatus(422)->assertJsonPath('code', 'link_ticket_invalid');
    }

    // ---- first sign-in of students ----

    public function test_a_student_is_linked_from_the_classroom_roster_when_sub_and_email_both_match(): void
    {
        $this->allowStudents($this->school);
        $student = $this->enrollStudent($this->classroom, 1, 'ด.ญ. นงนุช')['student'];
        $this->rosterGoogle($student, 'roster-sub', 'Nong@School.ac.th');

        $response = $this->signIn(['sub' => 'roster-sub', 'email' => 'nong@school.ac.th'], 'student')->assertOk();

        $this->assertSame($student->id, $response->json('user.id'));
        $this->assertSame('classroom_roster', $student->googleIdentity()->first()->linked_via);
    }

    public function test_the_roster_link_needs_one_student_with_both_sub_and_email(): void
    {
        $this->allowStudents($this->school);
        $a = $this->enrollStudent($this->classroom, 1)['student'];
        $b = $this->enrollStudent($this->classroom, 2)['student'];

        // Only the e-mail (a reused school address), only the sub, or two students: PIN/QR confirmation.
        $this->rosterGoogle($a, 'other-sub', 'nong@school.ac.th');
        $this->notLinkedStudent(['sub' => 'roster-sub', 'email' => 'nong@school.ac.th']);
        $this->rosterGoogle($a, 'roster-sub', 'changed@school.ac.th');
        $this->notLinkedStudent(['sub' => 'roster-sub', 'email' => 'nong@school.ac.th']);
        $this->rosterGoogle($a, 'roster-sub', 'nong@school.ac.th');
        $otherRoom = $this->makeClassroom($this->makeTeacher($this->school));
        $otherRoom->students()->attach($b->id, ['student_number' => 1]);
        DB::table('classroom_students')->where('student_id', $b->id)->where('classroom_id', $otherRoom->id)
            ->update(['google_user_id' => 'roster-sub', 'google_email' => 'nong@school.ac.th']);
        $this->notLinkedStudent(['sub' => 'roster-sub', 'email' => 'nong@school.ac.th']);

        $this->assertSame(0, UserGoogleIdentity::count());
    }

    public function test_an_unmatched_student_is_told_a_classroom_roster_links_them_soon(): void
    {
        $this->allowStudents($this->school);
        config(['eduvision.classroom_sync.roster_minutes' => 15]);

        $this->signIn(['sub' => 'not-yet-synced', 'email' => 'new@school.ac.th'], 'student')
            ->assertNotFound()
            ->assertJsonPath('code', 'google_not_linked')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Google Classroom') && str_contains($m, 'รอประมาณ 20 นาที') && str_contains($m, 'PIN'));
    }

    public function test_the_roster_link_respects_the_switch(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $this->rosterGoogle($student, 'roster-sub', 'nong@school.ac.th');

        $this->signIn(['sub' => 'roster-sub', 'email' => 'nong@school.ac.th'], 'student')
            ->assertForbidden()->assertJsonPath('code', 'student_google_disabled');
        $this->assertSame(0, UserGoogleIdentity::count());
    }

    public function test_a_student_confirms_the_first_sign_in_with_the_pin(): void
    {
        $this->allowStudents($this->school);
        $enrolled = $this->enrollStudent($this->classroom, 7);
        $ticket = $this->notLinkedStudent(['sub' => 'pin-sub', 'email' => 'nong@school.ac.th']);
        $body = ['link_ticket' => $ticket, 'class_code' => 'abc123', 'student_number' => 7, 'pin' => $enrolled['pin'], 'accept_notice' => true];

        $this->postJson('/api/v1/auth/google/link-with-pin', ['accept_notice' => false] + $body)
            ->assertStatus(422)->assertJsonPath('code', 'notice_required');
        $this->postJson('/api/v1/auth/google/link-with-pin', ['pin' => $this->wrongPin($enrolled['pin'])] + $body)
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->assertSame(0, UserGoogleIdentity::count());

        // A wrong PIN does not spend the ticket.
        $response = $this->postJson('/api/v1/auth/google/link-with-pin', $body + ['device_name' => 'tablet'])->assertOk();
        $this->assertSame($enrolled['student']->id, $response->json('user.id'));
        $this->assertSame(['student'], PersonalAccessToken::findToken($response->json('token'))->abilities);
        $identity = $enrolled['student']->googleIdentity()->first();
        $this->assertSame('pin_confirm', $identity->linked_via);
        $this->assertSame($enrolled['student']->id, $identity->linked_by);
        $this->assertSame('gsi-1', $identity->notice_version);
        $this->assertNotNull($identity->last_login_at);

        $this->postJson('/api/v1/auth/google/link-with-pin', $body)->assertStatus(422)->assertJsonPath('code', 'link_ticket_invalid');
        // From now on Google signs in directly.
        $this->signIn(['sub' => 'pin-sub', 'email' => 'nong@school.ac.th'], 'student')->assertOk()->assertJsonPath('user.id', $enrolled['student']->id);
    }

    public function test_the_pin_confirmation_keeps_the_lockout(): void
    {
        $this->allowStudents($this->school);
        $enrolled = $this->enrollStudent($this->classroom, 3);
        $ticket = $this->notLinkedStudent(['sub' => 'pin-sub', 'email' => 'nong@school.ac.th']);
        $body = ['link_ticket' => $ticket, 'class_code' => 'ABC123', 'student_number' => 3, 'pin' => $this->wrongPin($enrolled['pin']), 'accept_notice' => true];

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/google/link-with-pin', $body)->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        }
        $this->postJson('/api/v1/auth/google/link-with-pin', $body)->assertStatus(423)->assertJsonPath('code', 'pin_locked');
        $this->postJson('/api/v1/auth/google/link-with-pin', ['pin' => $enrolled['pin']] + $body)->assertStatus(423);
        // The PIN login shares the same lock.
        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'ABC123', 'student_number' => 3, 'pin' => $enrolled['pin']])->assertStatus(423);
    }

    public function test_a_student_confirms_with_the_qr_card(): void
    {
        $this->allowStudents($this->school);
        $enrolled = $this->enrollStudent($this->classroom, 4);
        $ticket = $this->notLinkedStudent(['sub' => 'qr-sub', 'email' => 'nong@school.ac.th']);

        $this->postJson('/api/v1/auth/google/link-with-qr', ['link_ticket' => $ticket, 'qr_token' => 'nope', 'accept_notice' => true])
            ->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $this->postJson('/api/v1/auth/google/link-with-qr', ['link_ticket' => str_repeat('f', 48), 'qr_token' => $enrolled['qr_token'], 'accept_notice' => true])
            ->assertStatus(422)->assertJsonPath('code', 'link_ticket_invalid');
        $this->postJson('/api/v1/auth/google/link-with-qr', ['link_ticket' => $ticket, 'qr_token' => $enrolled['qr_token'], 'accept_notice' => true])
            ->assertOk()->assertJsonPath('user.id', $enrolled['student']->id);
        $this->assertSame('pin_confirm', $enrolled['student']->googleIdentity()->first()->linked_via);
    }

    public function test_the_confirmation_checks_switch_domain_and_existing_links(): void
    {
        $enrolled = $this->enrollStudent($this->classroom, 5);
        $other = $this->enrollStudent($this->classroom, 6);
        $body = fn (string $ticket) => ['link_ticket' => $ticket, 'qr_token' => $enrolled['qr_token'], 'accept_notice' => true];

        $ticket = $this->notLinkedStudent(['sub' => 'a-sub', 'email' => 'nong@gmail.com']);
        $this->postJson('/api/v1/auth/google/link-with-qr', $body($ticket))->assertForbidden()->assertJsonPath('code', 'student_google_disabled');
        $this->allowStudents($this->school, ['school.ac.th']);
        $this->postJson('/api/v1/auth/google/link-with-qr', $body($ticket))->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');

        $this->linkGoogle($enrolled['student'], 'first-sub', 'first@school.ac.th');
        $ticket = $this->notLinkedStudent(['sub' => 'b-sub', 'email' => 'nong@school.ac.th']);
        $this->postJson('/api/v1/auth/google/link-with-qr', $body($ticket))->assertStatus(409)->assertJsonPath('code', 'google_identity_exists');

        // Between the ticket and the confirmation, someone else linked that Google account.
        $this->linkGoogle($other['student'], 'b-sub', 'nong@school.ac.th');
        $enrolled['student']->googleIdentity()->delete();
        $this->postJson('/api/v1/auth/google/link-with-qr', $body($ticket))->assertStatus(409)->assertJsonPath('code', 'google_already_linked');
    }

    // ---- /me/google-identity ----

    public function test_a_teacher_links_and_unlinks_in_the_settings(): void
    {
        $this->asUser($this->teacher)->getJson('/api/v1/me/google-identity')->assertOk()->assertExactJson(['data' => [
            'linked' => false, 'email' => null, 'name' => null, 'picture_url' => null, 'linked_via' => null,
            'linked_at' => null, 'can_link' => true, 'notice_version' => 'gsi-1',
        ]]);

        // The e-mail need not match the account's.
        $this->asUser($this->teacher)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'my-sub', 'email' => 'Personal@Gmail.com'])])
            ->assertOk()->assertJsonPath('data.linked', true)->assertJsonPath('data.email', 'personal@gmail.com')->assertJsonPath('data.linked_via', 'self');
        $this->assertSame($this->teacher->id, $this->teacher->googleIdentity()->first()->linked_by);

        // Again with the same account is a no-op; with another one, unlink first.
        $this->asUser($this->teacher)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'my-sub', 'email' => 'personal@gmail.com'])])->assertOk();
        $this->asUser($this->teacher)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'second-sub', 'email' => 'second@gmail.com'])])
            ->assertStatus(409)->assertJsonPath('code', 'google_identity_exists');
        // Somebody else cannot take it.
        $colleague = $this->makeTeacher($this->school);
        $this->asUser($colleague)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'my-sub', 'email' => 'personal@gmail.com'])])
            ->assertStatus(409)->assertJsonPath('code', 'google_already_linked');

        $this->asUser($this->teacher)->deleteJson('/api/v1/me/google-identity')->assertNoContent();
        $this->assertNull($this->teacher->googleIdentity()->first());
        $this->asUser($this->teacher)->deleteJson('/api/v1/me/google-identity')->assertNoContent();
        $this->signIn(['sub' => 'my-sub', 'email' => 'personal@gmail.com'])->assertNotFound();
    }

    public function test_an_admin_links_through_the_api(): void
    {
        $admin = $this->makeAdmin(['school_id' => $this->school->id]);
        $this->school->forceFill(['google_signin_domains' => ['school.ac.th']])->save();

        $this->asUser($admin)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'admin-sub', 'email' => 'admin@gmail.com'])])
            ->assertForbidden()->assertJsonPath('code', 'google_domain_not_allowed');
        $this->asUser($admin)->postJson('/api/v1/me/google-identity', ['id_token' => $this->idToken(['sub' => 'admin-sub', 'email' => 'admin@school.ac.th'])])
            ->assertOk()->assertJsonPath('data.linked', true);
        $this->signIn(['sub' => 'admin-sub', 'email' => 'admin@school.ac.th'])->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_a_student_links_after_accepting_the_notice(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $token = fn () => $this->idToken(['sub' => 'self-sub', 'email' => 'nong@school.ac.th']);

        $this->asUser($student)->getJson('/api/v1/me/google-identity')->assertOk()->assertJsonPath('data.can_link', false);
        $this->asUser($student)->postJson('/api/v1/me/google-identity', ['id_token' => $token(), 'accept_notice' => true])
            ->assertForbidden()->assertJsonPath('code', 'student_google_disabled');

        $this->allowStudents($this->school);
        $this->asUser($student)->getJson('/api/v1/me/google-identity')->assertOk()->assertJsonPath('data.can_link', true);
        $this->asUser($student)->postJson('/api/v1/me/google-identity', ['id_token' => $token()])
            ->assertStatus(422)->assertJsonPath('code', 'notice_required');
        $this->asUser($student)->postJson('/api/v1/me/google-identity', ['id_token' => $token(), 'accept_notice' => true])
            ->assertOk()->assertJsonPath('data.linked_via', 'self');
        $this->assertSame('gsi-1', $student->googleIdentity()->first()->notice_version);

        $this->asUser($student)->deleteJson('/api/v1/me/google-identity')->assertNoContent();
        $this->assertNull($student->googleIdentity()->first());
    }

    public function test_the_homeroom_teacher_unlinks_a_student_but_a_colleague_cannot(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $this->linkGoogle($student, 'student-sub', 'nong@school.ac.th');
        $colleague = $this->makeTeacher($this->school);
        $otherSchool = $this->makeTeacher();

        $this->asUser($colleague)->deleteJson("/api/v1/students/{$student->id}/google-identity")
            ->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');
        $this->asUser($otherSchool)->deleteJson("/api/v1/students/{$student->id}/google-identity")->assertNotFound();
        $this->assertNotNull($student->googleIdentity()->first());

        $roster = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk();
        $this->assertTrue($roster->json('data.0.google_linked'));
        $this->asUser($this->teacher)->deleteJson("/api/v1/students/{$student->id}/google-identity")->assertNoContent();
        $this->assertNull($student->googleIdentity()->first());
        $this->assertFalse($this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->json('data.0.google_linked'));
    }

    // ---- browser flow ----

    public function test_web_url_is_503_without_the_web_settings(): void
    {
        $this->postJson('/api/v1/auth/google/web-url', ['purpose' => 'login'])->assertStatus(503)->assertJsonPath('code', 'google_signin_web_not_configured');
    }

    public function test_the_browser_flow_signs_in_through_a_one_time_ticket(): void
    {
        $this->configureSignIn(web: true);
        $this->linkGoogle($this->teacher);

        $url = $this->postJson('/api/v1/auth/google/web-url', ['purpose' => 'login', 'intent' => 'staff'])->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame(self::SIGNIN_CLIENT_ID, $query['client_id']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('https://api.example.test/auth/google/callback', $query['redirect_uri']);
        $this->assertArrayNotHasKey('access_type', $query);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['nonce']);

        $this->fakeTokenEndpoint($this->idToken(['nonce' => $query['nonce']]));
        $callback = $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code-from-google', 'redirect' => 'https://evil.example']));
        $callback->assertRedirect();
        $location = $callback->headers->get('Location');
        $this->assertMatchesRegularExpression('~^https://app\.example\.test/#/login/google\?ticket=[a-f0-9]{48}$~', $location);
        $this->assertSame('no-referrer', $callback->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $callback->headers->get('Cache-Control'));
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_secret'] === self::SIGNIN_SECRET && $request['redirect_uri'] === 'https://api.example.test/auth/google/callback');

        // The state is single-use.
        $again = $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code-from-google']));
        $again->assertStatus(400)->assertSee('ลิงก์เข้าสู่ระบบด้วย Google ใช้ไม่ได้แล้ว');
        $this->assertSame('no-referrer', $again->headers->get('Referrer-Policy'));

        $ticket = substr($location, strrpos($location, '=') + 1);
        $this->postJson('/api/v1/auth/google/ticket', ['ticket' => $ticket])->assertOk()->assertJsonPath('user.id', $this->teacher->id);
        $this->postJson('/api/v1/auth/google/ticket', ['ticket' => $ticket])->assertStatus(422)->assertJsonPath('code', 'google_ticket_invalid');
    }

    public function test_a_state_another_callback_already_spent_is_refused_even_while_it_is_still_stored(): void
    {
        $tickets = app(GoogleSignInTickets::class);
        $state = $tickets->issueState(['purpose' => 'login', 'intent' => 'staff', 'nonce' => 'n', 'user_id' => null, 'accept_notice' => false]);
        // The other callback won the add() marker but has not forgotten the state yet.
        Cache::store('database')->add('google-signin:state:'.hash('sha256', $state).':used', 1, 600);

        $this->assertNull($tickets->consumeState($state));

        $fresh = $tickets->issueState(['purpose' => 'login', 'intent' => 'staff', 'nonce' => 'n', 'user_id' => null, 'accept_notice' => false]);
        $this->assertSame('login', $tickets->consumeState($fresh)['purpose'] ?? null);
        $this->assertNull($tickets->consumeState($fresh), 'single use');
    }

    public function test_the_login_ticket_lives_sixty_seconds_and_answers_like_post_auth_google(): void
    {
        $this->configureSignIn(web: true);

        $query = $this->webUrl(['purpose' => 'login', 'intent' => 'staff']);
        $this->fakeTokenEndpoint($this->idToken(['sub' => 'nobody', 'email' => 'nobody@school.ac.th', 'nonce' => $query['nonce']]));
        $location = $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code-1']))->headers->get('Location');
        $ticket = substr($location, strrpos($location, '=') + 1);
        $this->postJson('/api/v1/auth/google/ticket', ['ticket' => $ticket])->assertNotFound()->assertJsonPath('code', 'google_not_linked')
            ->assertJsonPath('registration.email', 'nobody@school.ac.th');

        $query = $this->webUrl(['purpose' => 'login']);
        $this->fakeTokenEndpoint($this->idToken(['nonce' => $query['nonce']]));
        $location = $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code-2']))->headers->get('Location');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/google/ticket', ['ticket' => substr($location, strrpos($location, '=') + 1)])->assertStatus(422)->assertJsonPath('code', 'google_ticket_invalid');
    }

    public function test_the_browser_flow_refuses_a_wrong_nonce_and_a_cancelled_choice(): void
    {
        $this->configureSignIn(web: true);

        $query = $this->webUrl(['purpose' => 'login']);
        $this->fakeTokenEndpoint($this->idToken(['nonce' => 'another-nonce']));
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code']))
            ->assertRedirect('https://app.example.test/#/login/google?error=google_token_invalid');

        $query = $this->webUrl(['purpose' => 'login']);
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))
            ->assertRedirect('https://app.example.test/#/login/google?error=cancelled');

        $this->get('/auth/google/callback?state=unknown-state-unknown-state-unknown-state-0&code=x')->assertStatus(400);
    }

    public function test_the_state_expires_after_ten_minutes(): void
    {
        $this->configureSignIn(web: true);
        $query = $this->webUrl(['purpose' => 'login']);

        $this->travel(601)->seconds();
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code']))->assertStatus(400);
    }

    public function test_the_browser_flow_links_the_account_of_the_user_who_asked(): void
    {
        $this->configureSignIn(web: true);

        $this->asGuest()->postJson('/api/v1/auth/google/web-url', ['purpose' => 'link'])->assertUnauthorized();
        $query = $this->webUrl(['purpose' => 'link'], $this->teacher);
        $this->fakeTokenEndpoint($this->idToken(['sub' => 'web-sub', 'email' => 'web@gmail.com', 'nonce' => $query['nonce']]));
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code']))
            ->assertRedirect('https://app.example.test/#/google-link?status=linked');
        $this->assertSame('web-sub', $this->teacher->googleIdentity()->first()->google_sub);

        // The same Google account for somebody else fails with its code.
        $colleague = $this->makeTeacher($this->school);
        $query = $this->webUrl(['purpose' => 'link'], $colleague);
        $this->fakeTokenEndpoint($this->idToken(['sub' => 'web-sub', 'email' => 'web@gmail.com', 'nonce' => $query['nonce']]));
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-code']))
            ->assertRedirect('https://app.example.test/#/google-link?status=google_already_linked');
    }

    public function test_a_student_web_link_needs_the_switch_and_the_notice(): void
    {
        $this->configureSignIn(web: true);
        $student = $this->enrollStudent($this->classroom)['student'];

        $this->forgetGuards();
        $this->withToken($this->tokenFor($student))->postJson('/api/v1/auth/google/web-url', ['purpose' => 'link', 'accept_notice' => true])
            ->assertForbidden()->assertJsonPath('code', 'student_google_disabled');
        $this->allowStudents($this->school);
        $this->forgetGuards();
        $this->withToken($this->tokenFor($student))->postJson('/api/v1/auth/google/web-url', ['purpose' => 'link'])
            ->assertStatus(422)->assertJsonPath('code', 'notice_required');
        $this->webUrl(['purpose' => 'link', 'accept_notice' => true], $student);
    }

    // ---- limits and privacy ----

    public function test_the_sign_in_routes_share_a_per_address_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/google', ['id_token' => 'a.b.c', 'intent' => 'staff'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/google/ticket', ['ticket' => str_repeat('a', 48)])->assertStatus(429);
    }

    public function test_no_token_ticket_or_email_reaches_the_log(): void
    {
        $this->captureSignInLog();
        $this->configureSignIn(web: true);
        $this->allowStudents($this->school);
        $enrolled = $this->enrollStudent($this->classroom, 9);
        $tokens = [];

        $tokens[] = $t = $this->idToken();
        $this->postJson('/api/v1/auth/google', ['id_token' => $t, 'intent' => 'staff'])->assertOk();
        $tokens[] = $t = $this->idToken(['sub' => 'unknown', 'email' => 'nong@school.ac.th']);
        $ticket = $this->postJson('/api/v1/auth/google', ['id_token' => $t, 'intent' => 'student'])->assertNotFound()->json('link_ticket');
        $this->postJson('/api/v1/auth/google/link-with-qr', ['link_ticket' => $ticket, 'qr_token' => $enrolled['qr_token'], 'accept_notice' => true])->assertOk();
        $query = $this->webUrl(['purpose' => 'login']);
        $tokens[] = $t = $this->idToken(['nonce' => $query['nonce']]);
        $this->fakeTokenEndpoint($t);
        $this->get('/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => '4/0AbCdEf-secret-code']));

        $log = json_encode($this->signinLog, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('google_signin', $log);
        foreach ([...$tokens, $ticket, $query['state'], '4/0AbCdEf-secret-code', self::SIGNIN_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringNotContainsString('school.ac.th', $log);
    }

    // ---- helpers ----

    /** @param  array<string, mixed>  $claims */
    private function signIn(array $claims = [], string $intent = 'staff', array $extra = []): TestResponse
    {
        $this->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/auth/google', ['id_token' => $this->idToken($claims), 'intent' => $intent, ...$extra]);
    }

    /** @param  array<string, mixed>  $claims */
    private function notLinkedStudent(array $claims): string
    {
        return $this->signIn($claims, 'student')->assertNotFound()
            ->assertJsonPath('code', 'google_not_linked')
            ->assertJsonMissingPath('registration')
            ->json('link_ticket');
    }

    private function rosterGoogle(User $student, string $sub, string $email): void
    {
        DB::table('classroom_students')->where('student_id', $student->id)->update(['google_user_id' => $sub, 'google_email' => $email]);
    }

    /** @return array<string, mixed> */
    private function registration(array $overrides = []): array
    {
        return [
            'school_code' => 'JOIN2569',
            'name' => 'ครูสมชาย',
            'email' => 'somchai@example.com',
            'password' => 'password123',
            ...$overrides,
        ];
    }

    private function wrongPin(string $pin): string
    {
        return $pin === '000000' ? '111111' : '000000';
    }

    /** @return array<string, string> the query of the URL from web-url */
    private function webUrl(array $body, ?User $user = null): array
    {
        $this->forgetGuards();
        $request = $user === null ? $this->withoutToken() : $this->withToken($this->tokenFor($user));
        $url = $request->postJson('/api/v1/auth/google/web-url', $body)->assertOk()->json('data.url');
        $this->forgetGuards();
        $this->withoutToken();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** What Google's token endpoint answers next (the faked code exchange of the browser flow). */
    private function fakeTokenEndpoint(string $idToken): void
    {
        $this->nextIdToken = $idToken;
    }
}
