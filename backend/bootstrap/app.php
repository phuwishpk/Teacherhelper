<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error body has the same shape: {message, errors, code} (DESIGN §9).
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($e->toArray(), $e->status);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'code' => 'validation_failed',
                ], $e->status);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'กรุณาเข้าสู่ระบบ',
                    'errors' => (object) [],
                    'code' => 'unauthenticated',
                ], 401);
            }

            return null;
        });
    })->create();
