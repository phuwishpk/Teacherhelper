<?php

namespace App\Domain\Google;

/**
 * The link back to Krucheck in a courseWork (DESIGN §30.4, §30.12):
 * APP_URL/a/{id}, a page that opens the hand-in in the app or on the web.
 */
final class AssignmentLinks
{
    public static function url(int $assignmentId): string
    {
        return rtrim((string) config('app.url'), '/').'/a/'.$assignmentId;
    }
}
