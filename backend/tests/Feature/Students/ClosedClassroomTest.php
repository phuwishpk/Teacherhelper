<?php

namespace Tests\Feature\Students;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\LoginCardPrint;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Google\GoogleFixtures;
use Tests\Feature\Security\SecurityWorld;
use Tests\TestCase;

/**
 * DESIGN §24.6: a closed classroom ("ห้องเก่า") is read-only. Every write
 * route that touches it answers 409 classroom_closed, for its teacher and its
 * students alike, while reads and exports keep working; closing, reopening
 * and deleting (only while empty) are the exceptions.
 *
 * test_every_write_on_a_closed_classroom_is_refused fails when a write route is added
 * without saying whether it writes to a classroom.
 */
class ClosedClassroomTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use SecurityWorld;

    /**
     * Writes that do not write to a classroom (or are allowed on a closed
     * one) => the status the owner gets on closed classroom A, or null when
     * the route is not called here.
     */
    private const NOT_CLASSROOM_WRITES = [
        'api.auth.teacher.register' => null,
        'api.auth.teacher.login' => null,
        'api.auth.student.qr' => null,
        'api.auth.student.login' => null,
        'api.auth.logout' => null,
        'api.auth.admin-handoff' => null,
        'api.devices.store' => null,
        'api.me.ai-key.update' => null,
        'api.me.ai-key.destroy' => null,
        'api.classrooms.store' => null,
        'api.classrooms.import-google' => null,
        // Allowed on a closed classroom (tested below).
        'api.classrooms.close' => null,
        'api.classrooms.reopen' => null,
        'api.classrooms.destroy' => null,
        // Free estimates write nothing.
        'api.assignments.answer-key.estimate' => null,
        'api.assignments.regrade.estimate' => null,
        'api.exams.import.estimate' => null,
        'api.courses.extract.estimate' => null,
        // Course-level settings shared by every classroom of the course.
        'api.courses.extract' => null,
        'api.courses.import' => null,
        'api.courses.update' => null,
        'api.courses.destroy' => null,
        'api.courses.indicators' => null,
        'api.courses.units.store' => null,
        'api.courses.lesson-plans.store' => null,
        'api.courses.gradebook.categories' => null,
        'api.courses.gradebook.cutoffs' => null,
        'api.units.update' => null,
        'api.units.destroy' => null,
        'api.units.indicators' => null,
        'api.lesson-plans.update' => null,
        'api.lesson-plans.destroy' => null,
        'api.lesson-plans.indicators' => null,
        'api.documents.store' => null,
        'api.skills.store' => null,
        'api.skills.update' => null,
        'api.subjects.store' => null,
        'api.subjects.update' => null,
        'api.subjects.destroy' => null,
        // The values of the statuses belong to the course; a closed classroom's scores are left alone.
        'api.courses.attendance.scores' => null,
        'api.student.password' => null,
        'api.skills.practice-items.generate' => null,
        'api.skills.resources.store' => null,
        'api.practice-items.store' => null,
        'api.practice-items.update' => null,
        'api.resources.update' => null,
        'api.resources.destroy' => null,
        'api.google.connect' => null,
        'api.google.disconnect' => null,
        'api.google.oauth-url' => null,
        'api.student.practice.attempts' => null,
        // Merging may involve closed classrooms (§24.5).
        'api.students.merge' => null,
        // A closed classroom's pending requests are cancelled when it closes, so a decision
        // answers 409 request_closed (SharedHomeroomTest); the path names the request, not the classroom.
        'api.course-requests.approve' => null,
        'api.course-requests.decline' => null,
        'api.course-requests.destroy' => null,
        // Student-level: only the homeroom teacher of an OPEN classroom edits a student (§24.2).
        'api.students.update' => 403,
        'api.students.pin' => 403,
        'api.students.login-card' => 403,
        'api.students.google-identity.destroy' => 403,
        // Google sign-in (§24.9): accounts, not classrooms.
        'api.auth.google' => null,
        'api.auth.google.web-url' => null,
        'api.auth.google.ticket' => null,
        'api.auth.google.link-with-password' => null,
        'api.auth.google.link-with-qr' => null,
        'api.me.google-identity.store' => null,
        'api.me.google-identity.ticket' => null,
        'api.me.google-identity.destroy' => null,
    ];

    /** Student routes, called by student A2 (who owns responseA2 and is in classroom A). */
    private const STUDENT_ROUTES = ['api.student.assignments.submission', 'api.student.responses.appeal'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSecurityWorld();
        $this->configureGoogle();
        config(['services.google_signin.client_ids' => 'test-signin-client.apps.googleusercontent.com']);
        $this->fakeGoogle([
            'classroom.googleapis.com/*' => Http::response(['courses' => [], 'students' => [], 'studentSubmissions' => []]),
            'www.googleapis.com/*' => Http::response([]),
        ]);
        $this->classroomA->forceFill(['closed_at' => now(), 'closed_by' => $this->teacherA->id])->save();
    }

    public function test_every_write_route_is_classified(): void
    {
        $this->assertGreaterThan(50, count(self::apiWrites()));
        foreach (array_keys(self::NOT_CLASSROOM_WRITES) as $name) {
            $this->assertTrue(Route::has($name), "{$name} is not a route any more");
        }
    }

    /**
     * Every write route, called by the owner on rows of closed classroom A
     * (one test: a data provider cannot read the router).
     */
    public function test_every_write_on_a_closed_classroom_is_refused(): void
    {
        $failures = [];
        $checked = 0;
        foreach (self::apiWrites() as $name => [$method, $uri]) {
            if (array_key_exists($name, self::NOT_CLASSROOM_WRITES) && self::NOT_CLASSROOM_WRITES[$name] === null) {
                continue;
            }
            $student = in_array($name, self::STUDENT_ROUTES, true);
            $response = $this->send($method, $uri, $student ? $this->studentA2 : $this->teacherA);
            $expected = self::NOT_CLASSROOM_WRITES[$name] ?? 409;
            $ok = $response->getStatusCode() === $expected
                && ($expected !== 409 || ($response->json('code') === 'classroom_closed' && array_keys($response->json()) === ['message', 'errors', 'code']));
            if (! $ok) {
                $failures[] = "{$name}: {$method} {$uri} -> {$response->getStatusCode()} ".mb_substr((string) $response->getContent(), 0, 200);
            }
            $checked++;
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThan(60, $checked);
    }

    public function test_someone_who_cannot_see_the_classroom_still_gets_404(): void
    {
        foreach ([$this->teacherA2, $this->teacherB] as $teacher) {
            $this->send('PATCH', 'api/v1/assignments/{id}', $teacher)->assertNotFound();
            $this->send('POST', 'api/v1/classrooms/{id}/students', $teacher)->assertNotFound();
        }
        $this->send('POST', 'api/v1/student/assignments/{id}/submission', $this->studentB)->assertNotFound();
    }

    public function test_reads_and_exports_keep_working(): void
    {
        $this->asUser($this->teacherA);
        foreach ([
            "classrooms/{$this->classroomA->id}",
            "classrooms/{$this->classroomA->id}/roster",
            "assignments/{$this->assignmentA->id}",
            "assignments/{$this->assignmentA->id}/review-queue",
            "classrooms/{$this->classroomA->id}/mastery",
            "courses/{$this->courseA->id}/gradebook?classroom_id={$this->classroomA->id}",
            "responses/{$this->responseA->id}",
        ] as $uri) {
            $this->getJson('/api/v1/'.$uri)->assertOk();
        }
        $this->get("/api/v1/courses/{$this->courseA->id}/gradebook/export?classroom_id={$this->classroomA->id}")->assertOk();

        $this->asUser($this->studentA2)->getJson('/api/v1/student/results')->assertOk();
        $this->asUser($this->studentA2)->getJson("/api/v1/student/results/{$this->submissionA2->id}")->assertOk();
    }

    public function test_a_student_still_logs_in_with_the_class_code_of_a_closed_classroom(): void
    {
        $this->asUser($this->teacherA)->postJson("/api/v1/classrooms/{$this->classroomA->id}/reopen")->assertOk();
        $enrolled = $this->enrollStudent($this->classroomA, 30, 'นักเรียนห้องเก่า');
        $this->classroomA->refresh()->forceFill(['closed_at' => now()])->save();

        $this->asGuest()->postJson('/api/v1/auth/student/login', $this->cred([
            'class_code' => $this->classroomA->class_code, 'student_number' => 30, 'pin' => $enrolled['pin'],
        ]))->assertOk();
    }

    public function test_nothing_of_a_closed_classroom_waits_on_the_home_screen(): void
    {
        DB::table('classroom_students')->where('student_id', $this->studentA->id)->update(['pin_pending_at' => now()]);

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/attention')
            ->assertOk()
            ->assertJsonPath('data.pins_pending', 0)
            ->assertJsonPath('data.grade_conflicts', 0);
    }

    public function test_the_list_shows_open_classrooms_and_old_ones_on_request(): void
    {
        $open = $this->makeClassroom($this->teacherA, ['name' => 'ป.5/1']);

        $this->asUser($this->teacherA)->getJson('/api/v1/classrooms')
            ->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.closed_at', null)
            ->assertJsonPath('data.0.my_role', 'homeroom')
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/classrooms?state=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->classroomA->id);
        $this->assertNotNull($this->json('GET', '/api/v1/classrooms?state=closed')->json('data.0.closed_at'));
        $this->getJson('/api/v1/classrooms?state=old')->assertStatus(422);
    }

    public function test_close_and_reopen(): void
    {
        $classroom = $this->makeClassroom($this->teacherA);

        $this->asUser($this->teacherA)->postJson("/api/v1/classrooms/{$classroom->id}/close")
            ->assertOk()->assertJsonPath('data.id', $classroom->id);
        $this->assertNotNull($classroom->refresh()->closed_at);
        $this->assertSame($this->teacherA->id, $classroom->closed_by);
        $this->patchJson("/api/v1/classrooms/{$classroom->id}", ['name' => 'ใหม่'])->assertStatus(409)->assertJsonPath('code', 'classroom_closed');

        $this->postJson("/api/v1/classrooms/{$classroom->id}/reopen")->assertOk()->assertJsonPath('data.closed_at', null);
        $this->assertNull($classroom->refresh()->closed_at);
        $this->patchJson("/api/v1/classrooms/{$classroom->id}", ['name' => 'ใหม่'])->assertOk();

        // Only the homeroom teacher.
        $this->asUser($this->teacherA2)->postJson("/api/v1/classrooms/{$classroom->id}/close")->assertNotFound();
    }

    public function test_a_classroom_with_data_cannot_be_deleted(): void
    {
        $this->asUser($this->teacherA)->deleteJson("/api/v1/classrooms/{$this->classroomA->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'classroom_has_data')
            ->assertJsonPath('counts.gradebook_publications', 1);
        $this->assertNotNull(Classroom::find($this->classroomA->id));
    }

    public function test_a_classroom_with_only_a_special_grade_cannot_be_deleted(): void
    {
        $classroom = $this->makeClassroom($this->teacherA);
        $student = $this->enrollStudent($classroom, 1, 'นักเรียน ร')['student'];
        $course = $this->makeCourse($this->teacherA, [$classroom]);
        DB::table('gradebook_special_grades')->insert(['course_id' => $course->id, 'classroom_id' => $classroom->id, 'student_id' => $student->id, 'special' => 'r', 'set_by' => $this->teacherA->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->asUser($this->teacherA)->deleteJson("/api/v1/classrooms/{$classroom->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'classroom_has_data')
            ->assertJsonPath('counts.gradebook_special_grades', 1);
        $this->assertDatabaseHas('gradebook_special_grades', ['classroom_id' => $classroom->id, 'student_id' => $student->id]);
    }

    public function test_an_empty_classroom_is_deleted_with_its_drafts_and_files_and_the_students_stay(): void
    {
        $classroom = $this->makeClassroom($this->teacherA);
        $student = $this->enrollStudent($classroom, 1, 'นักเรียนห้องที่จะลบ')['student'];
        $this->makeCourse($this->teacherA, [$classroom]);
        $draft = Assignment::factory()->for_classroom($classroom)->create(['subject_id' => $this->subject->id]);
        $print = WorksheetPrint::create(['assignment_id' => $draft->id, 'layout_version' => 1, 'requested_by' => $this->teacherA->id, 'status' => WorksheetPrint::STATUS_READY, 'file_path' => 'worksheets/x/print.pdf']);
        Storage::disk('local')->put($print->file_path, '%PDF');
        $card = LoginCardPrint::create(['school_id' => $classroom->school_id, 'classroom_id' => $classroom->id, 'requested_by' => $this->teacherA->id, 'status' => LoginCardPrint::STATUS_READY, 'file_path' => 'login-cards/x.pdf']);
        Storage::disk('local')->put($card->file_path, '%PDF');
        $this->asUser($this->teacherA)->deleteJson("/api/v1/classrooms/{$classroom->id}")->assertNoContent();

        $this->assertNull(Classroom::find($classroom->id));
        $this->assertNull(Assignment::find($draft->id));
        $this->assertDatabaseMissing('classroom_students', ['classroom_id' => $classroom->id]);
        $this->assertDatabaseMissing('course_classroom', ['classroom_id' => $classroom->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'active']);
        Storage::disk('local')->assertMissing($print->file_path);
        Storage::disk('local')->assertMissing($card->file_path);

        $other = $this->makeClassroom($this->teacherA);
        $s = $this->enrollStudent($other, 1)['student'];
        GradebookEntry::create(['classroom_id' => $other->id, 'student_id' => $s->id, 'gradebook_item_id' => null, 'assignment_id' => Assignment::factory()->for_classroom($other)->create(['subject_id' => $this->subject->id])->id, 'score' => null, 'updated_by' => $this->teacherA->id]);
        $this->deleteJson("/api/v1/classrooms/{$other->id}")->assertStatus(409)->assertJsonPath('counts.gradebook_entries', 1);
    }

    /** @return array<string, array{0: string, 1: string}> route name => [method, uri] */
    private static function apiWrites(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $method = $route->methods()[0];
            if (str_starts_with($route->uri(), 'api/v1') && ! in_array($method, ['GET', 'HEAD'], true)) {
                $routes[(string) $route->getName()] = [$method, $route->uri()];
            }
        }
        ksort($routes);

        return $routes;
    }

    private function send(string $method, string $uri, $user): TestResponse
    {
        $this->forgetGuards();
        $request = $this->withToken($this->tokenFor($user));
        $segments = explode('/', substr($uri, strlen('api/v1/')));
        $resource = $segments[0] === 'student' ? $segments[1] : $segments[0];
        $id = match ($resource) {
            'classrooms' => $this->classroomA->id,
            'assignments' => $this->assignmentA->id,
            'exams' => $this->examA->id,
            'questions' => $this->examQuestionA->id,
            'question-options' => $this->examOptionA->id,
            'exam-sections' => $this->examSectionA->id,
            'scans' => $this->scanA->id,
            'exam-sheets' => $this->examScanA->id,
            'responses' => $segments[0] === 'student' ? $this->responseA2->id : $this->responseA->id,
            'exam-responses' => $this->examResponseA->id,
            'submissions' => $this->submissionA->id,
            'appeals' => $this->appealA2->id,
            'gradebook-items' => $this->gradebookItemA->id,
            'attendance-sessions' => $this->attendanceSessionA->id,
            'analyses' => $this->analysisA->id,
            'google-submissions' => $this->importA->id,
            'grade-conflicts' => $this->conflictA->id,
            'students' => $this->studentA->id,
            'courses' => $this->courseA->id,
            default => 0,
        };
        $path = '/'.strtr($uri, ['{id}' => $id, '{student_id}' => $this->studentA->id, '{course_id}' => $this->courseA->id]);

        if ($uri === 'api/v1/scans') {
            return $request->post($path, $this->scanBody(), ['Accept' => 'application/json']);
        }
        if ($uri === 'api/v1/exam-sheets') {
            return $request->post($path, $this->examSheetBody(), ['Accept' => 'application/json']);
        }
        $classroomId = $this->classroomA->id;
        $body = match ($uri) {
            'api/v1/assignments' => ['classroom_id' => $classroomId, 'course_id' => $this->courseA->id, 'title' => 'งานใหม่'],
            'api/v1/courses' => ['code' => 'ค14102', 'name' => 'คณิต', 'subject_id' => $this->subject->id, 'grade_level' => 4, 'academic_year' => 2569, 'classroom_ids' => [$classroomId]],
            'api/v1/courses/{id}/classrooms' => ['classroom_ids' => []],
            'api/v1/courses/{id}/gradebook-items' => ['classroom_ids' => [$classroomId], 'category_id' => $this->gradebookItemA->category_id, 'name' => 'งานเก็บ', 'max_points' => 10],
            'api/v1/courses/{id}/attendance-sessions' => ['classroom_id' => $classroomId, 'held_on' => '2026-10-02'],
            'api/v1/courses/{id}/gradebook/special-grades', 'api/v1/courses/{id}/gradebook/publish' => ['classroom_id' => $classroomId, 'student_id' => $this->studentA->id, 'special' => 'r'],
            'api/v1/students/{id}/analysis/run' => ['classroom_id' => $classroomId],
            'api/v1/classrooms/{id}/course-requests' => ['course_id' => $this->courseA->id],
            'api/v1/google/courses/{course_id}/link-existing' => ['classroom_id' => $classroomId],
            default => [],
        };

        return $request->json($method, $path, $body);
    }
}
