<?php

namespace App\Listeners;

use App\Domain\Firestore\LiveClassroom;
use App\Events\ClassPostPublished;
use App\Models\ClassPost;

/**
 * Signals users/{uid}/class_feed/{classroomId} in Firestore when a post is
 * published (DESIGN §38.11, step 4). Dispatches SignalClassFeedJob without
 * ShouldQueue because that job queues itself.
 */
class SignalClassFeedOfClassPost
{
    public function handle(ClassPostPublished $event): void
    {
        $classroomId = ClassPost::query()->whereKey($event->postId)->value('classroom_id');
        if ($classroomId !== null) {
            LiveClassroom::feedChanged((int) $classroomId);
        }
    }
}
