<?php

use App\Domain\Web\WebApp;
use App\Http\Controllers\AdminHandoffLinkController;
use App\Http\Controllers\AssignmentLinkController;
use App\Http\Controllers\GoogleOAuthCallbackController;
use App\Http\Controllers\GoogleSignInCallbackController;
use App\Http\Controllers\ResultLinkController;
use App\Http\Controllers\Sus\SusController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The web app (DESIGN §25) is the front page once it is uploaded to public/app.
Route::get('/', function () {
    return WebApp::installed() ? redirect('/'.WebApp::PATH.'/') : view('welcome');
});

// The public privacy policy (DESIGN §24.14): the "Application privacy policy
// link" of both Google Cloud projects' consent screens, which Google needs
// before an External app can be published. Static Thai page, no session.
Route::get('privacy', function () {
    return response()
        ->view('privacy', ['contactEmail' => config('eduvision.privacy.contact_email') ?: null])
        ->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
})
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('privacy');

// Google's redirect after the consent page of the browser connect flow
// (POST /api/v1/google/oauth/url, DESIGN §18.5). No login: the single-use
// state says which teacher asked. No session or cookie either: the browser
// only shows a result page. Register this exact URL (GOOGLE_OAUTH_REDIRECT_URI,
// default APP_URL + /google/oauth/callback) in the Web OAuth client.
Route::get('google/oauth/callback', GoogleOAuthCallbackController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:google-oauth-callback')
    ->name('google.oauth.callback');

// Google's redirect after the account chooser of the browser sign-in flow
// (POST /api/v1/auth/google/web-url, DESIGN §24.9.4). No login, session or
// cookie: the single-use state says what was asked. Register this exact URL
// (GOOGLE_SIGNIN_REDIRECT_URI, default APP_URL + /auth/google/callback) in
// the Web client of the sign-in project.
Route::get('auth/google/callback', GoogleSignInCallbackController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:google-signin-callback')
    ->name('auth.google.callback');

// The link in a private Classroom announcement (DESIGN §19.7): a Thai page
// that opens the result in the app. No login and no student data.
Route::get('r/{submission_id}', ResultLinkController::class)
    ->where('submission_id', '[0-9]{1,18}')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('results.open-in-app');

// The link in a courseWork description (DESIGN §30.12): a Thai page that
// opens the hand-in of that assignment in the app or on the web.
Route::get('a/{assignment_id}', AssignmentLinkController::class)
    ->where('assignment_id', '[0-9]{1,18}')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('assignments.open-in-app');

// The one-time link of POST /api/v1/auth/admin-handoff (DESIGN §7.4): signs
// an admin who logged in on the app into the Filament panel's web session.
// Keeps the web group (session, cookies): the panel reads the same session.
Route::get('admin/handoff/{token}', AdminHandoffLinkController::class)
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:admin-handoff-link')
    ->name('admin.handoff');

// The SUS questionnaire for evaluators (DESIGN §39.7): its own pages and
// look, separate from the Test Lab at /qa. Guard `sus`, role evaluator.
Route::prefix('sus')->name('sus.')->group(function () {
    Route::get('login', [SusController::class, 'showLogin'])->name('login');
    Route::post('login', [SusController::class, 'login'])->middleware('throttle:sus-login')->name('login.attempt');
    Route::post('logout', [SusController::class, 'logout'])->name('logout');
    Route::get('/', [SusController::class, 'show'])->name('form');
    Route::post('/', [SusController::class, 'store'])->name('store');
});
