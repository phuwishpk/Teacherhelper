<?php

namespace App\Jobs;

use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleApiException;
use App\Models\ClassPost;
use App\Models\GoogleAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the Google Classroom copy of a class post that was deleted,
 * switched off or moved to another course (DESIGN §30.4). Carries the ids
 * only: the post's row is already gone.
 */
class DeleteClassPostGoogleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 40;

    public function __construct(
        public readonly int $userId,
        public readonly string $courseId,
        public readonly string $kind,
        public readonly string $googleId,
    ) {
        $this->onQueue('default');
    }

    public function handle(GoogleAccessTokens $tokens): void
    {
        $account = GoogleAccount::query()->find($this->userId);
        if ($account === null || $account->needsReconnect()) {
            Log::warning('google.class_post_delete_skipped', ['user_id' => $this->userId, 'google_id' => $this->googleId]);

            return;
        }
        try {
            $api = GoogleApi::forAccount($account, $tokens);
            if ($this->kind === ClassPost::KIND_ANNOUNCEMENT) {
                $api->deleteAnnouncement($this->courseId, $this->googleId);
            } else {
                $api->deleteCourseWorkMaterial($this->courseId, $this->googleId);
            }
        } catch (GoogleApiException $e) {
            if ($e->isTransient() && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            Log::warning('google.class_post_delete_failed', ['google_id' => $this->googleId, 'kind' => $e->kind, 'message' => $e->getMessage()]);
        }
    }
}
