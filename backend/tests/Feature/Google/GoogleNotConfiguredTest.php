<?php

namespace Tests\Feature\Google;

use App\Models\Assignment;
use App\Models\GoogleAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A server without GOOGLE_OAUTH_CLIENT_ID / _SECRET (DESIGN §18.6): every
 * Google Classroom route but GET /google/status answers 503
 * google_not_configured before its own preconditions (classroom_not_linked,
 * not_posted, ...), after auth (401) and role (403). No request reaches Google.
 */
class GoogleNotConfiguredTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const STATUS_ROUTE = 'api.google.status';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => '', 'services.google.client_secret' => '']);
    }

    /**
     * Every named /api/v1 route of §18.6 (teacher side), with its path.
     *
     * @return array<string, array{string, string}> name => [method, uri]
     */
    private function googleRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($route->uri(), 'api/v1/') && str_contains($name, 'google') && $name !== self::STATUS_ROUTE) {
                $routes[$name] = [$route->methods()[0], $route->uri()];
            }
        }
        ksort($routes);

        return $routes;
    }

    public function test_every_google_route_but_status_answers_503_first(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        $assignment = Assignment::factory()->for_classroom($classroom)->create();
        // A row left from a time the server was configured changes nothing.
        GoogleAccount::create(['user_id' => $teacher->id, 'google_sub' => 'sub-1', 'email' => 'kru@school.example', 'encrypted_refresh_token' => self::REFRESH_TOKEN, 'scopes' => '']);

        $routes = $this->googleRoutes();
        $this->assertSame([
            'api.assignments.google-grades.retry',
            'api.assignments.google-post',
            'api.assignments.google-submissions',
            'api.classrooms.google-link.destroy',
            'api.classrooms.google-link.store',
            'api.classrooms.google-roster.show',
            'api.classrooms.google-roster.sync',
            'api.classrooms.google-roster.update',
            'api.classrooms.google-sync',
            'api.classrooms.import-google',
            'api.google-submissions.accept-late',
            'api.google-submissions.return',
            'api.google.connect',
            'api.google.courses',
            'api.google.courses.import-preview',
            'api.google.disconnect',
            'api.google.oauth-url',
        ], array_keys($routes));

        $student = $this->enrollStudent($classroom)['student'];
        foreach ($routes as $name => [$method, $uri]) {
            $path = '/'.str_replace(['{id}', '{course_id}'], [(string) match (true) {
                str_contains($uri, 'classrooms/') => $classroom->id,
                str_contains($uri, 'assignments/') => $assignment->id,
                default => 999999,
            }, self::COURSE_ID], $uri);
            $body = match ($name) {
                'api.google.connect' => ['server_auth_code' => '4/0AQlEd8x-one-time-code'],
                'api.classrooms.google-link.store' => ['course_id' => self::COURSE_ID],
                'api.classrooms.google-roster.update' => ['matches' => []],
                'api.classrooms.import-google' => ['course_id' => self::COURSE_ID, 'name' => 'ม.1/1', 'grade_level' => 7, 'academic_year' => 2569, 'students' => []],
                'api.google-submissions.return' => ['reason' => 'ถ่ายใหม่'],
                default => [],
            };

            $this->asUser($teacher)->json($method, $path, $body)
                ->assertStatus(503)
                ->assertExactJson([
                    'message' => 'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom (GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET) กรุณาแจ้งผู้ดูแลระบบ',
                    'errors' => [],
                    'code' => 'google_not_configured',
                ]);
            $this->asGuest()->json($method, $path, $body)->assertStatus(401);
            $this->asUser($student)->json($method, $path, $body)->assertStatus(403);
        }

        // Nothing was changed or deleted on the way.
        $this->assertDatabaseHas('google_accounts', ['user_id' => $teacher->id]);
    }

    public function test_status_still_answers_and_says_the_server_is_not_configured(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->getJson('/api/v1/google/status')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.server_configured', false)
            ->assertJsonPath('data.connected', false);
    }

    public function test_configured_again_the_preconditions_come_back(): void
    {
        $this->configureGoogle();
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);

        $this->asUser($teacher)->getJson("/api/v1/classrooms/{$classroom->id}/google-roster")
            ->assertStatus(422)
            ->assertJsonPath('code', 'classroom_not_linked');
    }
}
