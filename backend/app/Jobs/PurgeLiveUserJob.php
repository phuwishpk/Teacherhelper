<?php

namespace App\Jobs;

use App\Domain\Firestore\FirebaseAuthUsers;
use App\Domain\Firestore\FirestoreClient;
use App\Domain\Firestore\FirestoreConfig;
use App\Domain\Firestore\FirestoreUid;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An account was deleted (DESIGN §31, §38.8): remove its live-layer copy,
 * every document under users/{uid}, and its Firebase Auth user, so no
 * device keeps a Firebase session for it. MariaDB is untouched; a restored
 * account simply signs in to Firebase again. Carries the id only.
 */
class PurgeLiveUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout = 50;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if (! FirestoreConfig::enabled()) {
            return;
        }
        $uid = FirestoreUid::of($this->userId);
        $deleted = FirestoreClient::fromConfig()->deleteSubcollections(FirestoreUid::path($this->userId));
        $hadAuthUser = FirebaseAuthUsers::fromConfig()->delete($uid);
        Log::info('firestore.user_purged', ['user_id' => $this->userId, 'documents' => $deleted, 'auth_user' => $hadAuthUser]);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('firestore.user_purge_failed', ['user_id' => $this->userId, 'message' => $e?->getMessage()]);
    }
}
