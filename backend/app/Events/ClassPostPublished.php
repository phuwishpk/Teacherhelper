<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A class post went from draft or scheduled to published (DESIGN §30.1).
 * Carries the post id only.
 *
 * Listeners: NotifyStudentsOfClassPost (FCM, §30.3).
 */
class ClassPostPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $postId) {}
}
