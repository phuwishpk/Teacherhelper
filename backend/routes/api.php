<?php

use App\Http\Controllers\Api\V1\AiKeyController;
use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AnswerKeyController;
use App\Http\Controllers\Api\V1\AppealController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\AssignmentGoogleController;
use App\Http\Controllers\Api\V1\ChartController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ClassroomGoogleController;
use App\Http\Controllers\Api\V1\ClassroomStudentController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CourseDocumentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\ExamImageController;
use App\Http\Controllers\Api\V1\ExamSectionController;
use App\Http\Controllers\Api\V1\GoogleAccountController;
use App\Http\Controllers\Api\V1\GoogleImportController;
use App\Http\Controllers\Api\V1\GoogleSubmissionController;
use App\Http\Controllers\Api\V1\GradeConflictController;
use App\Http\Controllers\Api\V1\GradingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IndicatorSuggestionController;
use App\Http\Controllers\Api\V1\LayoutController;
use App\Http\Controllers\Api\V1\LearningResourceController;
use App\Http\Controllers\Api\V1\LessonPlanController;
use App\Http\Controllers\Api\V1\LoginCardController;
use App\Http\Controllers\Api\V1\MasteryController;
use App\Http\Controllers\Api\V1\MasterySummaryController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\PracticeItemController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ResponseController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RubricController;
use App\Http\Controllers\Api\V1\ScanController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\StudentAssignmentController;
use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentCourseController;
use App\Http\Controllers\Api\V1\StudentMasteryController;
use App\Http\Controllers\Api\V1\StudentPinController;
use App\Http\Controllers\Api\V1\StudentPracticeController;
use App\Http\Controllers\Api\V1\StudentResultController;
use App\Http\Controllers\Api\V1\StudentRetakeController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\SubmissionPageController;
use App\Http\Controllers\Api\V1\TeacherAttentionController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\WorksheetPrintController;
use Illuminate\Support\Facades\Route;

// Ids are 64-bit integers. Digit strings longer than 18 characters would
// overflow the typed controller parameter into a 500; they are simply not
// routes (404), like any non-numeric id.
Route::pattern('id', '[0-9]{1,18}');
Route::pattern('submission_id', '[0-9]{1,18}');
Route::pattern('item_id', '[0-9]{1,18}');
Route::pattern('student_id', '[0-9]{1,18}');

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

                // The home screen's "รอดำเนินการ" card (§19.9).
                Route::get('teacher/attention', TeacherAttentionController::class)->name('api.teacher.attention');

                Route::get('classrooms', [ClassroomController::class, 'index'])->name('api.classrooms.index');
                Route::post('classrooms', [ClassroomController::class, 'store'])->name('api.classrooms.store');
                Route::get('classrooms/{id}', [ClassroomController::class, 'show'])->name('api.classrooms.show');
                Route::patch('classrooms/{id}', [ClassroomController::class, 'update'])->name('api.classrooms.update');
                Route::post('classrooms/{id}/students', [ClassroomStudentController::class, 'store'])->name('api.classrooms.students.store');
                Route::get('classrooms/{id}/roster', [ClassroomStudentController::class, 'index'])->name('api.classrooms.roster');
                Route::post('classrooms/{id}/students/pending-pins', [ClassroomStudentController::class, 'pendingPins'])->name('api.classrooms.students.pending-pins');
                Route::post('classrooms/{id}/login-cards', [LoginCardController::class, 'storeForClassroom'])->name('api.classrooms.login-cards');

                Route::post('students/{id}/login-card', [LoginCardController::class, 'storeForStudent'])->name('api.students.login-card');
                Route::post('students/{id}/pin', [StudentPinController::class, 'store'])->name('api.students.pin');

                Route::get('login-card-prints/{id}', [LoginCardController::class, 'show'])->name('api.login-card-prints.show');
                Route::get('login-card-prints/{id}/file', [LoginCardController::class, 'download'])->name('api.login-card-prints.file');

                Route::get('skills', [SkillController::class, 'index'])->name('api.skills.index');
                // A teacher adds a missing indicator for the school, and edits it before any observation (§20.2).
                Route::post('skills', [SkillController::class, 'store'])->name('api.skills.store');
                Route::patch('skills/{id}', [SkillController::class, 'update'])->name('api.skills.update');
                Route::get('subjects', [SubjectController::class, 'index'])->name('api.subjects.index');

                // Courses, units and lesson plans of the teacher (§20.1, §20.7).
                Route::get('courses', [CourseController::class, 'index'])->name('api.courses.index');
                Route::post('courses', [CourseController::class, 'store'])->name('api.courses.store');
                Route::get('courses/{id}', [CourseController::class, 'show'])->name('api.courses.show');
                Route::patch('courses/{id}', [CourseController::class, 'update'])->name('api.courses.update');
                Route::delete('courses/{id}', [CourseController::class, 'destroy'])->name('api.courses.destroy');
                Route::put('courses/{id}/classrooms', [CourseController::class, 'classrooms'])->name('api.courses.classrooms');
                Route::put('courses/{id}/indicators', [CourseController::class, 'indicators'])->name('api.courses.indicators');
                Route::post('courses/{id}/units', [UnitController::class, 'store'])->name('api.courses.units.store');
                Route::patch('units/{id}', [UnitController::class, 'update'])->name('api.units.update');
                Route::delete('units/{id}', [UnitController::class, 'destroy'])->name('api.units.destroy');
                Route::put('units/{id}/indicators', [UnitController::class, 'indicators'])->name('api.units.indicators');
                Route::post('courses/{id}/lesson-plans', [LessonPlanController::class, 'store'])->name('api.courses.lesson-plans.store');
                Route::get('lesson-plans/{id}', [LessonPlanController::class, 'show'])->name('api.lesson-plans.show');
                Route::patch('lesson-plans/{id}', [LessonPlanController::class, 'update'])->name('api.lesson-plans.update');
                Route::delete('lesson-plans/{id}', [LessonPlanController::class, 'destroy'])->name('api.lesson-plans.destroy');
                Route::put('lesson-plans/{id}/indicators', [LessonPlanController::class, 'indicators'])->name('api.lesson-plans.indicators');
                // From documents (§20.1): read once (queues a Gemini job unless the school read the same
                // files before, so it is throttled), estimate for free, then import what the teacher confirmed.
                Route::post('courses/extract', [CourseDocumentController::class, 'extract'])->middleware('throttle:course-extract')->name('api.courses.extract');
                Route::post('courses/extract/estimate', [CourseDocumentController::class, 'estimate'])->name('api.courses.extract.estimate');
                Route::post('courses/import', [CourseDocumentController::class, 'import'])->name('api.courses.import');
                // The roll-up by standard or unit with coverage, per student or per classroom (§20.3).
                Route::get('courses/{id}/mastery-summary', MasterySummaryController::class)->name('api.courses.mastery-summary');
                // Chart data of §20.4: (1) progress, (2) % passing, (4) score distribution, (5) plan progress.
                Route::get('courses/{id}/plan-progress', [ChartController::class, 'planProgress'])->name('api.courses.plan-progress');
                Route::get('classrooms/{id}/indicator-pass-rate', [ChartController::class, 'passRate'])->name('api.classrooms.indicator-pass-rate');
                Route::get('students/{id}/indicator-progress', [ChartController::class, 'studentProgress'])->name('api.students.indicator-progress');
                Route::get('assignments/{id}/score-distribution', [ChartController::class, 'scoreDistribution'])->name('api.assignments.score-distribution');
                // The per-student analysis (§20.5): code-computed strengths and areas, Gemini's texts
                // (nightly batch, or "วิเคราะห์ตอนนี้" which calls Gemini in the request, so it is
                // throttled), the teacher's edits and approval.
                Route::get('classrooms/{id}/analyses', [AnalysisController::class, 'classroom'])->name('api.classrooms.analyses');
                Route::get('students/{id}/analysis', [AnalysisController::class, 'show'])->name('api.students.analysis');
                Route::post('students/{id}/analysis/run', [AnalysisController::class, 'run'])->middleware('throttle:analysis-now')->name('api.students.analysis.run');
                Route::patch('analyses/{id}', [AnalysisController::class, 'update'])->name('api.analyses.update');
                Route::post('analyses/{id}/approve', [AnalysisController::class, 'approve'])->name('api.analyses.approve');

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
                // "ตรวจใหม่ทั้งห้อง" after the answer key changed (§21.13): queues Gemini jobs, so it is throttled;
                // the estimate is free.
                Route::post('assignments/{id}/regrade', [GradingController::class, 'regrade'])->middleware('throttle:regrade')->name('api.assignments.regrade');
                Route::post('assignments/{id}/regrade/estimate', [GradingController::class, 'regradeEstimate'])->name('api.assignments.regrade.estimate');
                // Indicators of the questions (§20.3): Gemini suggests from the linked lesson plan
                // (queues a job that costs money, so it is throttled), the teacher confirms.
                Route::post('assignments/{id}/indicator-suggestions', [IndicatorSuggestionController::class, 'store'])->middleware('throttle:indicator-suggest')->name('api.assignments.indicator-suggestions.store');
                Route::get('assignments/{id}/indicator-suggestions', [IndicatorSuggestionController::class, 'index'])->name('api.assignments.indicator-suggestions.index');
                Route::put('assignments/{id}/indicator-mapping', [IndicatorSuggestionController::class, 'mapping'])->name('api.assignments.indicator-mapping');

                // The teacher's answer key: typed, read from documents or drafted by AI, then approved (§19.5).
                Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:documents')->name('api.documents.store');
                Route::get('document-extractions/{id}', [DocumentController::class, 'extraction'])->name('api.document-extractions.show');
                Route::get('assignments/{id}/answer-key', [AnswerKeyController::class, 'show'])->name('api.assignments.answer-key.show');
                // Queue a Gemini job (costs money) unless the school read the same files before.
                Route::post('assignments/{id}/answer-key/extract', [AnswerKeyController::class, 'extract'])->middleware('throttle:answer-key')->name('api.assignments.answer-key.extract');
                Route::post('assignments/{id}/answer-key/draft', [AnswerKeyController::class, 'draft'])->middleware('throttle:answer-key')->name('api.assignments.answer-key.draft');
                // Free: what extract/draft would cost for the picked files and range, and whether it is cached.
                Route::post('assignments/{id}/answer-key/estimate', [AnswerKeyController::class, 'estimate'])->name('api.assignments.answer-key.estimate');
                Route::post('assignments/{id}/answer-key/approve', [AnswerKeyController::class, 'approve'])->name('api.assignments.answer-key.approve');

                // Exams: sections, questions, images, the answer key and the shuffled versions (§22.15).
                Route::get('exams/{id}', [ExamController::class, 'show'])->name('api.exams.show');
                Route::post('exams/{id}/sections', [ExamController::class, 'storeSection'])->name('api.exams.sections.store');
                Route::post('exams/{id}/questions/approve', [ExamController::class, 'approveQuestions'])->name('api.exams.questions.approve');
                Route::put('exams/{id}/answer-key', [ExamController::class, 'answerKey'])->name('api.exams.answer-key');
                Route::get('exams/{id}/versions', [ExamController::class, 'versions'])->name('api.exams.versions');
                Route::post('exams/{id}/versions/reshuffle', [ExamController::class, 'reshuffle'])->name('api.exams.versions.reshuffle');
                Route::post('exams/{id}/unlock-structure', [ExamController::class, 'unlockStructure'])->name('api.exams.unlock-structure');
                Route::patch('exam-sections/{id}', [ExamSectionController::class, 'update'])->name('api.exam-sections.update');
                Route::delete('exam-sections/{id}', [ExamSectionController::class, 'destroy'])->name('api.exam-sections.destroy');
                Route::post('exam-sections/{id}/questions', [ExamSectionController::class, 'storeQuestion'])->name('api.exam-sections.questions.store');
                Route::get('questions/{id}/image', [ExamImageController::class, 'showQuestion'])->name('api.questions.image.show');
                Route::post('questions/{id}/image', [ExamImageController::class, 'storeQuestion'])->middleware('throttle:exam-images')->name('api.questions.image.store');
                Route::delete('questions/{id}/image', [ExamImageController::class, 'destroyQuestion'])->name('api.questions.image.destroy');
                Route::get('question-options/{id}/image', [ExamImageController::class, 'showOption'])->name('api.question-options.image.show');
                Route::post('question-options/{id}/image', [ExamImageController::class, 'storeOption'])->middleware('throttle:exam-images')->name('api.question-options.image.store');
                Route::delete('question-options/{id}/image', [ExamImageController::class, 'destroyOption'])->name('api.question-options.image.destroy');

                Route::patch('questions/{id}', [QuestionController::class, 'update'])->name('api.questions.update');
                Route::delete('questions/{id}', [QuestionController::class, 'destroy'])->name('api.questions.destroy');
                Route::post('questions/{id}/rubric/draft', [RubricController::class, 'draft'])->name('api.questions.rubric.draft');
                Route::put('questions/{id}/rubric', [RubricController::class, 'update'])->name('api.questions.rubric.update');

                Route::get('worksheet-prints/{id}', [WorksheetPrintController::class, 'show'])->name('api.worksheet-prints.show');
                Route::get('worksheet-prints/{id}/file', [WorksheetPrintController::class, 'download'])->name('api.worksheet-prints.file');

                // Scans (§9.4): upload, confirm a rescan of a published page, page image.
                Route::post('scans', [ScanController::class, 'store'])->name('api.scans.store');
                // A student's hand-in uploaded by the teacher from files (§19.6): whole-page path.
                Route::post('assignments/{id}/students/{student_id}/pages', [SubmissionPageController::class, 'store'])->middleware('throttle:page-upload')->name('api.assignments.students.pages');
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
                Route::post('submissions/{id}/grade', [SubmissionController::class, 'grade'])->name('api.submissions.grade');
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
                    // Grade conflicts and late hand-ins (§19.3): no Google call in the request.
                    Route::get('assignments/{id}/grade-conflicts', [GradeConflictController::class, 'index'])->name('api.assignments.grade-conflicts');
                    Route::post('grade-conflicts/{id}/resolve', [GradeConflictController::class, 'resolve'])->name('api.grade-conflicts.resolve');
                    Route::post('google-submissions/{id}/accept-late', [GoogleSubmissionController::class, 'acceptLate'])->name('api.google-submissions.accept-late');
                    // Private announcements of published results (§19.7): the job calls Google.
                    Route::get('assignments/{id}/google-feedback', [AssignmentGoogleController::class, 'feedback'])->name('api.assignments.google-feedback');
                    Route::post('assignments/{id}/google-feedback/retry', [AssignmentGoogleController::class, 'retryFeedback'])->name('api.assignments.google-feedback.retry');
                    Route::middleware('throttle:google')->group(function () {
                        Route::post('google/connect', [GoogleAccountController::class, 'connect'])->name('api.google.connect');
                        Route::post('google/oauth/url', [GoogleAccountController::class, 'oauthUrl'])->name('api.google.oauth-url');
                        Route::get('google/courses', [GoogleAccountController::class, 'courses'])->name('api.google.courses');
                        // Import a classroom from a course and sync its roster (§19.2).
                        Route::get('google/courses/{course_id}/import-preview', [GoogleImportController::class, 'preview'])->where('course_id', '[A-Za-z0-9_-]{1,64}')->name('api.google.courses.import-preview');
                        Route::post('classrooms/import-google', [GoogleImportController::class, 'import'])->name('api.classrooms.import-google');
                        Route::post('classrooms/{id}/google-roster/sync', [GoogleImportController::class, 'syncRoster'])->name('api.classrooms.google-roster.sync');
                        // "ซิงก์ตอนนี้": one sync round of the classroom (§19.3).
                        Route::post('classrooms/{id}/google-sync', [ClassroomGoogleController::class, 'syncNow'])->name('api.classrooms.google-sync');
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

            // Student-only (§9.7, §19.6): their own published results and hand-ins.
            Route::middleware('role:student')->prefix('student')->group(function () {
                Route::get('results', [StudentResultController::class, 'index'])->name('api.student.results.index');
                Route::get('results/{submission_id}', [StudentResultController::class, 'show'])->name('api.student.results.show');
                Route::post('responses/{id}/appeal', [StudentResultController::class, 'appeal'])->middleware('throttle:appeal')->name('api.student.responses.appeal');
                Route::get('mastery', StudentMasteryController::class)->name('api.student.mastery');
                // Their own courses and roll-up only (§20.4, §20.9).
                Route::get('courses', [StudentCourseController::class, 'index'])->name('api.student.courses.index');
                Route::get('courses/{id}/mastery-summary', [StudentCourseController::class, 'summary'])->name('api.student.courses.mastery-summary');
                Route::get('indicator-progress', [ChartController::class, 'myProgress'])->name('api.student.indicator-progress');
                // Only the analysis texts the teacher shared, never the teacher's version (§20.5).
                Route::get('analysis', [AnalysisController::class, 'mine'])->name('api.student.analysis');
                Route::get('retake-requests', [StudentRetakeController::class, 'index'])->name('api.student.retake-requests');
                // Hand in from the app (§19.6): open assignments and a whole-page submission.
                Route::get('assignments', [StudentAssignmentController::class, 'index'])->name('api.student.assignments.index');
                Route::post('assignments/{id}/submission', [StudentAssignmentController::class, 'submit'])->middleware('throttle:student-submission')->name('api.student.assignments.submission');
                // Practice (§9.7, §14.1): recommendations and graded attempts.
                Route::get('practice', [StudentPracticeController::class, 'index'])->name('api.student.practice.index');
                Route::post('practice/{item_id}/attempts', [StudentPracticeController::class, 'attempt'])->middleware('throttle:practice-attempt')->name('api.student.practice.attempts');
            });

            // Teacher or student (§9.5, §9.7); ResponsePolicy::viewCrop decides.
            Route::middleware('role:teacher,student')->group(function () {
                Route::get('responses/{id}/crop', [ResponseController::class, 'crop'])->name('api.responses.crop');
                Route::get('submission-pages/{id}/image', [SubmissionPageController::class, 'image'])->name('api.submission-pages.image');
                // On-device model distribution (§9.8).
                Route::get('ml/models/active', [ModelController::class, 'active'])->name('api.ml.models.active');
                Route::get('ml/models/{id}/file', [ModelController::class, 'file'])->name('api.ml.models.file');
            });
        });
    });
});
