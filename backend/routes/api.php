<?php

use App\Http\Controllers\Api\V1\AiKeyController;
use App\Http\Controllers\Api\V1\AppealController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\AssignmentGoogleController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ClassroomGoogleController;
use App\Http\Controllers\Api\V1\ClassroomStudentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\GoogleAccountController;
use App\Http\Controllers\Api\V1\GoogleSubmissionController;
use App\Http\Controllers\Api\V1\GradingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LayoutController;
use App\Http\Controllers\Api\V1\LoginCardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ResponseController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RubricController;
use App\Http\Controllers\Api\V1\ScanController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentMasteryController;
use App\Http\Controllers\Api\V1\StudentPinController;
use App\Http\Controllers\Api\V1\StudentResultController;
use App\Http\Controllers\Api\V1\StudentRetakeController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\WorksheetPrintController;
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
                // The teacher's own Gemini key (§9.1, §10.1). PUT calls Google, so it is throttled.
                Route::get('me/ai-key', [AiKeyController::class, 'show'])->name('api.me.ai-key.show');
                Route::put('me/ai-key', [AiKeyController::class, 'update'])->middleware('throttle:10,1')->name('api.me.ai-key.update');
                Route::delete('me/ai-key', [AiKeyController::class, 'destroy'])->name('api.me.ai-key.destroy');

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

                // Assignments, rubric and worksheets (§9.3).
                Route::get('assignments', [AssignmentController::class, 'index'])->name('api.assignments.index');
                Route::post('assignments', [AssignmentController::class, 'store'])->name('api.assignments.store');
                Route::get('assignments/{id}', [AssignmentController::class, 'show'])->whereNumber('id')->name('api.assignments.show');
                Route::patch('assignments/{id}', [AssignmentController::class, 'update'])->whereNumber('id')->name('api.assignments.update');
                Route::delete('assignments/{id}', [AssignmentController::class, 'destroy'])->whereNumber('id')->name('api.assignments.destroy');
                Route::post('assignments/{id}/questions', [QuestionController::class, 'store'])->whereNumber('id')->name('api.assignments.questions.store');
                Route::post('assignments/{id}/layout', [LayoutController::class, 'store'])->whereNumber('id')->name('api.assignments.layout.store');
                Route::get('assignments/{id}/layouts', [LayoutController::class, 'index'])->whereNumber('id')->name('api.assignments.layouts.index');
                Route::post('assignments/{id}/worksheets', [WorksheetPrintController::class, 'store'])->whereNumber('id')->name('api.assignments.worksheets.store');
                Route::post('assignments/{id}/requeue-missing-key', [GradingController::class, 'requeueMissingKey'])->whereNumber('id')->name('api.assignments.requeue-missing-key');

                Route::patch('questions/{id}', [QuestionController::class, 'update'])->whereNumber('id')->name('api.questions.update');
                Route::delete('questions/{id}', [QuestionController::class, 'destroy'])->whereNumber('id')->name('api.questions.destroy');
                Route::post('questions/{id}/rubric/draft', [RubricController::class, 'draft'])->whereNumber('id')->name('api.questions.rubric.draft');
                Route::put('questions/{id}/rubric', [RubricController::class, 'update'])->whereNumber('id')->name('api.questions.rubric.update');

                Route::get('worksheet-prints/{id}', [WorksheetPrintController::class, 'show'])->whereNumber('id')->name('api.worksheet-prints.show');
                Route::get('worksheet-prints/{id}/file', [WorksheetPrintController::class, 'download'])->whereNumber('id')->name('api.worksheet-prints.file');

                // Scans (§9.4): upload, confirm a rescan of a published page, page image.
                Route::post('scans', [ScanController::class, 'store'])->name('api.scans.store');
                Route::post('scans/{id}/confirm-replace', [ScanController::class, 'confirmReplace'])->whereNumber('id')->name('api.scans.confirm-replace');
                Route::get('scans/{id}/page', [ScanController::class, 'page'])->whereNumber('id')->name('api.scans.page');

                // Review and publishing (§9.5, §13).
                Route::get('assignments/{id}/review-queue', [ReviewController::class, 'queue'])->whereNumber('id')->name('api.assignments.review-queue');
                Route::post('assignments/{id}/approve-confident', [ReviewController::class, 'approveConfident'])->whereNumber('id')->name('api.assignments.approve-confident');
                Route::post('assignments/{id}/publish', [ReviewController::class, 'publish'])->whereNumber('id')->name('api.assignments.publish');
                Route::get('responses/{id}', [ResponseController::class, 'show'])->whereNumber('id')->name('api.responses.show');
                Route::patch('responses/{id}', [ResponseController::class, 'update'])->whereNumber('id')->name('api.responses.update');
                // Calls Gemini synchronously (and costs money), so it is throttled.
                Route::post('responses/{id}/regenerate-explanation', [ResponseController::class, 'regenerateExplanation'])->whereNumber('id')->middleware('throttle:20,1')->name('api.responses.regenerate-explanation');
                Route::post('submissions/{id}/publish', [SubmissionController::class, 'publish'])->whereNumber('id')->name('api.submissions.publish');
                Route::get('appeals', [AppealController::class, 'index'])->name('api.appeals.index');
                Route::patch('appeals/{id}', [AppealController::class, 'update'])->whereNumber('id')->name('api.appeals.update');

                // Google Classroom (§18.6). Routes that call Google share the `google` limiter.
                Route::get('google/status', [GoogleAccountController::class, 'status'])->name('api.google.status');
                Route::delete('google/disconnect', [GoogleAccountController::class, 'disconnect'])->name('api.google.disconnect');
                Route::delete('classrooms/{id}/google-link', [ClassroomGoogleController::class, 'unlink'])->whereNumber('id')->name('api.classrooms.google-link.destroy');
                Route::middleware('throttle:google')->group(function () {
                    Route::post('google/connect', [GoogleAccountController::class, 'connect'])->name('api.google.connect');
                    Route::get('google/courses', [GoogleAccountController::class, 'courses'])->name('api.google.courses');
                    Route::post('classrooms/{id}/google-link', [ClassroomGoogleController::class, 'link'])->whereNumber('id')->name('api.classrooms.google-link.store');
                    Route::get('classrooms/{id}/google-roster', [ClassroomGoogleController::class, 'roster'])->whereNumber('id')->name('api.classrooms.google-roster.show');
                    Route::put('classrooms/{id}/google-roster', [ClassroomGoogleController::class, 'saveRoster'])->whereNumber('id')->name('api.classrooms.google-roster.update');
                    Route::post('assignments/{id}/google-post', [AssignmentGoogleController::class, 'post'])->whereNumber('id')->name('api.assignments.google-post');
                    Route::get('assignments/{id}/google-submissions', [AssignmentGoogleController::class, 'submissions'])->whereNumber('id')->name('api.assignments.google-submissions');
                    Route::post('assignments/{id}/google-grades/retry', [AssignmentGoogleController::class, 'retryGrades'])->whereNumber('id')->name('api.assignments.google-grades.retry');
                    Route::post('google-submissions/{id}/return', [GoogleSubmissionController::class, 'returnForRetake'])->whereNumber('id')->name('api.google-submissions.return');
                });
            });

            // Student-only (§9.7): their own published results.
            Route::middleware('ability:student')->prefix('student')->group(function () {
                Route::get('results', [StudentResultController::class, 'index'])->name('api.student.results.index');
                Route::get('results/{submission_id}', [StudentResultController::class, 'show'])->whereNumber('submission_id')->name('api.student.results.show');
                Route::post('responses/{id}/appeal', [StudentResultController::class, 'appeal'])->whereNumber('id')->middleware('throttle:30,1')->name('api.student.responses.appeal');
                Route::get('mastery', StudentMasteryController::class)->name('api.student.mastery');
                Route::get('retake-requests', [StudentRetakeController::class, 'index'])->name('api.student.retake-requests');
            });

            // Teacher or student (§9.5, §9.7); ResponsePolicy::viewCrop decides.
            Route::middleware('ability:teacher,student')->group(function () {
                Route::get('responses/{id}/crop', [ResponseController::class, 'crop'])->whereNumber('id')->name('api.responses.crop');
            });
        });
    });
});
