<?php

use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ClassroomStudentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LoginCardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentPinController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use Illuminate\Support\Facades\Route;

// All endpoints live under /api/v1 (DESIGN §9).
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->name('api.health');

    // Public auth endpoints (DESIGN §7.4). Teachers get the strict per-IP
    // limiter; students the wider `student-auth` limiter (AppServiceProvider),
    // because a whole class logs in from one school NAT address at once.
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('teacher/register', [TeacherAuthController::class, 'register'])->name('api.auth.teacher.register');
            Route::post('teacher/login', [TeacherAuthController::class, 'login'])->name('api.auth.teacher.login');
        });
        Route::middleware('throttle:student-auth')->group(function () {
            Route::post('student/qr', [StudentAuthController::class, 'qr'])->name('api.auth.student.qr');
            Route::post('student/pin', [StudentAuthController::class, 'pin'])->name('api.auth.student.pin');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [TeacherAuthController::class, 'logout'])->name('api.auth.logout'); // deletes the current token only

        // Every other route also requires status = active (`active` middleware).
        Route::middleware('active')->group(function () {
            Route::get('me', MeController::class)->name('api.me');
            Route::post('devices', [DeviceController::class, 'store'])->name('api.devices.store');

            // Teacher-only (§9.2, §9.3): token ability `teacher` plus a policy per action.
            Route::middleware('ability:teacher')->group(function () {
                Route::get('classrooms', [ClassroomController::class, 'index'])->name('api.classrooms.index');
                Route::post('classrooms', [ClassroomController::class, 'store'])->name('api.classrooms.store');
                Route::get('classrooms/{id}', [ClassroomController::class, 'show'])->whereNumber('id')->name('api.classrooms.show');
                Route::patch('classrooms/{id}', [ClassroomController::class, 'update'])->whereNumber('id')->name('api.classrooms.update');
                Route::post('classrooms/{id}/students', [ClassroomStudentController::class, 'store'])->whereNumber('id')->name('api.classrooms.students.store');
                Route::get('classrooms/{id}/roster', [ClassroomStudentController::class, 'index'])->whereNumber('id')->name('api.classrooms.roster');
                Route::post('classrooms/{id}/login-cards', [LoginCardController::class, 'storeForClassroom'])->whereNumber('id')->name('api.classrooms.login-cards');

                Route::post('students/{id}/login-card', [LoginCardController::class, 'storeForStudent'])->whereNumber('id')->name('api.students.login-card');
                Route::post('students/{id}/pin', [StudentPinController::class, 'store'])->whereNumber('id')->name('api.students.pin');

                Route::get('login-card-prints/{id}', [LoginCardController::class, 'show'])->whereNumber('id')->name('api.login-card-prints.show');
                Route::get('login-card-prints/{id}/file', [LoginCardController::class, 'download'])->whereNumber('id')->name('api.login-card-prints.file');

                Route::get('skills', [SkillController::class, 'index'])->name('api.skills.index');
                Route::get('subjects', [SubjectController::class, 'index'])->name('api.subjects.index');
            });
        });
    });
});
