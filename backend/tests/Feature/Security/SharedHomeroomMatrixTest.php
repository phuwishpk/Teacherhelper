<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Google\GoogleFixtures;
use Tests\TestCase;

/**
 * DESIGN §24.8, the other direction of AuthorizationMatrixTest: every route
 * on one assignment or exam (and its responses, scans and submissions) called on
 * the work of subject teacher S in teacher A's classroom A.
 *
 *   homeroom   teacher A (classroom A's homeroom teacher) -> HOMEROOM[route]: reads
 *              the results (ok), anything that changes the work is 403 not_course_teacher
 *   manager    teacher S                                  -> anything but 401/403/404
 *   colleague  teacher A2 (same school, no role)          -> 404 (looked up among visible work)
 *              or 403 (looked up in the school, then the policy)
 */
class SharedHomeroomMatrixTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use SharedHomeroomWorld;

    private const OK = 'ok';

    private const NCT = '403 not_course_teacher';

    /** route name => [what teacher A gets, what a colleague gets] */
    private const ROUTES = [
        'api.assignments.show' => [self::OK, 404],
        'api.assignments.update' => [self::NCT, 404],
        'api.assignments.destroy' => [self::NCT, 404],
        'api.assignments.questions.store' => [self::NCT, 404],
        'api.assignments.layout.store' => [self::NCT, 404],
        'api.assignments.layouts.index' => [self::OK, 404],
        'api.assignments.worksheets.store' => [self::NCT, 404],
        'api.assignments.requeue-missing-key' => [self::NCT, 404],
        'api.assignments.regrade' => [self::NCT, 404],
        'api.assignments.regrade.estimate' => [self::NCT, 404],
        'api.assignments.indicator-suggestions.store' => [self::NCT, 404],
        'api.assignments.indicator-suggestions.index' => [self::OK, 404],
        'api.assignments.indicator-mapping' => [self::NCT, 404],
        'api.assignments.answer-key.show' => [self::OK, 404],
        'api.assignments.answer-key.extract' => [self::NCT, 404],
        'api.assignments.answer-key.draft' => [self::NCT, 404],
        'api.assignments.answer-key.estimate' => [self::NCT, 404],
        'api.assignments.answer-key.approve' => [self::NCT, 404],
        'api.assignments.students.pages' => [self::NCT, 404],
        'api.assignments.review-queue' => [self::OK, 404],
        'api.assignments.approve-confident' => [self::NCT, 404],
        'api.assignments.publish' => [self::NCT, 404],
        'api.assignments.analytics' => [self::OK, 404],
        'api.assignments.score-distribution' => [self::OK, 404],
        'api.assignments.gradebook-scores' => [self::NCT, 404],
        'api.assignments.gradebook-scores.fill-full' => [self::NCT, 404],
        'api.assignments.google-post' => [self::NCT, 404],
        'api.assignments.google-submissions' => [self::NCT, 404],
        'api.assignments.google-grades.retry' => [self::NCT, 404],
        'api.assignments.google-feedback' => [self::NCT, 404],
        'api.assignments.google-feedback.retry' => [self::NCT, 404],
        'api.assignments.grade-conflicts' => [self::NCT, 404],
        'api.exams.show' => [self::OK, 404],
        'api.exams.sections.store' => [self::NCT, 404],
        'api.exams.questions.approve' => [self::NCT, 404],
        'api.exams.answer-key' => [self::NCT, 404],
        'api.exams.versions' => [self::OK, 404],
        'api.exams.versions.reshuffle' => [self::NCT, 404],
        'api.exams.unlock-structure' => [self::NCT, 404],
        'api.exams.prints.store' => [self::NCT, 404],
        'api.exams.scan-kit' => [self::NCT, 404],
        'api.exams.sheet-status' => [self::NCT, 404],
        'api.exams.key-sheet-read' => [self::NCT, 404],
        'api.exams.option-analysis' => [self::OK, 404],
        'api.exams.import' => [self::NCT, 404],
        'api.exams.import.estimate' => [self::NCT, 404],
        'api.exams.page-images.store' => [self::NCT, 404],
        'api.exams.documents.file' => [self::NCT, 404],
        'api.exams.copy-questions' => [self::NCT, 404],
        'api.responses.show' => [self::OK, 403],
        'api.responses.update' => [self::NCT, 403],
        'api.responses.regenerate-explanation' => [self::NCT, 403],
        'api.responses.crop' => [self::OK, 403],
        'api.scans.page' => [self::OK, 403],
        'api.scans.confirm-replace' => [self::NCT, 403],
        'api.submissions.publish' => [self::NCT, 403],
        'api.submissions.grade' => [self::NCT, 403],
    ];

    /** What the manager gets where a route needs more than an empty body to reach its end. */
    private const MANAGER = [
        // No such document was read for this exam: 404 after the policy let S through.
        'api.exams.documents.file' => 404,
    ];

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(array_keys(self::ROUTES), array_keys(self::ROUTES)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSecurityWorld();
        $this->makeSharedHomeroomWorld();
        $this->configureGoogle();
        $this->fakeGoogle([
            'classroom.googleapis.com/*' => Http::response(['courses' => [], 'students' => [], 'studentSubmissions' => []]),
            'www.googleapis.com/*' => Http::response([]),
        ]);
    }

    public function test_every_route_on_one_assignment_is_listed(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match('#^api/v1/(assignments|exams|responses|scans|submissions)/\{id\}#', $uri) === 1) {
                $this->assertArrayHasKey((string) $route->getName(), self::ROUTES, "classify {$uri} for the homeroom teacher");
            }
        }
    }

    #[DataProvider('routes')]
    public function test_route_on_the_subject_teachers_work(string $name): void
    {
        [$homeroom, $colleague] = self::ROUTES[$name];

        $this->expect($this->send($name, $this->teacherA2), $colleague, 'colleague');
        $this->expect($this->send($name, $this->teacherP), $colleague, 'teacher with a pending request');
        $this->expect($this->send($name, $this->teacherA), $homeroom, 'homeroom teacher');
        $this->expect($this->send($name, $this->teacherS), self::MANAGER[$name] ?? self::OK, 'subject teacher (manager)');
    }

    private function send(string $name, $user): TestResponse
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);
        $method = $route->methods()[0];
        $uri = $route->uri();
        $id = match (true) {
            str_starts_with($uri, 'api/v1/assignments/') => $this->assignmentS->id,
            str_starts_with($uri, 'api/v1/exams/') => $this->examS->id,
            str_starts_with($uri, 'api/v1/responses/') => $this->responseS->id,
            str_starts_with($uri, 'api/v1/scans/') => $this->scanS->id,
            str_starts_with($uri, 'api/v1/submissions/') => $this->submissionS->id,
        };
        $path = '/'.strtr($uri, ['{id}' => $id, '{student_id}' => $this->studentA->id, '{document_id}' => $this->examDocumentA->id]);
        $this->forgetGuards();

        return $this->withToken($this->tokenFor($user))->json($method, $path, []);
    }

    private function expect(TestResponse $response, int|string $expected, string $actor): void
    {
        $code = null;
        if (is_string($expected) && str_contains($expected, ' ')) {
            [$status, $code] = explode(' ', $expected, 2);
            $expected = (int) $status;
        }
        $status = $response->getStatusCode();
        $where = "{$actor}: {$response->baseRequest->getMethod()} {$response->baseRequest->getPathInfo()} -> {$status} ".mb_substr((string) $response->getContent(), 0, 300);

        if ($expected === self::OK) {
            $json = json_decode((string) $response->getContent(), true);
            $googleError = is_array($json) && str_starts_with((string) ($json['code'] ?? ''), 'google_');
            $this->assertNotContains($status, [401, 403, 404], $where);
            $this->assertTrue($status < 500 || $googleError, $where);

            return;
        }

        $this->assertSame($expected, $status, $where);
        $this->assertSame($code ?? ($expected === 404 ? 'not_found' : 'forbidden'), $response->json('code'), $where);
        $this->assertSame(['message', 'errors', 'code'], array_keys($response->json()), $where);
    }
}
