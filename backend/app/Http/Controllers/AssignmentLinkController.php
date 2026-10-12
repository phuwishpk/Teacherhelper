<?php

namespace App\Http\Controllers;

use App\Domain\Web\WebApp;
use Illuminate\Http\Response;

/**
 * GET /a/{assignment_id} (DESIGN §30.12): the link in a courseWork
 * description. A Thai page with an Android intent into the app
 * (eduvision://a/{id}) and the web app's hand-in page. No login, no
 * assignment data: the app checks the student's own access.
 */
class AssignmentLinkController extends Controller
{
    public function __invoke(string $assignmentId): Response
    {
        $id = (int) $assignmentId;

        return response()
            ->view('assignments.open-in-app', [
                'intentUrl' => self::intentUrl($id),
                'webUrl' => WebApp::installed() ? WebApp::url('/student/assignments/'.$id.'/hand-in') : null,
            ])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ]);
    }

    public static function intentUrl(int $assignmentId): string
    {
        return 'intent://a/'.$assignmentId.'#Intent;scheme=eduvision;package='.ResultLinkController::APP_PACKAGE.';end';
    }
}
