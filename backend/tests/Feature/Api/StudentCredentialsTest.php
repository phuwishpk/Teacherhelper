<?php

namespace Tests\Feature\Api;

use App\Domain\Students\LoginCardRenderer;
use App\Jobs\RenderLoginCardsJob;
use App\Models\LoginCardPrint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §9.2: login-card PDFs (classroom and single student) and PIN reset,
 * including the session revocation of §7.4 and the download policy of §7.3.
 */
class StudentCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_classroom_login_cards_are_queued_on_the_pdf_queue(): void
    {
        Queue::fake();
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $this->enrollStudent($classroom, 1);

        $response = $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/login-cards")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.classroom_id', $classroom->id)
            ->assertJsonPath('data.download_url', null);

        $id = $response->json('data.id');
        $this->assertSame("/api/v1/login-card-prints/{$id}", $response->json('data.status_url'));
        Queue::assertPushedOn('pdf', RenderLoginCardsJob::class, fn (RenderLoginCardsJob $job) => $job->printId === $id);
        $this->assertDatabaseHas('login_card_prints', ['id' => $id, 'requested_by' => $teacher->id, 'school_id' => $teacher->school_id]);
    }

    public function test_an_empty_classroom_cannot_print_cards(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);

        $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/login-cards")
            ->assertStatus(422)
            ->assertJsonPath('code', 'classroom_empty');
    }

    public function test_the_rendered_classroom_pdf_rotates_every_qr_token_and_can_be_downloaded(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher, ['name' => 'ป.5/2']);
        $a = $this->enrollStudent($classroom, 1, 'เด็กชายเอ');
        $b = $this->enrollStudent($classroom, 2, 'เด็กหญิงบี');
        $sessionOfA = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $a['qr_token']])->json('token');
        $oldHashA = $a['student']->credential->qr_token_hash;

        // QUEUE_CONNECTION=sync in tests: the job renders inline.
        $response = $this->asUser($teacher)->postJson("/api/v1/classrooms/{$classroom->id}/login-cards")->assertStatus(202);
        $id = $response->json('data.id');

        $status = $this->asUser($teacher)->getJson("/api/v1/login-card-prints/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.download_url', "/api/v1/login-card-prints/{$id}/file");

        $print = LoginCardPrint::findOrFail($id);
        Storage::disk('local')->assertExists($print->file_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($print->file_path));

        $download = $this->asUser($teacher)->get("/api/v1/login-card-prints/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $download->streamedContent());

        // Both students got a new QR token; the old card of A is dead and so is A's session.
        $this->assertNotSame($oldHashA, $a['student']->credential->fresh()->qr_token_hash);
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $a['qr_token']])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $b['qr_token']])->assertStatus(422);
        $this->forgetGuards();
        $this->withToken($sessionOfA)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_reissuing_one_students_card_renders_a_single_card_and_revokes_that_student_only(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $a = $this->enrollStudent($classroom, 1);
        $b = $this->enrollStudent($classroom, 2);
        $sessionOfB = $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $b['qr_token']])->json('token');

        $response = $this->asUser($teacher)->postJson("/api/v1/students/{$a['student']->id}/login-card")
            ->assertStatus(202)
            ->assertJsonPath('data.student_id', $a['student']->id)
            ->assertJsonPath('data.classroom_id', null);
        $id = $response->json('data.id');

        $this->asUser($teacher)->getJson("/api/v1/login-card-prints/{$id}")->assertOk()->assertJsonPath('data.status', 'ready');

        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $a['qr_token']])->assertStatus(422);
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $b['qr_token']])->assertOk();
        $this->forgetGuards();
        $this->withToken($sessionOfB)->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_failed_render_marks_the_print_failed_without_rotating_tokens(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $a = $this->enrollStudent($classroom, 1);
        $hash = $a['student']->credential->qr_token_hash;

        // A student with no classroom membership has nothing to print.
        $orphan = User::factory()->student($teacher->school)->create();
        $print = LoginCardPrint::create([
            'school_id' => $teacher->school_id, 'student_id' => $orphan->id, 'requested_by' => $teacher->id, 'status' => 'queued',
        ]);
        $this->withoutExceptionHandling();
        (new RenderLoginCardsJob($print->id))->handle(app(LoginCardRenderer::class));

        $this->assertSame('failed', $print->fresh()->status);
        $this->assertNotNull($print->fresh()->error);
        $this->assertSame($hash, $a['student']->credential->fresh()->qr_token_hash);
    }

    public function test_pin_reset_returns_a_new_pin_once_and_revokes_sessions(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher, ['class_code' => 'PINPIN']);
        $s = $this->enrollStudent($classroom, 4);
        $session = $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'PINPIN', 'student_number' => 4, 'pin' => $s['pin']])->json('token');
        $s['student']->credential->forceFill(['failed_pin_attempts' => 3, 'locked_until' => now()->addMinutes(10)])->save();

        $response = $this->asUser($teacher)->postJson("/api/v1/students/{$s['student']->id}/pin")
            ->assertOk()
            ->assertJsonPath('student_id', $s['student']->id);
        $newPin = $response->json('pin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $newPin);

        $credential = $s['student']->credential->fresh();
        $this->assertSame(0, $credential->failed_pin_attempts);
        $this->assertNull($credential->locked_until);

        $this->postJson('/api/v1/auth/student/pin', ['class_code' => 'PINPIN', 'student_number' => 4, 'pin' => $newPin])->assertOk();
        $this->forgetGuards();
        $this->withToken($session)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_credential_routes_are_limited_to_the_students_own_teacher(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $s = $this->enrollStudent($classroom, 1);
        $print = LoginCardPrint::create(['school_id' => $teacher->school_id, 'classroom_id' => $classroom->id, 'requested_by' => $teacher->id, 'status' => 'queued']);

        $colleague = $this->makeTeacher($teacher->school);
        $stranger = $this->makeTeacher();

        foreach ([$colleague, $stranger] as $other) {
            $this->asUser($other)->postJson("/api/v1/students/{$s['student']->id}/pin")->assertForbidden()->assertJsonPath('code', 'forbidden');
            $this->asUser($other)->postJson("/api/v1/students/{$s['student']->id}/login-card")->assertForbidden();
        }
        // Same school: the print row is visible in the query but the policy denies it.
        $this->asUser($colleague)->getJson("/api/v1/login-card-prints/{$print->id}")->assertForbidden();
        $this->asUser($colleague)->get("/api/v1/login-card-prints/{$print->id}/file")->assertForbidden();
        // Other school: scoped out entirely.
        $this->asUser($stranger)->getJson("/api/v1/login-card-prints/{$print->id}")->assertNotFound();

        // A student cannot touch any of it, nor can a guest.
        $this->asUser($s['student'], ['student'])->postJson("/api/v1/students/{$s['student']->id}/pin")->assertForbidden();
        $this->asUser($s['student'], ['student'])->getJson("/api/v1/login-card-prints/{$print->id}")->assertForbidden();
        $this->asGuest()->postJson("/api/v1/students/{$s['student']->id}/pin")->assertUnauthorized();
        $this->asGuest()->getJson("/api/v1/login-card-prints/{$print->id}/file")->assertUnauthorized();

        // A teacher id is not a student id.
        $this->asUser($teacher)->postJson("/api/v1/students/{$teacher->id}/pin")->assertNotFound();

        // Not ready yet -> 409 for the owner.
        $this->asUser($teacher)->get("/api/v1/login-card-prints/{$print->id}/file")->assertStatus(409)->assertJsonPath('code', 'print_not_ready');
    }
}
