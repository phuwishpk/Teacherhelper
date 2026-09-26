<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SecurityHeaders middleware: every response (API and Filament) carries the
 * browser hardening headers, API answers are never cached, JSON gets a
 * locked-down CSP, and HSTS is sent only over HTTPS.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_the_security_headers_and_are_never_cached(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_error_responses_carry_them_too(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        $this->getJson('/api/v1/no-such-route')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('https://localhost/api/v1/health')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);

        $this->get('http://localhost/api/v1/health')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_admin_panel_gets_the_browser_headers_but_not_the_api_only_ones(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_file_downloads_keep_the_controllers_cache_policy_and_get_no_csp(): void
    {
        Storage::fake('local');
        $model = ModelVersion::create(['name' => 'digit_crnn', 'version' => '0.1.0', 'file_path' => 'models/digit_crnn/0.1.0.tflite', 'sha256' => hash('sha256', 'bytes'), 'metrics' => [], 'is_active' => true]);
        Storage::disk('local')->put($model->file_path, 'bytes');

        $response = $this->asUser($this->makeTeacher())->get("/api/v1/ml/models/{$model->id}/file");
        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
