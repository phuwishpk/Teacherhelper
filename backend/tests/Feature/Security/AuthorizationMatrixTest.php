<?php

namespace Tests\Feature\Security;

use App\Models\LearningResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Google\GoogleFixtures;
use Tests\TestCase;

/**
 * DESIGN §9 "every endpoint passes a policy" and §16.1 (a), as one table:
 * every /api/v1 route is called by every kind of actor with an empty body
 * (POST /scans with a validly signed page), and each cell states the answer.
 *
 *   guest      no token                                   -> 401
 *   student    a student's token on a teacher route        -> 403 (ability)
 *   teacher    a teacher's token on a student route        -> 403 (ability)
 *   same       a teacher of the same school, not the owner -> 403 or 404
 *   other      a teacher of another school                 -> 404 (or 403 where the id is not looked up first)
 *   peer       a classmate of the student who owns the row -> 404
 *   disabled   a disabled account with a valid token        -> 403 account_not_active
 *   admin      an admin token has no API abilities          -> 403
 *   owner      the teacher / student who owns the row       -> anything but 401/403/404 (422 with the empty body counts)
 *
 * test_every_api_route_is_in_the_matrix fails when a route is added without
 * a row here, so a new endpoint cannot ship without stating who may call it.
 */
class AuthorizationMatrixTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use SecurityWorld;

    /** Public routes, exercised by TeacherAuthTest and StudentAuthTest instead. */
    private const PUBLIC = ['api.health', 'api.auth.teacher.register', 'api.auth.teacher.login', 'api.auth.student.qr', 'api.auth.student.pin'];

    private const OK = 'ok';

    /**
     * Teacher-only routes: [method, uri, same-school teacher, other-school teacher].
     * Students and admins get 403 on all of them.
     */
    private const TEACHER = [
        'api.me.ai-key.show' => ['GET', 'me/ai-key', self::OK, self::OK],
        'api.me.ai-key.update' => ['PUT', 'me/ai-key', self::OK, self::OK],
        'api.me.ai-key.destroy' => ['DELETE', 'me/ai-key', self::OK, self::OK],
        'api.classrooms.index' => ['GET', 'classrooms', self::OK, self::OK],
        'api.classrooms.store' => ['POST', 'classrooms', self::OK, self::OK],
        'api.classrooms.show' => ['GET', 'classrooms/{classroom}', 404, 404],
        'api.classrooms.update' => ['PATCH', 'classrooms/{classroom}', 404, 404],
        'api.classrooms.students.store' => ['POST', 'classrooms/{classroom}/students', 404, 404],
        'api.classrooms.roster' => ['GET', 'classrooms/{classroom}/roster', 404, 404],
        'api.classrooms.students.pending-pins' => ['POST', 'classrooms/{classroom}/students/pending-pins', 404, 404],
        'api.classrooms.login-cards' => ['POST', 'classrooms/{classroom}/login-cards', 404, 404],
        'api.students.login-card' => ['POST', 'students/{student}/login-card', 403, 404],
        'api.students.pin' => ['POST', 'students/{student}/pin', 403, 404],
        'api.login-card-prints.show' => ['GET', 'login-card-prints/{card_print}', 403, 404],
        'api.login-card-prints.file' => ['GET', 'login-card-prints/{card_print}/file', 403, 404],
        'api.skills.index' => ['GET', 'skills', self::OK, self::OK],
        'api.skills.store' => ['POST', 'skills', self::OK, self::OK],
        // A teacher-added indicator is shared by the school but edited by its creator only (§20.2).
        'api.skills.update' => ['PATCH', 'skills/{teacher_skill}', 403, 404],
        'api.subjects.index' => ['GET', 'subjects', self::OK, self::OK],
        // A course, its units and plans belong to their creator only (§20.9): others get 404.
        'api.courses.index' => ['GET', 'courses', self::OK, self::OK],
        'api.courses.store' => ['POST', 'courses', self::OK, self::OK],
        'api.courses.show' => ['GET', 'courses/{own_course}', 404, 404],
        'api.courses.update' => ['PATCH', 'courses/{own_course}', 404, 404],
        'api.courses.destroy' => ['DELETE', 'courses/{own_course}', 404, 404],
        'api.courses.classrooms' => ['PUT', 'courses/{own_course}/classrooms', 404, 404],
        'api.courses.indicators' => ['PUT', 'courses/{own_course}/indicators', 404, 404],
        'api.courses.units.store' => ['POST', 'courses/{own_course}/units', 404, 404],
        'api.units.update' => ['PATCH', 'units/{unit}', 404, 404],
        'api.units.destroy' => ['DELETE', 'units/{unit}', 404, 404],
        'api.units.indicators' => ['PUT', 'units/{unit}/indicators', 404, 404],
        'api.courses.lesson-plans.store' => ['POST', 'courses/{own_course}/lesson-plans', 404, 404],
        'api.lesson-plans.show' => ['GET', 'lesson-plans/{lesson_plan}', 404, 404],
        'api.lesson-plans.update' => ['PATCH', 'lesson-plans/{lesson_plan}', 404, 404],
        'api.lesson-plans.destroy' => ['DELETE', 'lesson-plans/{lesson_plan}', 404, 404],
        'api.lesson-plans.indicators' => ['PUT', 'lesson-plans/{lesson_plan}/indicators', 404, 404],
        'api.courses.extract' => ['POST', 'courses/extract', self::OK, self::OK],
        'api.courses.extract.estimate' => ['POST', 'courses/extract/estimate', self::OK, self::OK],
        'api.courses.import' => ['POST', 'courses/import', self::OK, self::OK],
        'api.courses.mastery-summary' => ['GET', 'courses/{own_course}/mastery-summary', 404, 404],
        'api.courses.plan-progress' => ['GET', 'courses/{own_course}/plan-progress', 404, 404],
        // The gradebook (§23.11, §23.12): the owner's courses and items only; another teacher gets 404.
        'api.gradebook.templates' => ['GET', 'gradebook/templates', self::OK, self::OK],
        'api.courses.gradebook.settings' => ['GET', 'courses/{own_course}/gradebook/settings', 404, 404],
        'api.courses.gradebook.categories' => ['PUT', 'courses/{own_course}/gradebook/categories', 404, 404],
        'api.courses.gradebook.cutoffs' => ['PUT', 'courses/{own_course}/gradebook/cutoffs', 404, 404],
        'api.courses.gradebook.show' => ['GET', 'courses/{own_course}/gradebook', 404, 404],
        'api.courses.gradebook.special-grades' => ['PUT', 'courses/{own_course}/gradebook/special-grades', 404, 404],
        'api.courses.gradebook.publish' => ['POST', 'courses/{own_course}/gradebook/publish', 404, 404],
        'api.courses.gradebook.withdraw' => ['DELETE', 'courses/{own_course}/gradebook/publish', 404, 404],
        'api.courses.gradebook.export' => ['GET', 'courses/{own_course}/gradebook/export', 404, 404],
        'api.courses.gradebook-items.store' => ['POST', 'courses/{own_course}/gradebook-items', 404, 404],
        'api.gradebook-items.update' => ['PATCH', 'gradebook-items/{gradebook_item}', 404, 404],
        'api.gradebook-items.destroy' => ['DELETE', 'gradebook-items/{gradebook_item}', 404, 404],
        'api.gradebook-items.scores' => ['PUT', 'gradebook-items/{gradebook_item}/scores', 404, 404],
        'api.gradebook-items.fill-full' => ['POST', 'gradebook-items/{gradebook_item}/fill-full', 404, 404],
        'api.assignments.gradebook-scores' => ['PUT', 'assignments/{assignment}/gradebook-scores', 404, 404],
        'api.assignments.gradebook-scores.fill-full' => ['POST', 'assignments/{assignment}/gradebook-scores/fill-full', 404, 404],
        'api.assignments.index' => ['GET', 'assignments', self::OK, self::OK],
        'api.assignments.store' => ['POST', 'assignments', self::OK, self::OK],
        'api.assignments.show' => ['GET', 'assignments/{assignment}', 404, 404],
        'api.assignments.update' => ['PATCH', 'assignments/{assignment}', 404, 404],
        'api.assignments.destroy' => ['DELETE', 'assignments/{assignment}', 404, 404],
        'api.assignments.questions.store' => ['POST', 'assignments/{assignment}/questions', 404, 404],
        'api.assignments.layout.store' => ['POST', 'assignments/{assignment}/layout', 404, 404],
        'api.assignments.layouts.index' => ['GET', 'assignments/{assignment}/layouts', 404, 404],
        'api.assignments.worksheets.store' => ['POST', 'assignments/{assignment}/worksheets', 404, 404],
        'api.assignments.requeue-missing-key' => ['POST', 'assignments/{assignment}/requeue-missing-key', 404, 404],
        'api.assignments.regrade' => ['POST', 'assignments/{assignment}/regrade', 404, 404],
        'api.assignments.regrade.estimate' => ['POST', 'assignments/{assignment}/regrade/estimate', 404, 404],
        'api.assignments.indicator-suggestions.store' => ['POST', 'assignments/{assignment}/indicator-suggestions', 404, 404],
        'api.assignments.indicator-suggestions.index' => ['GET', 'assignments/{assignment}/indicator-suggestions', 404, 404],
        'api.assignments.indicator-mapping' => ['PUT', 'assignments/{assignment}/indicator-mapping', 404, 404],
        'api.documents.store' => ['POST', 'documents', self::OK, self::OK],
        // An answer-key read (the fixture) is shown only to a teacher tied to it (§19.9, §22.17):
        // a colleague who guesses the id gets 404. Course reads stay school-wide (CourseDocumentTest).
        'api.document-extractions.show' => ['GET', 'document-extractions/{extraction}', 404, 404],
        'api.assignments.answer-key.show' => ['GET', 'assignments/{assignment}/answer-key', 404, 404],
        'api.assignments.answer-key.extract' => ['POST', 'assignments/{assignment}/answer-key/extract', 404, 404],
        'api.assignments.answer-key.draft' => ['POST', 'assignments/{assignment}/answer-key/draft', 404, 404],
        'api.assignments.answer-key.estimate' => ['POST', 'assignments/{assignment}/answer-key/estimate', 404, 404],
        'api.assignments.answer-key.approve' => ['POST', 'assignments/{assignment}/answer-key/approve', 404, 404],
        // Exams (§22.15, §22.17): the owner's exams only; another teacher gets 404, even in the same school.
        'api.exams.show' => ['GET', 'exams/{exam}', 404, 404],
        'api.exams.sections.store' => ['POST', 'exams/{exam}/sections', 404, 404],
        'api.exams.questions.approve' => ['POST', 'exams/{exam}/questions/approve', 404, 404],
        'api.exams.answer-key' => ['PUT', 'exams/{exam}/answer-key', 404, 404],
        'api.exams.versions' => ['GET', 'exams/{exam}/versions', 404, 404],
        'api.exams.versions.reshuffle' => ['POST', 'exams/{exam}/versions/reshuffle', 404, 404],
        'api.exams.unlock-structure' => ['POST', 'exams/{exam}/unlock-structure', 404, 404],
        'api.exams.prints.store' => ['POST', 'exams/{exam}/prints', 404, 404],
        'api.exams.scan-kit' => ['GET', 'exams/{exam}/scan-kit', 404, 404],
        'api.exams.sheet-status' => ['GET', 'exams/{exam}/sheet-status', 404, 404],
        'api.exams.key-sheet-read' => ['POST', 'exams/{exam}/key-sheet-read', 404, 404],
        // The QR names the exam; the scan policy answers 403 like POST /scans.
        'api.exam-sheets.store' => ['POST', 'exam-sheets', 403, 403],
        // Review of a scanned page (§22.11): looked up among the owner's exams only.
        'api.exam-sheets.version' => ['POST', 'exam-sheets/{exam_scan}/version', 404, 404],
        'api.exam-responses.resolve' => ['POST', 'exam-responses/{exam_response}/resolve', 404, 404],
        'api.exam-sections.update' => ['PATCH', 'exam-sections/{exam_section}', 404, 404],
        'api.exam-sections.destroy' => ['DELETE', 'exam-sections/{exam_section}', 404, 404],
        'api.exam-sections.questions.store' => ['POST', 'exam-sections/{exam_section}/questions', 404, 404],
        'api.questions.image.show' => ['GET', 'questions/{exam_question}/image', 404, 404],
        'api.questions.image.store' => ['POST', 'questions/{exam_question}/image', 404, 404],
        'api.questions.image.destroy' => ['DELETE', 'questions/{exam_question}/image', 404, 404],
        'api.question-options.image.show' => ['GET', 'question-options/{exam_option}/image', 404, 404],
        'api.question-options.image.store' => ['POST', 'question-options/{exam_option}/image', 404, 404],
        'api.question-options.image.destroy' => ['DELETE', 'question-options/{exam_option}/image', 404, 404],
        // Reading an exam file and copying questions (§22.4, §22.17): the owner's exams only.
        'api.exams.import' => ['POST', 'exams/{exam}/import', 404, 404],
        'api.exams.import.estimate' => ['POST', 'exams/{exam}/import/estimate', 404, 404],
        'api.exams.page-images.store' => ['POST', 'exams/{exam}/page-images', 404, 404],
        'api.exam-page-images.show' => ['GET', 'exam-page-images/{exam_page_image}', 404, 404],
        // Even a teacher of the same school who guesses the document id gets 404 (§22.4).
        'api.exams.documents.file' => ['GET', 'exams/{exam}/documents/{exam_document}/file', 404, 404],
        'api.questions.figure' => ['PUT', 'questions/{exam_question}/figure', 404, 404],
        'api.question-options.figure' => ['PUT', 'question-options/{exam_option}/figure', 404, 404],
        // The library lists the caller's own exams only: anyone gets an answer, never others' questions.
        'api.teacher.exam-questions' => ['GET', 'teacher/exam-questions', self::OK, self::OK],
        'api.exams.copy-questions' => ['POST', 'exams/{exam}/copy-questions', 404, 404],
        'api.questions.update' => ['PATCH', 'questions/{question}', 404, 404],
        'api.questions.destroy' => ['DELETE', 'questions/{question}', 404, 404],
        'api.questions.rubric.draft' => ['POST', 'questions/{question}/rubric/draft', 404, 404],
        'api.questions.rubric.update' => ['PUT', 'questions/{question}/rubric', 404, 404],
        'api.worksheet-prints.show' => ['GET', 'worksheet-prints/{print}', 403, 404],
        'api.worksheet-prints.file' => ['GET', 'worksheet-prints/{print}/file', 403, 404],
        'api.scans.store' => ['POST', 'scans', 403, 403],
        'api.assignments.students.pages' => ['POST', 'assignments/{assignment}/students/{student}/pages', 404, 404],
        'api.scans.confirm-replace' => ['POST', 'scans/{scan}/confirm-replace', 403, 404],
        'api.scans.page' => ['GET', 'scans/{scan}/page', 403, 404],
        'api.assignments.review-queue' => ['GET', 'assignments/{assignment}/review-queue', 404, 404],
        'api.assignments.approve-confident' => ['POST', 'assignments/{assignment}/approve-confident', 404, 404],
        'api.assignments.publish' => ['POST', 'assignments/{assignment}/publish', 404, 404],
        'api.responses.show' => ['GET', 'responses/{response}', 403, 404],
        'api.responses.update' => ['PATCH', 'responses/{response}', 403, 404],
        'api.responses.regenerate-explanation' => ['POST', 'responses/{response}/regenerate-explanation', 403, 404],
        'api.submissions.publish' => ['POST', 'submissions/{submission}/publish', 403, 404],
        'api.submissions.grade' => ['POST', 'submissions/{submission}/grade', 403, 404],
        'api.appeals.index' => ['GET', 'appeals', self::OK, self::OK],
        'api.appeals.update' => ['PATCH', 'appeals/{appeal}', 404, 404],
        'api.practice-items.index' => ['GET', 'practice-items', self::OK, self::OK],
        'api.practice-items.store' => ['POST', 'practice-items', self::OK, self::OK],
        'api.practice-items.update' => ['PATCH', 'practice-items/{item}', self::OK, 404],
        'api.skills.practice-items.generate' => ['POST', 'skills/{skill}/practice-items/generate', self::OK, 404],
        'api.skills.resources.index' => ['GET', 'skills/{skill}/resources', self::OK, 404],
        'api.skills.resources.store' => ['POST', 'skills/{skill}/resources', self::OK, 404],
        'api.resources.update' => ['PATCH', 'resources/{resource}', self::OK, 404],
        'api.resources.destroy' => ['DELETE', 'resources/{resource}', self::OK, 404],
        'api.assignments.analytics' => ['GET', 'assignments/{assignment}/analytics', 404, 404],
        'api.classrooms.mastery' => ['GET', 'classrooms/{classroom}/mastery', 404, 404],
        'api.students.mastery' => ['GET', 'students/{student}/mastery', 404, 404],
        // Chart data of §20.4: the teacher's own classrooms, students and assignments only.
        'api.classrooms.indicator-pass-rate' => ['GET', 'classrooms/{classroom}/indicator-pass-rate', 404, 404],
        'api.students.indicator-progress' => ['GET', 'students/{student}/indicator-progress', 404, 404],
        // The per-student analysis of the teacher's own classrooms only (§20.5, §20.9).
        'api.classrooms.analyses' => ['GET', 'classrooms/{classroom}/analyses', 404, 404],
        'api.students.analysis' => ['GET', 'students/{student}/analysis', 404, 404],
        'api.students.analysis.run' => ['POST', 'students/{student}/analysis/run', 404, 404],
        'api.analyses.update' => ['PATCH', 'analyses/{analysis}', 404, 404],
        'api.analyses.approve' => ['POST', 'analyses/{analysis}/approve', 404, 404],
        'api.assignments.score-distribution' => ['GET', 'assignments/{assignment}/score-distribution', 404, 404],
        'api.google.status' => ['GET', 'google/status', self::OK, self::OK],
        'api.google.disconnect' => ['DELETE', 'google/disconnect', self::OK, self::OK],
        'api.google.connect' => ['POST', 'google/connect', self::OK, self::OK],
        'api.google.oauth-url' => ['POST', 'google/oauth/url', self::OK, self::OK],
        'api.google.courses' => ['GET', 'google/courses', self::OK, self::OK],
        'api.google.courses.import-preview' => ['GET', 'google/courses/{course}/import-preview', self::OK, self::OK],
        'api.classrooms.import-google' => ['POST', 'classrooms/import-google', self::OK, self::OK],
        'api.classrooms.google-roster.sync' => ['POST', 'classrooms/{classroom}/google-roster/sync', 404, 404],
        'api.classrooms.google-link.store' => ['POST', 'classrooms/{classroom}/google-link', 404, 404],
        'api.classrooms.google-link.destroy' => ['DELETE', 'classrooms/{classroom}/google-link', 404, 404],
        'api.classrooms.google-roster.show' => ['GET', 'classrooms/{classroom}/google-roster', 404, 404],
        'api.classrooms.google-roster.update' => ['PUT', 'classrooms/{classroom}/google-roster', 404, 404],
        'api.assignments.google-post' => ['POST', 'assignments/{assignment}/google-post', 404, 404],
        'api.assignments.google-submissions' => ['GET', 'assignments/{assignment}/google-submissions', 404, 404],
        'api.assignments.google-grades.retry' => ['POST', 'assignments/{assignment}/google-grades/retry', 404, 404],
        'api.assignments.google-feedback' => ['GET', 'assignments/{assignment}/google-feedback', 404, 404],
        'api.assignments.google-feedback.retry' => ['POST', 'assignments/{assignment}/google-feedback/retry', 404, 404],
        'api.google-submissions.return' => ['POST', 'google-submissions/{import}/return', 404, 404],
        'api.google-submissions.accept-late' => ['POST', 'google-submissions/{import}/accept-late', 404, 404],
        'api.classrooms.google-sync' => ['POST', 'classrooms/{classroom}/google-sync', 404, 404],
        'api.assignments.grade-conflicts' => ['GET', 'assignments/{assignment}/grade-conflicts', 404, 404],
        'api.grade-conflicts.resolve' => ['POST', 'grade-conflicts/{conflict}/resolve', 404, 404],
        'api.teacher.attention' => ['GET', 'teacher/attention', self::OK, self::OK],
    ];

    /**
     * Student-only routes: [method, uri, classmate (peer), student of another school].
     * The owner is student A2 (published result); teachers and admins get 403.
     */
    private const STUDENT = [
        'api.student.results.index' => ['GET', 'student/results', self::OK, self::OK],
        'api.student.results.show' => ['GET', 'student/results/{submission_a2}', 404, 404],
        'api.student.responses.appeal' => ['POST', 'student/responses/{response_a2}/appeal', 404, 404],
        'api.student.mastery' => ['GET', 'student/mastery', self::OK, self::OK],
        'api.student.courses.index' => ['GET', 'student/courses', self::OK, self::OK],
        // A classmate reads the same course (their own values only); another school's student does not see it.
        'api.student.courses.mastery-summary' => ['GET', 'student/courses/{own_course}/mastery-summary', self::OK, 404],
        // Their own row of the latest publication only (§23.12): a classmate reads their own row, another school's student nothing.
        'api.student.grades.index' => ['GET', 'student/grades', self::OK, self::OK],
        'api.student.courses.grade' => ['GET', 'student/courses/{own_course}/grade', self::OK, 404],
        // Always the signed-in student's own lines (§20.9).
        'api.student.indicator-progress' => ['GET', 'student/indicator-progress', self::OK, self::OK],
        // Always the signed-in student's own shared texts (§20.5).
        'api.student.analysis' => ['GET', 'student/analysis', self::OK, self::OK],
        'api.student.retake-requests' => ['GET', 'student/retake-requests', self::OK, self::OK],
        'api.student.assignments.index' => ['GET', 'student/assignments', self::OK, self::OK],
        // A classmate hands in to the same assignment as themself (their own work).
        'api.student.assignments.submission' => ['POST', 'student/assignments/{assignment}/submission', self::OK, 404],
        'api.student.practice.index' => ['GET', 'student/practice', self::OK, self::OK],
        'api.student.practice.attempts' => ['POST', 'student/practice/{item}/attempts', self::OK, 404],
    ];

    /**
     * Routes both roles reach: [method, uri, owner student (A, unpublished), student B, same-school teacher, other-school teacher].
     */
    private const SHARED = [
        'api.responses.crop' => ['GET', 'responses/{response}/crop', 404, 404, 403, 404],
        'api.submission-pages.image' => ['GET', 'submission-pages/{page}/image', 404, 404, 403, 404],
        'api.ml.models.active' => ['GET', 'ml/models/active', self::OK, self::OK, self::OK, self::OK],
        'api.ml.models.file' => ['GET', 'ml/models/{model}/file', self::OK, self::OK, self::OK, self::OK],
    ];

    /** Routes for every logged-in account. */
    private const COMMON = [
        'api.auth.logout' => ['POST', 'auth/logout'],
        'api.me' => ['GET', 'me'],
        'api.devices.store' => ['POST', 'devices'],
    ];

    /** @return array<string, array{string}> */
    public static function teacherRoutes(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(array_keys(self::TEACHER), array_keys(self::TEACHER)));
    }

    /** @return array<string, array{string}> */
    public static function studentRoutes(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(array_keys(self::STUDENT), array_keys(self::STUDENT)));
    }

    /** @return array<string, array{string}> */
    public static function sharedRoutes(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(array_keys(self::SHARED), array_keys(self::SHARED)));
    }

    /** @return array<string, array{string}> */
    public static function commonRoutes(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(array_keys(self::COMMON), array_keys(self::COMMON)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSecurityWorld();
        $this->configureGoogle();
        // Every Google call answers with an empty list, so an owner's request
        // reaches its normal end instead of a stray-request error.
        $this->fakeGoogle([
            'classroom.googleapis.com/*' => Http::response(['courses' => [], 'students' => [], 'studentSubmissions' => []]),
            'www.googleapis.com/*' => Http::response([]),
        ]);
    }

    public function test_every_api_route_is_in_the_matrix(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                $this->assertNotNull($route->getName(), 'every API route is named: '.$route->uri());
                $routes[$route->getName()] = $route->methods()[0].' '.substr($route->uri(), strlen('api/v1/'));
            }
        }
        $matrix = [...self::TEACHER, ...self::STUDENT, ...self::SHARED, ...self::COMMON];
        $expected = [...self::PUBLIC, ...array_keys($matrix)];
        sort($expected);
        $actual = array_keys($routes);
        sort($actual);

        $this->assertSame($expected, $actual, 'add every new /api/v1 route to the authorization matrix');

        foreach ($matrix as $name => [$method, $uri]) {
            $this->assertSame($method.' '.preg_replace('/\{[a-z_0-9]+\}/', '{id}', $uri), preg_replace('/\{[a-z_0-9]+\}/', '{id}', $routes[$name]), $name);
        }
    }

    #[DataProvider('teacherRoutes')]
    public function test_teacher_route(string $name): void
    {
        [$method, $uri, $sameSchool, $otherSchool] = self::TEACHER[$name];

        $this->expect($this->call_($method, $uri, null), 401, 'guest');
        $this->expect($this->call_($method, $uri, $this->studentA), 403, 'student');
        $this->expect($this->call_($method, $uri, $this->admin), 403, 'admin');
        $this->expect($this->call_($method, $uri, $this->disabledTeacherA), 403, 'disabled teacher', 'account_not_active');
        $this->expect($this->call_($method, $uri, $this->teacherB), $otherSchool, 'teacher of another school');
        $this->expect($this->call_($method, $uri, $this->teacherA2), $sameSchool, 'teacher of the same school');
        if ($method === 'DELETE' && $sameSchool === self::OK) {
            $this->restoreDeletedBy($name);
        }
        $this->expect($this->call_($method, $uri, $this->teacherA), self::OK, 'owner');
    }

    /** A school-wide row a colleague may delete is put back so the owner's call is meaningful. */
    private function restoreDeletedBy(string $name): void
    {
        if ($name === 'api.resources.destroy') {
            $this->resourceA = LearningResource::create($this->resourceA->only(['school_id', 'skill_id', 'title', 'url', 'added_by']));
        }
    }

    #[DataProvider('studentRoutes')]
    public function test_student_route(string $name): void
    {
        [$method, $uri, $peer, $otherSchool] = self::STUDENT[$name];

        $this->expect($this->call_($method, $uri, null), 401, 'guest');
        $this->expect($this->call_($method, $uri, $this->teacherA), 403, 'the teacher');
        $this->expect($this->call_($method, $uri, $this->admin), 403, 'admin');
        $this->expect($this->call_($method, $uri, $this->studentB), $otherSchool, 'student of another school');
        $this->expect($this->call_($method, $uri, $this->studentA), $peer, 'classmate');
        $this->expect($this->call_($method, $uri, $this->studentA2), self::OK, 'owner');
    }

    #[DataProvider('sharedRoutes')]
    public function test_shared_route(string $name): void
    {
        [$method, $uri, $studentA, $studentB, $sameSchool, $otherSchool] = self::SHARED[$name];

        $this->expect($this->call_($method, $uri, null), 401, 'guest');
        $this->expect($this->call_($method, $uri, $this->admin), 403, 'admin');
        $this->expect($this->call_($method, $uri, $this->disabledTeacherA), 403, 'disabled teacher', 'account_not_active');
        $this->expect($this->call_($method, $uri, $this->studentA), $studentA, 'student A (own, unpublished)');
        $this->expect($this->call_($method, $uri, $this->studentB), $studentB, 'student of another school');
        $this->expect($this->call_($method, $uri, $this->teacherB), $otherSchool, 'teacher of another school');
        $this->expect($this->call_($method, $uri, $this->teacherA2), $sameSchool, 'teacher of the same school');
        $this->expect($this->call_($method, $uri, $this->teacherA), self::OK, 'owner');
    }

    #[DataProvider('commonRoutes')]
    public function test_common_route(string $name): void
    {
        [$method, $uri] = self::COMMON[$name];

        $this->expect($this->call_($method, $uri, null), 401, 'guest');
        foreach (['teacher A' => $this->teacherA, 'student A' => $this->studentA, 'admin' => $this->admin] as $label => $user) {
            $this->expect($this->call_($method, $uri, $user), self::OK, $label);
        }
        // A disabled account may still log out (the app drops its token) but reach nothing else.
        $this->expect($this->call_($method, $uri, $this->disabledTeacherA), $name === 'api.auth.logout' ? self::OK : 403, 'disabled teacher', $name === 'api.auth.logout' ? null : 'account_not_active');
    }

    public function test_a_student_reads_only_the_crop_of_a_published_answer_of_their_own(): void
    {
        $this->asUser($this->studentA2)->getJson("/api/v1/responses/{$this->responseA2->id}/crop")->assertOk();
        $this->asUser($this->studentA)->getJson("/api/v1/responses/{$this->responseA2->id}/crop")->assertNotFound();
        $this->asUser($this->studentA)->getJson("/api/v1/responses/{$this->responseA->id}/crop")->assertNotFound();
        $this->asUser($this->studentA2)->getJson("/api/v1/student/results/{$this->submissionA2->id}")->assertOk();
        $this->asUser($this->studentA)->getJson("/api/v1/student/results/{$this->submissionA->id}")->assertNotFound();
    }

    public function test_a_token_with_a_foreign_ability_is_forbidden_everywhere(): void
    {
        // A student token that somehow carries `teacher`, or vice versa, is
        // stopped by EnsureRole (role and ability must both match).
        foreach ([
            'classrooms', "classrooms/{$this->classroomA->id}", "assignments/{$this->assignmentA->id}/review-queue",
            "responses/{$this->responseA->id}", 'practice-items', "scans/{$this->scanA->id}/page",
        ] as $uri) {
            $this->asUser($this->studentA, ['teacher'])->getJson('/api/v1/'.$uri)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        foreach (['student/results', "student/results/{$this->submissionA2->id}", 'student/practice', 'student/mastery'] as $uri) {
            $this->asUser($this->teacherA, ['student'])->getJson('/api/v1/'.$uri)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        }
        // The shared routes need the role behind the ability as well.
        $this->asUser($this->studentA, ['teacher'])->getJson("/api/v1/responses/{$this->responseA->id}/crop")->assertStatus(403);
        $this->asUser($this->admin, ['teacher', 'student'])->getJson('/api/v1/ml/models/active')->assertStatus(403);
    }

    private function call_(string $method, string $uri, $user): TestResponse
    {
        $this->forgetGuards();
        $request = $user === null ? $this->withoutToken() : $this->withToken($this->tokenFor($user));
        $path = '/api/v1/'.strtr($uri, [
            '{classroom}' => $this->classroomA->id,
            '{student}' => $this->studentA->id,
            '{card_print}' => $this->cardPrintA->id,
            '{assignment}' => $this->assignmentA->id,
            '{question}' => $this->shortA->id,
            '{print}' => $this->printA->id,
            '{scan}' => $this->scanA->id,
            '{response}' => $this->responseA->id,
            '{response_a2}' => $this->responseA2->id,
            '{submission}' => $this->submissionA->id,
            '{submission_a2}' => $this->submissionA2->id,
            '{appeal}' => $this->appealA2->id,
            '{item}' => $this->practiceItemA->id,
            '{skill}' => $this->skillA->id,
            '{teacher_skill}' => $this->teacherSkillA->id,
            '{resource}' => $this->resourceA->id,
            '{import}' => $this->importA->id,
            '{conflict}' => $this->conflictA->id,
            '{page}' => $this->pageA->id,
            '{extraction}' => $this->extractionA->id,
            '{model}' => $this->model->id,
            '{course}' => 'course-a',
            '{own_course}' => $this->courseA->id,
            '{unit}' => $this->unitA->id,
            '{lesson_plan}' => $this->lessonPlanA->id,
            '{analysis}' => $this->analysisA->id,
            '{exam}' => $this->examA->id,
            '{exam_section}' => $this->examSectionA->id,
            '{exam_question}' => $this->examQuestionA->id,
            '{exam_option}' => $this->examOptionA->id,
            '{exam_scan}' => $this->examScanA->id,
            '{exam_response}' => $this->examResponseA->id,
            '{exam_page_image}' => $this->examPageImageA->id,
            '{exam_document}' => $this->examDocumentA->id,
            '{gradebook_item}' => $this->gradebookItemA->id,
        ]);

        if ($uri === 'scans') {
            return $request->post($path, $this->scanBody(), ['Accept' => 'application/json']);
        }
        if ($uri === 'exam-sheets') {
            return $request->post($path, $this->examSheetBody(), ['Accept' => 'application/json']);
        }

        return $request->json($method, $path, []);
    }

    private function expect(TestResponse $response, int|string $expected, string $actor, ?string $code = null): void
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $where = "{$actor}: {$response->baseRequest->getMethod()} {$response->baseRequest->getPathInfo()} -> {$status} ".mb_substr($body, 0, 300);

        if ($expected === self::OK) {
            $json = json_decode($body, true);
            $googleError = is_array($json) && str_starts_with((string) ($json['code'] ?? ''), 'google_');
            $this->assertNotContains($status, [401, 403, 404], $where);
            $this->assertTrue($status < 500 || $googleError, $where);

            return;
        }

        $this->assertSame($expected, $status, $where);
        $json = json_decode($body, true);
        $this->assertIsArray($json, $where);
        $this->assertSame(['message', 'errors', 'code'], array_keys($json), $where);
        $this->assertSame($code ?? match ($expected) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
        }, $json['code'], $where);
    }
}
