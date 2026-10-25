<?php

namespace App\Jobs;

use App\Domain\Notifications\Notifier;
use App\Models\GoogleAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "ต้องเชื่อมบัญชี Google ใหม่" to the teacher (DESIGN §19.3): once per drop
 * of the grant. The atomic update of google_accounts.reconnect_notified_at
 * decides who sends, so two failing calls never push twice; a new connect
 * clears it for the next drop. Queued because the drop is often noticed
 * inside an HTTP request, where no push is sent (§9.9).
 */
class NotifyGoogleReconnectJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $teacherId)
    {
        $this->onQueue('default');
    }

    public function handle(Notifier $notifier): void
    {
        $claimed = GoogleAccount::query()
            ->whereKey($this->teacherId)
            ->whereNotNull('last_error')
            ->whereNull('reconnect_notified_at')
            ->update(['reconnect_notified_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $notifier->googleReconnectNeeded($this->teacherId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
