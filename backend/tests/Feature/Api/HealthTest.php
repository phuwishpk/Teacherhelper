<?php

namespace Tests\Feature\Api;

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_has_the_documented_shape(): void
    {
        // phpunit.xml uses CACHE_STORE=array and QUEUE_CONNECTION=sync, so no heartbeat
        // exists here: the endpoint must still answer 200 with db=ok (KICKOFF B6).
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'db', 'queue_last_run_at'])
            ->assertJson([
                'status' => 'degraded',
                'db' => 'ok',
                'queue_last_run_at' => null,
            ]);
    }

    public function test_health_is_ok_when_the_heartbeat_is_fresh(): void
    {
        (new QueueHeartbeatJob)->handle();

        $response = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'db' => 'ok']);

        $this->assertNotNull($response->json('queue_last_run_at'));
    }

    public function test_health_is_degraded_when_the_heartbeat_is_stale(): void
    {
        $maxAge = (int) config('eduvision.heartbeat_max_age_minutes');
        $stale = now()->subMinutes($maxAge + 1)->toIso8601String();
        Cache::forever(QueueHeartbeatJob::CACHE_KEY, $stale);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson([
                'status' => 'degraded',
                'db' => 'ok',
                'queue_last_run_at' => $stale,
            ]);
    }
}
