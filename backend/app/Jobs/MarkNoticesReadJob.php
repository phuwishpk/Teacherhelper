<?php

namespace App\Jobs;

use App\Domain\Firestore\FirestoreInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * POST /me/notices/read (DESIGN §38 step 2): the Firestore writes run in the
 * queue, like every other mirror write, never inside the request.
 */
class MarkNoticesReadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    /**
     * @param  list<string>  $ids
     */
    public function __construct(public readonly int $userId, public readonly array $ids)
    {
        $this->onQueue('default');
    }

    public function handle(FirestoreInbox $inbox): void
    {
        $inbox->markRead($this->userId, $this->ids);
    }
}
