<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * GET /r/{submission_id} (DESIGN §19.7, §19.9): the link in a private
 * Classroom announcement. A Thai page "เปิดผลในแอป EduVision" whose button
 * is an Android intent link into the app; the app opens the result after
 * the student signs in, so the page reads nothing from the database and
 * shows no student data. No login, session or cookie.
 *
 * Intent link: intent://r/{id}#Intent;scheme=eduvision;package=com.eduvision.app;end
 * (the app handles eduvision://r/{id} and opens /student/results/{id}).
 */
class ResultLinkController extends Controller
{
    public const APP_PACKAGE = 'com.eduvision.app';

    public function __invoke(string $submissionId): Response
    {
        $id = (int) $submissionId;

        return response()
            ->view('results.open-in-app', ['intentUrl' => self::intentUrl($id)])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            ]);
    }

    public static function intentUrl(int $submissionId): string
    {
        return 'intent://r/'.$submissionId.'#Intent;scheme=eduvision;package='.self::APP_PACKAGE.';end';
    }
}
