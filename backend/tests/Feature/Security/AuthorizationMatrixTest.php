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
        'api.classrooms.login-cards' => ['POST', 'classrooms/{classroom}/login-cards', 404, 404],
        'api.students.login-card' => ['POST', 'students/{student}/login-card', 403, 404],
        'api.students.pin' => ['POST', 'students/{student}/pin', 403, 404],
        'api.login-card-prints.show' => ['GET', 'login-card-prints/{card_print}', 403, 404],
        'api.login-card-prints.file' => ['GET', 'login-card-prints/{card_print}/file', 403, 404],
        'api.skills.index' => ['GET', 'skills', self::OK, self::OK],
        'api.subjects.index' => ['GET', 'subjects', self::OK, self::OK],
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
        'api.questions.update' => ['PATCH', 'questions/{question}', 404, 404],
        'api.questions.destroy' => ['DELETE', 'questions/{question}', 404, 404],
        'api.questions.rubric.draft' => ['POST', 'questions/{question}/rubric/draft', 404, 404],
        'api.questions.rubric.update' => ['PUT', 'questions/{question}/rubric', 404, 404],
        'api.worksheet-prints.show' => ['GET', 'worksheet-prints/{print}', 403, 404],
        'api.worksheet-prints.file' => ['GET', 'worksheet-prints/{print}/file', 403, 404],
        'api.scans.store' => ['POST', 'scans', 403, 403],
        'api.scans.confirm-replace' => ['POST', 'scans/{scan}/confirm-replace', 403, 404],
        'api.scans.page' => ['GET', 'scans/{scan}/page', 403, 404],
        'api.assignments.review-queue' => ['GET', 'assignments/{assignment}/review-queue', 404, 404],
        'api.assignments.approve-confident' => ['POST', 'assignments/{assignment}/approve-confident', 404, 404],
        'api.assignments.publish' => ['POST', 'assignments/{assignment}/publish', 404, 404],
        'api.responses.show' => ['GET', 'responses/{response}', 403, 404],
        'api.responses.update' => ['PATCH', 'responses/{response}', 403, 404],
        'api.responses.regenerate-explanation' => ['POST', 'responses/{response}/regenerate-explanation', 403, 404],
        'api.submissions.publish' => ['POST', 'submissions/{submission}/publish', 403, 404],
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
        'api.google-submissions.return' => ['POST', 'google-submissions/{import}/return', 404, 404],
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
        'api.student.retake-requests' => ['GET', 'student/retake-requests', self::OK, self::OK],
        'api.student.practice.index' => ['GET', 'student/practice', self::OK, self::OK],
        'api.student.practice.attempts' => ['POST', 'student/practice/{item}/attempts', self::OK, 404],
    ];

    /**
     * Routes both roles reach: [method, uri, owner student (A, unpublished), student B, same-school teacher, other-school teacher].
     */
    private const SHARED = [
        'api.responses.crop' => ['GET', 'responses/{response}/crop', 404, 404, 403, 404],
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
            '{resource}' => $this->resourceA->id,
            '{import}' => $this->importA->id,
            '{model}' => $this->model->id,
            '{course}' => 'course-a',
        ]);

        if ($uri === 'scans') {
            return $request->post($path, $this->scanBody(), ['Accept' => 'application/json']);
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
