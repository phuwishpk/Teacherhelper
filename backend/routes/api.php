<?php

use App\Http\Controllers\Api\V1\AiKeyController;
use App\Http\Controllers\Api\V1\AnalyticsController;
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
use App\Http\Controllers\Api\V1\LearningResourceController;
use App\Http\Controllers\Api\V1\LoginCardController;
use App\Http\Controllers\Api\V1\MasteryController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\PracticeItemController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ResponseController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RubricController;
use App\Http\Controllers\Api\V1\ScanController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentMasteryController;
use App\Http\Controllers\Api\V1\StudentPinController;
use App\Http\Controllers\Api\V1\StudentPracticeController;
use App\Http\Controllers\Api\V1\StudentResultController;
use App\Http\Controllers\Api\V1\StudentRetakeController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\WorksheetPrintController;
use Illuminate\Support\Facades\Route;

// Ids are 64-bit integers. Digit strings longer than 18 characters would
// overflow the typed controller parameter into a 500; they are simply not
// routes (404), like any non-numeric id.
Route::pattern('id', '[0-9]{1,18}');
Route::pattern('submission_id', '[0-9]{1,18}');
Route::pattern('item_id', '[0-9]{1,18}');

// All endpoints live under /api/v1 (DESIGN §9).
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->name('api.health');

    // Public auth endpoints (DESIGN §7.4). Teachers get the strict per-IP
    // `teacher-auth` limiter; students the wider `student-auth` limiter, because
    // a whole class logs in from one school NAT address at once. Every
    // `throttle:<name>` below is a named limiter in AppServiceProvider with its
    // own bucket (never a bare throttle:N,M, which shares one per user).
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:teacher-auth')->group(function () {
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

            // Teacher-only (§9.2, §9.3): role + token ability `teacher` (EnsureRole) plus a policy per action.
            Route::middleware('role:teacher')->group(function () {
                // The teacher's own Gemini key (§9.1, §10.1). PUT calls Google, so it is throttled.
                Route::get('me/ai-key', [AiKeyController::class, 'show'])->name('api.me.ai-key.show');
                Route::put('me/ai-key', [AiKeyController::class, 'update'])->middleware('throttle:ai-key')->name('api.me.ai-key.update');
                Route::delete('me/ai-key', [AiKeyController::class, 'destroy'])->name('api.me.ai-key.destroy');

                Route::get('classrooms', [ClassroomController::class, 'index'])->name('api.classrooms.index');
                Route::post('classrooms', [ClassroomController::class, 'store'])->name('api.classrooms.store');
                Route::get('classrooms/{id}', [ClassroomController::class, 'show'])->name('api.classrooms.show');
                Route::patch('classrooms/{id}', [ClassroomController::class, 'update'])->name('api.classrooms.update');
                Route::post('classrooms/{id}/students', [ClassroomStudentController::class, 'store'])->name('api.classrooms.students.store');
                Route::get('classrooms/{id}/roster', [ClassroomStudentController::class, 'index'])->name('api.classrooms.roster');
                Route::post('classrooms/{id}/login-cards', [LoginCardController::class, 'storeForClassroom'])->name('api.classrooms.login-cards');

                Route::post('students/{id}/login-card', [LoginCardController::class, 'storeForStudent'])->name('api.students.login-card');
                Route::post('students/{id}/pin', [StudentPinController::class, 'store'])->name('api.students.pin');

                Route::get('login-card-prints/{id}', [LoginCardController::class, 'show'])->name('api.login-card-prints.show');
                Route::get('login-card-prints/{id}/file', [LoginCardController::class, 'download'])->name('api.login-card-prints.file');

                Route::get('skills', [SkillController::class, 'index'])->name('api.skills.index');
                Route::get('subjects', [SubjectController::class, 'index'])->name('api.subjects.index');

                // Assignments, rubric and worksheets (§9.3).
                Route::get('assignments', [AssignmentController::class, 'index'])->name('api.assignments.index');
                Route::post('assignments', [AssignmentController::class, 'store'])->name('api.assignments.store');
                Route::get('assignments/{id}', [AssignmentController::class, 'show'])->name('api.assignments.show');
                Route::patch('assignments/{id}', [AssignmentController::class, 'update'])->name('api.assignments.update');
                Route::delete('assignments/{id}', [AssignmentController::class, 'destroy'])->name('api.assignments.destroy');
                Route::post('assignments/{id}/questions', [QuestionController::class, 'store'])->name('api.assignments.questions.store');
                Route::post('assignments/{id}/layout', [LayoutController::class, 'store'])->name('api.assignments.layout.store');
                Route::get('assignments/{id}/layouts', [LayoutController::class, 'index'])->name('api.assignments.layouts.index');
                Route::post('assignments/{id}/worksheets', [WorksheetPrintController::class, 'store'])->name('api.assignments.worksheets.store');
                Route::post('assignments/{id}/requeue-missing-key', [GradingController::class, 'requeueMissingKey'])->name('api.assignments.requeue-missing-key');

                Route::patch('questions/{id}', [QuestionController::class, 'update'])->name('api.questions.update');
                Route::delete('questions/{id}', [QuestionController::class, 'destroy'])->name('api.questions.destroy');
                Route::post('questions/{id}/rubric/draft', [RubricController::class, 'draft'])->name('api.questions.rubric.draft');
                Route::put('questions/{id}/rubric', [RubricController::class, 'update'])->name('api.questions.rubric.update');

                Route::get('worksheet-prints/{id}', [WorksheetPrintController::class, 'show'])->name('api.worksheet-prints.show');
                Route::get('worksheet-prints/{id}/file', [WorksheetPrintController::class, 'download'])->name('api.worksheet-prints.file');

                // Scans (§9.4): upload, confirm a rescan of a published page, page image.
                Route::post('scans', [ScanController::class, 'store'])->name('api.scans.store');
                Route::post('scans/{id}/confirm-replace', [ScanController::class, 'confirmReplace'])->name('api.scans.confirm-replace');
                Route::get('scans/{id}/page', [ScanController::class, 'page'])->name('api.scans.page');

                // Review and publishing (§9.5, §13).
                Route::get('assignments/{id}/review-queue', [ReviewController::class, 'queue'])->name('api.assignments.review-queue');
                Route::post('assignments/{id}/approve-confident', [ReviewController::class, 'approveConfident'])->name('api.assignments.approve-confident');
                Route::post('assignments/{id}/publish', [ReviewController::class, 'publish'])->name('api.assignments.publish');
                Route::get('responses/{id}', [ResponseController::class, 'show'])->name('api.responses.show');
                Route::patch('responses/{id}', [ResponseController::class, 'update'])->name('api.responses.update');
                // Calls Gemini synchronously (and costs money), so it is throttled.
                Route::post('responses/{id}/regenerate-explanation', [ResponseController::class, 'regenerateExplanation'])->middleware('throttle:explanation')->name('api.responses.regenerate-explanation');
                Route::post('submissions/{id}/publish', [SubmissionController::class, 'publish'])->name('api.submissions.publish');
                Route::get('appeals', [AppealController::class, 'index'])->name('api.appeals.index');
                Route::patch('appeals/{id}', [AppealController::class, 'update'])->name('api.appeals.update');

                // Practice bank, review links, mastery and analytics (§9.6, §14).
                Route::get('practice-items', [PracticeItemController::class, 'index'])->name('api.practice-items.index');
                Route::post('practice-items', [PracticeItemController::class, 'store'])->name('api.practice-items.store');
                Route::patch('practice-items/{id}', [PracticeItemController::class, 'update'])->name('api.practice-items.update');
                // Queues a Gemini job (costs money), so it is throttled.
                Route::post('skills/{id}/practice-items/generate', [PracticeItemController::class, 'generate'])->middleware('throttle:practice-generate')->name('api.skills.practice-items.generate');
                Route::get('skills/{id}/resources', [LearningResourceController::class, 'index'])->name('api.skills.resources.index');
                Route::post('skills/{id}/resources', [LearningResourceController::class, 'store'])->name('api.skills.resources.store');
                Route::patch('resources/{id}', [LearningResourceController::class, 'update'])->name('api.resources.update');
                Route::delete('resources/{id}', [LearningResourceController::class, 'destroy'])->name('api.resources.destroy');
                Route::get('assignments/{id}/analytics', [AnalyticsController::class, 'assignment'])->name('api.assignments.analytics');
                Route::get('classrooms/{id}/mastery', [MasteryController::class, 'classroom'])->name('api.classrooms.mastery');
                Route::get('students/{id}/mastery', [MasteryController::class, 'student'])->name('api.students.mastery');

                // Google Classroom (§18.6). Without the OAuth client every route but
                // /google/status answers 503 google_not_configured first; routes that
                // call Google share the `google` limiter.
                Route::get('google/status', [GoogleAccountController::class, 'status'])->name('api.google.status');
                Route::middleware('google.configured')->group(function () {
                    Route::delete('google/disconnect', [GoogleAccountController::class, 'disconnect'])->name('api.google.disconnect');
                    Route::delete('classrooms/{id}/google-link', [ClassroomGoogleController::class, 'unlink'])->name('api.classrooms.google-link.destroy');
                    Route::middleware('throttle:google')->group(function () {
                        Route::post('google/connect', [GoogleAccountController::class, 'connect'])->name('api.google.connect');
                        Route::post('google/oauth/url', [GoogleAccountController::class, 'oauthUrl'])->name('api.google.oauth-url');
                        Route::get('google/courses', [GoogleAccountController::class, 'courses'])->name('api.google.courses');
                        Route::post('classrooms/{id}/google-link', [ClassroomGoogleController::class, 'link'])->name('api.classrooms.google-link.store');
                        Route::get('classrooms/{id}/google-roster', [ClassroomGoogleController::class, 'roster'])->name('api.classrooms.google-roster.show');
                        Route::put('classrooms/{id}/google-roster', [ClassroomGoogleController::class, 'saveRoster'])->name('api.classrooms.google-roster.update');
                        Route::post('assignments/{id}/google-post', [AssignmentGoogleController::class, 'post'])->name('api.assignments.google-post');
                        Route::get('assignments/{id}/google-submissions', [AssignmentGoogleController::class, 'submissions'])->name('api.assignments.google-submissions');
                        Route::post('assignments/{id}/google-grades/retry', [AssignmentGoogleController::class, 'retryGrades'])->name('api.assignments.google-grades.retry');
                        Route::post('google-submissions/{id}/return', [GoogleSubmissionController::class, 'returnForRetake'])->name('api.google-submissions.return');
                    });
                });
            });

            // Student-only (§9.7): their own published results.
            Route::middleware('role:student')->prefix('student')->group(function () {
                Route::get('results', [StudentResultController::class, 'index'])->name('api.student.results.index');
                Route::get('results/{submission_id}', [StudentResultController::class, 'show'])->name('api.student.results.show');
                Route::post('responses/{id}/appeal', [StudentResultController::class, 'appeal'])->middleware('throttle:appeal')->name('api.student.responses.appeal');
                Route::get('mastery', StudentMasteryController::class)->name('api.student.mastery');
                Route::get('retake-requests', [StudentRetakeController::class, 'index'])->name('api.student.retake-requests');
                // Practice (§9.7, §14.1): recommendations and graded attempts.
                Route::get('practice', [StudentPracticeController::class, 'index'])->name('api.student.practice.index');
                Route::post('practice/{item_id}/attempts', [StudentPracticeController::class, 'attempt'])->middleware('throttle:practice-attempt')->name('api.student.practice.attempts');
            });

            // Teacher or student (§9.5, §9.7); ResponsePolicy::viewCrop decides.
            Route::middleware('role:teacher,student')->group(function () {
                Route::get('responses/{id}/crop', [ResponseController::class, 'crop'])->name('api.responses.crop');
                // On-device model distribution (§9.8).
                Route::get('ml/models/active', [ModelController::class, 'active'])->name('api.ml.models.active');
                Route::get('ml/models/{id}/file', [ModelController::class, 'file'])->name('api.ml.models.file');
            });
        });
    });
});
