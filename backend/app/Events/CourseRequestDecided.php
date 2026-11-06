<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The homeroom teacher approved or declined a course request (DESIGN §24.7).
 * Carries the request id only.
 *
 * Listeners: NotifyRequesterOfCourseDecision (FCM to the requester, §24.8).
 */
class CourseRequestDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $requestId) {}
}
