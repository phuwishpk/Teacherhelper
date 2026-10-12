<?php

namespace Tests\Feature\Google;

use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomFeedbackPost;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\Course;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DESIGN §30.14: exam scores into Google Classroom. The first send makes
 * the holder courseWork; every send grades and returns each scored
 * student's submission and queues the private announcement.
 */
class ExamScoresGoogleTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    /** @var list<User> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://teacherhelper.example']);
        $this->configureGoogle();
        $this->captureLogs();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->students = [];
        foreach ([1, 2, 3] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
        $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิต', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => 'g-1']);
        ClassroomStudent::query()->where('student_id', $this->students[1]->id)->update(['google_user_id' => 'g-2']);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function fake(array $routes = []): void
    {
        $this->fakeGoogle($routes + [
            // The grade patch answers a work not yet returned, so the pusher returns it next.
            'classroom.googleapis.com/v1/courses/*/courseWork/*/studentSubmissions/*' => Http::response(['id' => 'sub', 'state' => 'CREATED', 'updateTime' => '2026-10-13T02:00:00.000Z']),
            // The student's own (never handed-in) submission in the holder courseWork.
            'classroom.googleapis.com/v1/courses/*/courseWork/*/studentSubmissions*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $id = ($query['userId'] ?? '') === 'g-2' ? 'sub-2' : 'sub-1';

                return Http::response(['studentSubmissions' => [['id' => $id, 'state' => 'CREATED', 'updateTime' => '2026-10-13T01:00:00.000Z', 'alternateLink' => 'https://classroom.google.com/s/'.$id]]]);
            },
            'classroom.googleapis.com/v1/courses/*/courseWork' => Http::response(['id' => self::COURSE_WORK_ID, 'alternateLink' => 'https://classroom.google.com/c/1/a/9']),
        ]);
    }

    /**
     * The courseWork.create calls alone (the :return calls share the path).
     *
     * @return list<Request>
     */
    private function holders(): array
    {
        return array_values(array_filter($this->sentTo('/courseWork', 'POST'), fn ($r) => str_ends_with($r->url(), '/courseWork')));
    }

    private function appExam(): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบกลางภาค',
            'kind' => 'exam', 'grading_method' => 'app', 'due_at' => now()->subDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        Question::factory()->create(['assignment_id' => $id, 'max_points' => 20]);
        Question::factory()->create(['assignment_id' => $id, 'max_points' => 10]);

        return Assignment::query()->findOrFail($id);
    }

    private function publishedScore(Assignment $exam, User $student, float $total): Submission
    {
        return Submission::query()->create([
            'assignment_id' => $exam->id, 'student_id' => $student->id, 'status' => Submission::STATUS_PUBLISHED,
            'total_score' => $total, 'published_at' => now(), 'published_by' => $this->teacher->id,
        ]);
    }

    public function test_sending_makes_the_holder_course_work_then_grades_and_announces_each_published_score(): void
    {
        $this->fake();
        $exam = $this->appExam();
        $url = "/api/v1/assignments/{$exam->id}/google-exam-scores";
        $this->asUser($this->teacher)->postJson($url)->assertStatus(409)->assertJsonPath('code', 'no_results');
        $this->asUser($this->teacher)->getJson($url)->assertOk()
            ->assertJsonPath('data.posted', false)->assertJsonPath('data.linked', true)->assertJsonPath('data.scored', 0);

        $this->publishedScore($exam, $this->students[0], 26);
        $this->publishedScore($exam, $this->students[1], 18.5);
        $this->publishedScore($exam, $this->students[2], 9); // not paired with Google
        $this->asUser($this->teacher)->postJson($url)->assertStatus(202)
            ->assertJsonPath('data.queued', 3)->assertJsonPath('data.course_work_id', self::COURSE_WORK_ID);

        $holder = $this->holders();
        $this->assertCount(1, $holder);
        $this->assertSame(['title' => 'สอบกลางภาค', 'description' => 'คะแนนสอบ "สอบกลางภาค" จาก Krucheck', 'workType' => 'ASSIGNMENT', 'state' => 'PUBLISHED', 'maxPoints' => 30.0], $holder[0]->data());
        $link = AssignmentGoogleLink::query()->findOrFail($exam->id);
        $this->assertSame(self::COURSE_WORK_ID, $link->course_work_id);

        $grades = $this->sentTo('/studentSubmissions/', 'PATCH');
        $this->assertCount(2, $grades);
        $this->assertEqualsCanonicalizing([26.0, 18.5], array_map(fn ($r) => $r->data()['assignedGrade'], $grades));
        $this->assertCount(2, $this->sentTo(':return', 'POST'));
        $this->assertSame(2, ClassroomSubmissionImport::query()->where('state', ClassroomSubmissionImport::STATE_GRADED)->count());
        // The private announcement of each published result (§19.7), as for homework.
        $this->assertSame(2, ClassroomFeedbackPost::query()->count());
        $this->assertCount(2, $this->sentTo('/announcements', 'POST'));

        $this->asUser($this->teacher)->getJson($url)->assertOk()
            ->assertJsonPath('data.posted', true)
            ->assertJsonPath('data.link', 'https://classroom.google.com/c/1/a/9')
            ->assertJsonPath('data.scored', 3)->assertJsonPath('data.graded', 2)->assertJsonPath('data.failed', 0)->assertJsonPath('data.unpaired', 1);

        // Sent again: the holder stays, the current scores go again.
        $this->asUser($this->teacher)->postJson($url)->assertStatus(202);
        $this->assertCount(1, $this->holders());
        $this->assertCount(4, $this->sentTo('/studentSubmissions/', 'PATCH'));
        $this->assertSame(2, ClassroomFeedbackPost::query()->count(), 'the same publish is announced once');
    }

    public function test_a_manual_exam_sends_the_typed_scores_and_skips_excused_students(): void
    {
        $this->fake();
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบปลายภาค',
            'kind' => 'exam', 'grading_method' => 'manual', 'manual_full_marks' => 50, 'due_at' => now()->subDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$id}/gradebook-scores", ['scores' => [
            ['student_id' => $this->students[0]->id, 'score' => 41],
            ['student_id' => $this->students[1]->id, 'excused' => true],
        ]])->assertOk();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$id}/google-exam-scores")->assertStatus(202)->assertJsonPath('data.queued', 1);
        $this->assertSame(50.0, $this->holders()[0]->data()['maxPoints']);
        $grades = $this->sentTo('/studentSubmissions/', 'PATCH');
        $this->assertCount(1, $grades);
        $this->assertSame(41.0, $grades[0]->data()['assignedGrade']);
        $this->assertCount(0, $this->sentTo('/announcements', 'POST'), 'no hand-in, no private announcement');
    }

    public function test_only_exams_of_a_linked_open_classroom_send_scores(): void
    {
        $this->fake();
        $homework = Assignment::factory()->for_classroom($this->classroom)->create(['send_to_google' => false]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$homework->id}/google-exam-scores")->assertStatus(422)->assertJsonPath('code', 'homework_kind_unsupported');

        $exam = $this->appExam();
        $this->publishedScore($exam, $this->students[0], 26);
        ClassroomGoogleLink::query()->delete();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/google-exam-scores")->assertStatus(422)->assertJsonPath('code', 'classroom_not_linked');
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/google-exam-scores")->assertOk()->assertJsonPath('data.linked', false);

        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->postJson("/api/v1/assignments/{$exam->id}/google-exam-scores")->assertNotFound();
    }
}
