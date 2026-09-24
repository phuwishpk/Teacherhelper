<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use Illuminate\Support\Facades\Route;

// All endpoints live under /api/v1 (DESIGN §9).
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->name('api.health');

    Route::prefix('auth/teacher')->middleware('throttle:10,1')->group(function () {
        Route::post('register', [TeacherAuthController::class, 'register'])->name('api.auth.teacher.register');
        Route::post('login', [TeacherAuthController::class, 'login'])->name('api.auth.teacher.login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', MeController::class)->name('api.me');
        Route::post('auth/logout', [TeacherAuthController::class, 'logout'])->name('api.auth.logout'); // deletes the current token only
    });
});
