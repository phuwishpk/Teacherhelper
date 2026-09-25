<?php

namespace Tests\Feature\Gemini;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\HttpGeminiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * GEMINI_FAKE selects the offline fake everywhere but production: there the
 * fake would score real students from an image hash and accept any key.
 */
class GeminiClientBindingTest extends TestCase
{
    private function resolveIn(string $env): GeminiClient
    {
        $this->app['env'] = $env;
        $this->app->forgetInstance(GeminiClient::class);

        return $this->app->make(GeminiClient::class);
    }

    public function test_the_fake_serves_tests_and_other_environments(): void
    {
        $this->assertInstanceOf(FakeGeminiClient::class, $this->resolveIn('testing'));
        $this->assertInstanceOf(FakeGeminiClient::class, $this->resolveIn('local'));

        config(['services.gemini.fake' => false]);
        $this->assertInstanceOf(HttpGeminiClient::class, $this->resolveIn('local'));
    }

    public function test_production_refuses_the_fake_and_logs_an_error(): void
    {
        Log::spy();

        $this->assertInstanceOf(HttpGeminiClient::class, $this->resolveIn('production'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'gemini.fake_refused');
    }

    public function test_gemini_check_warns_about_the_fake_outside_local_and_testing(): void
    {
        $this->resolveIn('staging');
        $this->artisan('eduvision:gemini-check')
            ->expectsOutputToContain('GEMINI_FAKE=true outside local/testing')
            ->assertSuccessful();
    }

    public function test_gemini_check_says_the_flag_is_ignored_in_production(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [['name' => 'models/gemini-3.8-flash']]])]);
        $this->resolveIn('production');

        $this->artisan('eduvision:gemini-check')
            ->expectsOutputToContain('client: HttpGeminiClient')
            ->expectsOutputToContain('GEMINI_FAKE=true is ignored in production')
            ->expectsOutputToContain('models.list ok: 1 models')
            ->assertSuccessful();
    }
}
