<?php

namespace Tests\Feature\Exams;

use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Submission;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Google\GoogleFixtures;
use Tests\TestCase;

/**
 * DESIGN §22.3 "route เดิมที่รับการบ้าน": homework-only routes answer 422
 * exam_kind_unsupported for an exam without changing anything or calling
 * Google or Gemini, and exams never appear as work a student hands in.
 */
class ExamKindGuardTest extends TestCase
{
    use ExamTestHelpers;
    use GoogleFixtures;
    use RefreshDatabase;

    private Assignment $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->configureGoogle();
        $this->makeExamWorld();
        $this->exam = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 20]);
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
    }

    /** @return array<string, array{string, string}> */
    public static function homeworkRoutes(): array
    {
        return [
            'layout' => ['POST', 'assignments/{id}/layout'],
            'worksheets' => ['POST', 'assignments/{id}/worksheets'],
            'homework question' => ['POST', 'assignments/{id}/questions'],
            'homework key' => ['GET', 'assignments/{id}/answer-key'],
            'key extract' => ['POST', 'assignments/{id}/answer-key/extract'],
            'key draft' => ['POST', 'assignments/{id}/answer-key/draft'],
            'key estimate' => ['POST', 'assignments/{id}/answer-key/estimate'],
            'teacher upload' => ['POST', 'assignments/{id}/students/{student}/pages'],
            'google post' => ['POST', 'assignments/{id}/google-post'],
            'google submissions' => ['GET', 'assignments/{id}/google-submissions'],
            'google grades retry' => ['POST', 'assignments/{id}/google-grades/retry'],
            'google feedback' => ['GET', 'assignments/{id}/google-feedback'],
            'google feedback retry' => ['POST', 'assignments/{id}/google-feedback/retry'],
            'grade conflicts' => ['GET', 'assignments/{id}/grade-conflicts'],
            'requeue missing key' => ['POST', 'assignments/{id}/requeue-missing-key'],
        ];
    }

    #[DataProvider('homeworkRoutes')]
    public function test_homework_routes_refuse_an_exam(string $method, string $uri): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $questions = Question::query()->where('assignment_id', $this->exam->id)->count();
        $body = [
            'type' => 'short', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['accepted' => ['1']],
            'attach_blank_worksheet' => false, 'files' => [UploadedFile::fake()->image('page.jpg')],
        ];

        $this->asUser($this->teacher)
            ->json($method, '/api/v1/'.strtr($uri, ['{id}' => $this->exam->id, '{student}' => $student->id]), $method === 'GET' ? [] : $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_kind_unsupported');

        $this->assertSame($questions, Question::query()->where('assignment_id', $this->exam->id)->count());
        $this->assertSame(0, Submission::query()->where('assignment_id', $this->exam->id)->count());
        $this->assertSame(0, WorksheetPrint::query()->count());
        $this->assertSame(0, AiCall::query()->count());
        $this->assertNull($this->exam->refresh()->current_layout_version);
    }

    public function test_an_exam_without_plan_or_course_is_told_to_pick_a_course(): void
    {
        $this->exam->forceFill(['course_id' => null, 'lesson_plan_id' => null])->save();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/indicator-suggestions")
            ->assertStatus(422)
            ->assertJsonPath('code', 'lesson_plan_required')
            ->assertJsonPath('message', 'ผูกข้อสอบนี้กับรายวิชาหรือแผนการสอนที่หน้าตั้งค่าข้อสอบก่อน แล้วจึงให้ AI เสนอตัวชี้วัด')
            ->assertJsonValidationErrors(['course_id']);
        $this->assertSame(0, AiCall::query()->count());
    }

    public function test_grading_whole_pages_of_an_exam_submission_is_refused(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $submission = Submission::create(['assignment_id' => $this->exam->id, 'student_id' => $student->id, 'status' => Submission::STATUS_NEEDS_REVIEW]);

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")
            ->assertStatus(422)->assertJsonPath('code', 'exam_kind_unsupported');
    }

    public function test_students_never_hand_in_an_exam(): void
    {
        $student = $this->enrollStudent($this->classroom)['student'];
        $homework = Assignment::factory()->for_classroom($this->classroom)->freeform()->create(['status' => Assignment::STATUS_READY]);
        $this->assertSame('ready', $this->exam->refresh()->status);

        $this->asUser($student)->getJson('/api/v1/student/assignments')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $homework->id);
        $this->asUser($student)->post("/api/v1/student/assignments/{$this->exam->id}/submission", [
            'files' => [UploadedFile::fake()->image('page.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'exam_kind_unsupported');
        $this->assertSame(0, Submission::query()->where('assignment_id', $this->exam->id)->count());
    }

    public function test_homework_questions_are_untouched_by_the_exam_types(): void
    {
        $homework = Assignment::factory()->for_classroom($this->classroom)->create();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$homework->id}/questions", [
            'type' => 'true_false', 'prompt_text' => 'x', 'max_points' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $id = $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$homework->id}/questions", [
            'type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['correct' => 'B'],
        ])->assertCreated()->json('data.id');
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['answer_key' => ['accepted_options' => [1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['answer_key.correct']);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$homework->id}/answer-key")->assertOk();
    }
}
