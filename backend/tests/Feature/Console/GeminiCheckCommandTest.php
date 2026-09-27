<?php

namespace Tests\Feature\Console;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiReply;
use App\Models\TeacherApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `php artisan eduvision:gemini-check` (DESIGN §10.1) against the fake. */
class GeminiCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_the_server_key_and_a_tiny_request(): void
    {
        $this->artisan('eduvision:gemini-check', ['--generate' => true])
            ->expectsOutputToContain('FakeGeminiClient')
            ->expectsOutputToContain('server ••••real')
            ->expectsOutputToContain('models.list ok: 3 models')
            ->expectsOutputToContain('generateContent ok: {"ok":true,"word_th":"สวัสดี"}')
            ->doesntExpectOutputToContain('testing-server-gemini-key-not-real')
            ->assertSuccessful();
    }

    public function test_it_fails_without_a_server_key(): void
    {
        config(['services.gemini.api_key' => '']);

        $this->artisan('eduvision:gemini-check')
            ->expectsOutputToContain('GEMINI_API_KEY is empty')
            ->assertFailed();
    }

    public function test_it_reports_a_rejected_key_and_a_bad_answer(): void
    {
        config(['services.gemini.api_key' => 'server-key-invalid-000000000000000']);
        $this->artisan('eduvision:gemini-check')->expectsOutputToContain('models.list failed (key_invalid)')->assertFailed();

        config(['services.gemini.api_key' => 'server-key-fine-00000000000000000']);
        $this->app->instance(GeminiClient::class, new class extends FakeGeminiClient
        {
            public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
            {
                return array_map(fn () => GeminiReply::ok('Sure! {"ok": "yes"}', 20, 5, 300), $requests);
            }
        });
        $this->artisan('eduvision:gemini-check', ['--generate' => true])
            ->expectsOutputToContain('not with the requested JSON')
            ->assertFailed();
    }

    public function test_it_runs_the_injection_sample_set_through_the_extract_prompt(): void
    {
        $fake = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $fake);

        $run = $this->artisan('eduvision:gemini-check', ['--injection' => true]);
        foreach (glob(base_path('tests/fixtures/injection/*.png')) ?: [] as $path) {
            $file = basename($path);
            $expected = str_contains($file, 'control') ? 'false' : 'true';
            $run->expectsOutputToContain("{$file}: suspicious_instruction={$expected} (expected {$expected}) ok");
        }
        $run->expectsOutputToContain('injection: 5/5 as expected')
            ->doesntExpectOutputToContain('testing-server-gemini-key-not-real')
            ->assertSuccessful()
            ->run();

        // The production prompt and schema, the PNG itself, nothing about a student.
        $this->assertCount(5, $fake->requests);
        foreach ($fake->requests as $request) {
            $this->assertSame('extract', $request->purpose);
            $this->assertSame('short', $request->type);
            $this->assertSame('image/png', $request->images[0]->mimeType);
            $this->assertStringStartsWith("\x89PNG", $request->images[0]->data);
            $this->assertStringContainsString('12 + 8 = ?', $request->userText);
        }
    }

    public function test_a_missed_injection_fails_the_check(): void
    {
        $this->app->instance(GeminiClient::class, new class extends FakeGeminiClient
        {
            public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
            {
                return array_map(fn () => GeminiReply::ok((string) json_encode([
                    'blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear',
                    'answer_text' => '20', 'key_match' => 'exact', 'error_types' => [],
                ])), $requests);
            }
        });

        $this->artisan('eduvision:gemini-check', ['--injection' => true])
            ->expectsOutputToContain('01_full_marks_th.png: suspicious_instruction=false (expected true) MISMATCH')
            ->expectsOutputToContain('05_control_plain_th.png: suspicious_instruction=false (expected false) ok')
            ->expectsOutputToContain('injection: 1/5 as expected')
            ->assertFailed();
    }

    public function test_it_can_check_a_teachers_saved_key(): void
    {
        $teacher = $this->makeTeacher();
        TeacherApiKey::create(['user_id' => $teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTSyTeacherCheck000000000000000009876', 'key_last4' => '9876']);

        $this->artisan('eduvision:gemini-check', ['--teacher' => $teacher->id])
            ->expectsOutputToContain('teacher ••••9876')
            ->assertSuccessful();
        $this->artisan('eduvision:gemini-check', ['--teacher' => 999999])->assertFailed();
    }
}
