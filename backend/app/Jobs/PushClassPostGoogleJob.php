<?php

namespace App\Jobs;

use App\Domain\ClassPosts\ClassPostGoogle;
use App\Domain\Google\GoogleApiException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates or patches the Google Classroom copy of one class post (DESIGN
 * §30.4), dispatched when the post is published, edited or retried.
 * Carries the class_post_google_posts id only.
 *
 * Google unreachable / 429 / 5xx: tried again after 1 and 5 minutes; after
 * the last attempt the row becomes `failed` with last_error. Other errors
 * (reconnect needed, missing scope, no teacher of the course, ...) fail at
 * once.
 */
class PushClassPostGoogleJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Up to five 10 MB uploads to Drive in one go (DESIGN §30.2). */
    public int $timeout = 110;

    public function __construct(public readonly int $rowId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->rowId;
    }

    public function handle(ClassPostGoogle $google): void
    {
        try {
            $google->push($this->rowId);
        } catch (GoogleApiException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            $google->markFailed($this->rowId, ClassPostGoogle::errorText($e));
        }
    }

    public function failed(?Throwable $e): void
    {
        app(ClassPostGoogle::class)->markFailed($this->rowId, 'ส่งไป Google Classroom ไม่สำเร็จ กดส่งอีกครั้ง');
    }
}
