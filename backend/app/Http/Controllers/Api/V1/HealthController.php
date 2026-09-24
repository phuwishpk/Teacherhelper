<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\QueueHeartbeatJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/health -> {status, db, queue_last_run_at}
 *
 * `status` is "ok" only when the database answers AND the queue heartbeat
 * (written by QueueHeartbeatJob from the cron-driven worker, KICKOFF B5) is
 * younger than config('eduvision.heartbeat_max_age_minutes'). HTTP status is
 * 503 only when the database is unreachable.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $db = 'ok';
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $db = 'error';
        }

        $last = null;
        if ($db === 'ok') {
            try {
                $last = Cache::get(QueueHeartbeatJob::CACHE_KEY); // cache store is the same DB
            } catch (\Throwable) {
                // Leave $last null: a broken cache store shows up as "degraded".
            }
        }

        $maxAge = (int) config('eduvision.heartbeat_max_age_minutes');
        $fresh = is_string($last) && Carbon::parse($last)->gt(now()->subMinutes($maxAge));

        return response()->json([
            'status' => ($db === 'ok' && $fresh) ? 'ok' : 'degraded',
            'db' => $db,
            'queue_last_run_at' => is_string($last) ? $last : null,
        ], $db === 'ok' ? 200 : 503);
    }
}
