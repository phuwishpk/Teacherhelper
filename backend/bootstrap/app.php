<?php

use App\Exceptions\ApiErrorResponse;
use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Named limiter defined in AppServiceProvider; auth routes add a stricter one.
        $middleware->throttleApi();

        // Security headers on every response, API and Filament alike (DESIGN §16.1).
        $middleware->append(SecurityHeaders::class);

        // Role + Sanctum token ability (DESIGN §7.4: `teacher` vs `student`) and
        // the account-status check that runs after authentication on every API route.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'role' => EnsureRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // There is no `login` route: the only web login is Filament's. API guests get
        // no redirect at all (null), so the AuthenticationException below renders the
        // JSON 401 even when the client sends no Accept: application/json header.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $wantsApiError = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        // Every API error body has the same shape: {message, errors, code} (DESIGN §9).
        // ApiException = errors the app branches on; the rest map framework errors to
        // stable codes so nothing on api/* falls back to Laravel's default body.
        $exceptions->render(function (ApiException $e, Request $request) use ($wantsApiError) {
            return $wantsApiError($request)
                ? ApiErrorResponse::make($e->getMessage(), $e->errorCode, $e->status, $e->errors)
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsApiError) {
            return $wantsApiError($request)
                ? ApiErrorResponse::make($e->getMessage(), 'validation_failed', $e->status, $e->errors())
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsApiError) {
            return $wantsApiError($request)
                ? ApiErrorResponse::make('กรุณาเข้าสู่ระบบ', 'unauthenticated', 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsApiError) {
            if (! $wantsApiError($request)) {
                return null;
            }

            $status = $e->hasStatus() ? $e->status() : 403;

            return ApiErrorResponse::make(
                ApiErrorResponse::messageFor($status) ?? $e->getMessage(),
                ApiErrorResponse::codeFor($status),
                $status,
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsApiError) {
            return $wantsApiError($request)
                ? ApiErrorResponse::make(ApiErrorResponse::messageFor(404), 'not_found', 404)
                : null;
        });

        // 404 unknown route, 405, 429 from throttle, abort(...) and friends.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsApiError) {
            return $wantsApiError($request) ? ApiErrorResponse::fromHttpException($e) : null;
        });
    })->create();
