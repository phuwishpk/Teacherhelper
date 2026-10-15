<?php

namespace App\Jobs;

use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleApiException;
use App\Models\GoogleAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Moves the Drive copy of a deleted class-post file to the owner's bin
 * (DESIGN §30.2). The row is already gone, so the ids travel with the job.
 */
class TrashDriveFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 40;

    public function __construct(public readonly int $userId, public readonly string $driveFileId)
    {
        $this->onQueue('default');
    }

    public function handle(GoogleAccessTokens $tokens): void
    {
        $account = GoogleAccount::query()->find($this->userId);
        if ($account === null || $account->needsReconnect()) {
            return;
        }
        try {
            GoogleApi::forAccount($account, $tokens)->trashDriveFile($this->driveFileId);
        } catch (GoogleApiException $e) {
            if ($e->isTransient() && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            Log::warning('google.drive_trash_failed', ['file_id' => $this->driveFileId, 'kind' => $e->kind]);
        }
    }
}
