<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subject teacher asked to bind a course to a classroom (DESIGN §24.7).
 * Carries the request id only.
 *
 * Listeners: NotifyHomeroomOfCourseRequest (FCM to the homeroom teacher, §24.8).
 */
class CourseRequestCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $requestId) {}
}
