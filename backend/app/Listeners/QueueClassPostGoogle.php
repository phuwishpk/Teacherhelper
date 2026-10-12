<?php

namespace App\Listeners;

use App\Domain\ClassPosts\ClassPostGoogle;
use App\Events\ClassPostPublished;
use App\Models\ClassPost;

/**
 * A class post was published (DESIGN §30.4): queue its Google Classroom
 * copy when the author linked the classroom and kept "ส่งไป Google
 * Classroom ด้วย" on. Runs inline after the commit; the sending is the
 * queued PushClassPostGoogleJob.
 */
class QueueClassPostGoogle
{
    public function __construct(private readonly ClassPostGoogle $google) {}

    public function handle(ClassPostPublished $event): void
    {
        $post = ClassPost::query()->find($event->postId);
        if ($post !== null) {
            $this->google->queue($post);
        }
    }
}
