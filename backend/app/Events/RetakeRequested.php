<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher sent a Google Classroom submission back for a new photo
 * (DESIGN §18.2 "ตีกลับให้ถ่ายใหม่"). Classroom has no private-comment API,
 * so the reason reaches the student through our app: a push
 * (NotifyStudentOfRetakeRequest) and GET /student/retake-requests.
 */
class RetakeRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $importId) {}
}
