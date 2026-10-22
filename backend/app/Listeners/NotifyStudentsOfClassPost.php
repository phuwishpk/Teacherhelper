<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\ClassPostPublished;
use App\Models\ClassPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "มีประกาศใหม่ในห้อง …" to the students of a published announcement (DESIGN
 * §30.3: materials are not announced, and §9.9: never the text of the
 * post), from the cron worker (queue `default`), only if the post still
 * exists.
 */
class NotifyStudentsOfClassPost implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(ClassPostPublished $event): void
    {
        $post = ClassPost::query()->with('classroom')->find($event->postId);
        if ($post === null || ! $post->isPublished() || $post->kind !== ClassPost::KIND_ANNOUNCEMENT) {
            return;
        }

        try {
            $this->notifier->classPostPublished($post);
        } catch (Throwable $e) {
            report($e); // a push outage must not fail or repeat the job
        }
    }
}
