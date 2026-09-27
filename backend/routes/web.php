<?php

use App\Http\Controllers\GoogleOAuthCallbackController;
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
