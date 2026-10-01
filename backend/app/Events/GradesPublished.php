<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher published the grades of a classroom (DESIGN §23.7). Carries
 * the publication id only.
 *
 * Listeners: NotifyStudentsOfPublishedGrades (FCM, §9.9).
 */
class GradesPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $publicationId) {}
}
