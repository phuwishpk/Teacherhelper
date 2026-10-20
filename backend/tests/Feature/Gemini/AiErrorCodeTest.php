<?php

namespace Tests\Feature\Gemini;

use App\Domain\Gemini\AiErrorCode;
use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiReply;
use App\Exceptions\ApiException;
use App\Filament\Resources\AiCalls\Pages\ManageAiCalls;
use App\Models\AiCall;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §36: one set of AI error codes, the call's id beside it, and the
 * admin finding the call by that id.
 */
class AiErrorCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_failures_get_their_code(): void
    {
        $this->assertSame(AiErrorCode::QUOTA, AiErrorCode::forTransport(429, 'HTTP 429: Resource has been exhausted'));
        $this->assertSame(AiErrorCode::QUOTA, AiErrorCode::forTransport(null, 'HTTP 429: quota'), 'the status read from the text');
        $this->assertSame(AiErrorCode::MODEL, AiErrorCode::forTransport(404, 'HTTP 404: models/x is not found'));
        $this->assertSame(AiErrorCode::SERVER, AiErrorCode::forTransport(503, 'HTTP 503: overloaded'));
        $this->assertSame(AiErrorCode::TIMEOUT, AiErrorCode::forTransport(504, 'HTTP 504'));
        $this->assertSame(AiErrorCode::KEY_INVALID, AiErrorCode::forTransport(403, 'HTTP 403: permission denied'));
        $this->assertSame(AiErrorCode::TIMEOUT, AiErrorCode::forTransport(null, 'ConnectionException: cURL error 28: Operation timed out'));
        $this->assertSame(AiErrorCode::BLOCKED, AiErrorCode::forTransport(200, 'prompt blocked: SAFETY'));
        $this->assertSame(AiErrorCode::ERROR, AiErrorCode::forTransport(null, 'no reply for this request'));
    }

    public function test_judged_outcomes_get_their_code(): void
    {
        $reply = GeminiReply::ok('{}', finishReason: 'SAFETY');
        $this->assertSame(AiErrorCode::BLOCKED, AiErrorCode::forOutcome(new CallOutcome(CallOutcome::INVALID_OUTPUT), $reply));
        $this->assertSame(AiErrorCode::OUTPUT, AiErrorCode::forOutcome(new CallOutcome(CallOutcome::INVALID_OUTPUT), GeminiReply::ok('x')));
        $this->assertSame(AiErrorCode::KEY_INVALID, AiErrorCode::forOutcome(new CallOutcome(CallOutcome::KEY_INVALID), GeminiReply::keyRejected('HTTP 400')));
        $this->assertNull(AiErrorCode::forOutcome(new CallOutcome(CallOutcome::OK), GeminiReply::ok('{}')));
        $this->assertSame(AiErrorCode::QUOTA, (new GeminiException('HTTP 429: x'))->code());
        foreach (AiErrorCode::ALL as $code) {
            $this->assertNotSame('', AiErrorCode::message($code));
        }
    }

    public function test_every_key_error_carries_the_ai_code(): void
    {
        Route::get('/api/v1/_test/ai-key-missing', fn () => throw new ApiException('ไม่มี key', 'ai_key_missing', 422));

        $this->getJson('/api/v1/_test/ai-key-missing')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_missing')
            ->assertJsonPath('ai_error_code', AiErrorCode::KEY_MISSING);
    }

    public function test_the_admin_finds_a_call_by_its_number(): void
    {
        Filament::setCurrentPanel('admin');
        $teacher = $this->makeTeacher(null, ['name' => 'ครูสมศรี']);
        $failed = AiCall::create(['purpose' => 'explanation', 'model' => 'm', 'prompt_version' => 'v1', 'key_source' => 'teacher', 'status' => AiCall::STATUS_ERROR, 'error' => 'HTTP 429', 'error_code' => AiErrorCode::QUOTA, 'http_status' => 429, 'user_id' => $teacher->id]);
        $other = AiCall::create(['purpose' => 'extract', 'model' => 'm', 'prompt_version' => 'v1', 'key_source' => 'server', 'status' => AiCall::STATUS_OK]);

        $this->actingAs($this->makeAdmin());
        Livewire::test(ManageAiCalls::class)
            ->searchTable('#'.$failed->id)
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('AI-QUOTA')
            ->assertSee('ครูสมศรี');
        Livewire::test(ManageAiCalls::class)
            ->filterTable('error_code', AiErrorCode::QUOTA)
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
