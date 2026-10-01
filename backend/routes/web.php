<?php

use App\Http\Controllers\AdminHandoffLinkController;
use App\Http\Controllers\GoogleOAuthCallbackController;
use App\Http\Controllers\ResultLinkController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

// Google's redirect after the consent page of the browser connect flow
// (POST /api/v1/google/oauth/url, DESIGN §18.5). No login: the single-use
// state says which teacher asked. No session or cookie either: the browser
// only shows a result page. Register this exact URL (GOOGLE_OAUTH_REDIRECT_URI,
// default APP_URL + /google/oauth/callback) in the Web OAuth client.
Route::get('google/oauth/callback', GoogleOAuthCallbackController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->middleware('throttle:google-oauth-callback')
    ->name('google.oauth.callback');

// The link in a private Classroom announcement (DESIGN §19.7): a Thai page
// that opens the result in the app. No login and no student data.
Route::get('r/{submission_id}', ResultLinkController::class)
    ->where('submission_id', '[0-9]{1,18}')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->name('results.open-in-app');

// The one-time link of POST /api/v1/auth/admin-handoff (DESIGN §7.4): signs
// an admin who logged in on the app into the Filament panel's web session.
// Keeps the web group (session, cookies): the panel reads the same session.
Route::get('admin/handoff/{token}', AdminHandoffLinkController::class)
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:admin-handoff-link')
    ->name('admin.handoff');
