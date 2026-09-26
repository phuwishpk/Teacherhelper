<?php

namespace Tests\Feature\Console;

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueWorkCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * queue:work exits 12 (memory limit) instead of 0 once the process holds
     * more than --memory MB; a coverage run (pcov, CI) keeps the whole suite's
     * line data in this process, so the pass is given headroom.
     */
    private const MEMORY = ['--memory' => 1024];

    public function test_it_dispatches_the_heartbeat_before_working_the_queue(): void
    {
        Queue::fake();

        $this->artisan('eduvision:queue-work', self::MEMORY)->assertSuccessful();

        Queue::assertPushed(QueueHeartbeatJob::class, 1);
    }

    public function test_one_cron_pass_processes_the_heartbeat_through_the_database_queue(): void
    {
        // Same wiring as production (KICKOFF B5): database queue, worker pass bounded by
        // --stop-when-empty, heartbeat written by the worker, not by the command itself.
        config(['queue.default' => 'database']);
        $this->assertNull(Cache::get(QueueHeartbeatJob::CACHE_KEY));

        $this->artisan('eduvision:queue-work', self::MEMORY)->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertNotNull(Cache::get(QueueHeartbeatJob::CACHE_KEY));

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
