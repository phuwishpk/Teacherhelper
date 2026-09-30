<?php

namespace Tests\Feature\Exams;

use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\GradebookEntry;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.1–§22.4, §22.15: exams as an assignment kind with a grading
 * method, sections, blank and typed questions, options, the typed master
 * key, question approval, the key approval gate and the structure lock.
 */
class ExamApiTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
    }

    public function test_an_app_exam_starts_as_a_draft_with_its_settings(): void
    {
        $response = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id,
            'course_id' => $this->course->id,
            'title' => 'สอบกลางภาค',
            'kind' => 'exam',
            'due_at' => '2026-10-15T02:00:00Z',
            'duration_minutes' => 90,
            'version_count' => 2,
            'show_key_to_students' => true,
        ])->assertCreated();

        $response->assertJsonPath('data.kind', 'exam')
            ->assertJsonPath('data.grading_method', 'app')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version_count', 2)
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.show_key_to_students', true)
            ->assertJsonPath('data.mode', 'worksheet')
            ->assertJsonPath('data.structure_locked_at', null);

        // Homework keeps its defaults.
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'การบ้าน',
        ])->assertCreated()->assertJsonPath('data.kind', 'homework')->assertJsonPath('data.grading_method', null);

        $this->asUser($this->teacher)->getJson('/api/v1/assignments?kind=exam')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'exam');
    }

    public function test_a_manual_exam_is_ready_at_once_and_needs_full_marks(): void
    {
        $base = ['classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบย่อย', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z', 'grading_method' => 'manual'];

        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base)
            ->assertStatus(422)->assertJsonValidationErrors(['manual_full_marks']);

        $exam = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['manual_full_marks' => 20])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.manual_full_marks', 20)
            ->json('data.id');

        // No key gate: approving is always 422 exam_manual_grading.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam}/answer-key/approve")
            ->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
        // Reopening a manual exam keeps it ready.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam}", ['status' => 'draft'])
            ->assertOk()->assertJsonPath('data.status', 'ready');
    }

    public function test_a_manual_exam_can_be_deleted_until_it_is_printed_or_scored(): void
    {
        $manual = fn () => $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 20]);
        $delete = fn (Assignment $exam) => $this->asUser($this->teacher)->deleteJson("/api/v1/assignments/{$exam->id}");

        // Ready from creation, and still deletable.
        $exam = $manual();
        $this->assertSame('ready', $exam->status);
        $delete($exam)->assertNoContent();
        $this->assertNull(Assignment::query()->find($exam->id));

        // A score typed into the gradebook keeps it.
        $scored = $manual();
        $student = $this->enrollStudent($this->classroom, 1, 'นักเรียนหนึ่ง')['student'];
        GradebookEntry::create(['classroom_id' => $this->classroom->id, 'student_id' => $student->id, 'assignment_id' => $scored->id, 'score' => 15, 'excused' => false, 'updated_by' => $this->teacher->id]);
        $delete($scored)->assertStatus(409)->assertJsonPath('code', 'exam_scores_entered');

        // A printed exam keeps it, with exam wording.
        $printed = $manual();
        WorksheetPrint::create(['assignment_id' => $printed->id, 'layout_version' => 1, 'requested_by' => $this->teacher->id, 'status' => 'ready']);
        $delete($printed)->assertStatus(409)->assertJsonPath('code', 'assignment_printed')->assertJsonPath('message', 'ข้อสอบนี้พิมพ์ไปแล้ว ลบไม่ได้');

        // A ready app exam is still not deletable.
        $app = $this->createExam();
        $app->forceFill(['status' => 'ready'])->save();
        $delete($app)->assertStatus(409)->assertJsonPath('code', 'assignment_not_draft')->assertJsonPath('message', 'ลบได้เฉพาะข้อสอบที่ยังเป็นฉบับร่าง');
    }

    public function test_exam_fields_are_validated(): void
    {
        $base = ['classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'x'];

        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['kind' => 'exam'])
            ->assertStatus(422)->assertJsonValidationErrors(['due_at']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['grading_method' => 'app', 'version_count' => 2])
            ->assertStatus(422)->assertJsonValidationErrors(['grading_method', 'version_count']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['kind' => 'exam', 'due_at' => '2026-10-15', 'version_count' => 5])
            ->assertStatus(422)->assertJsonValidationErrors(['version_count']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['kind' => 'exam', 'due_at' => '2026-10-15', 'mode' => 'freeform'])
            ->assertStatus(422)->assertJsonValidationErrors(['mode']);

        $exam = $this->createExam();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['kind' => 'homework'])
            ->assertStatus(422)->assertJsonValidationErrors(['kind']);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['due_at' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['due_at']);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'manual'])
            ->assertStatus(422)->assertJsonValidationErrors(['manual_full_marks']);

        $homework = Assignment::factory()->for_classroom($this->classroom)->create();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$homework->id}", ['version_count' => 2])
            ->assertStatus(422)->assertJsonValidationErrors(['version_count']);
        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$homework->id}")->assertNotFound();
    }

    public function test_sections_create_blank_questions_numbered_across_the_exam(): void
    {
        $exam = $this->createExam();

        $mcq = $this->addSection($exam, ['title' => 'ตอนที่ 1 ปรนัย', 'type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $this->assertSame(3, $mcq['question_count']);
        $this->assertSame([1, 3], [$mcq['first_number'], $mcq['last_number']]);
        $first = $mcq['questions'][0];
        $this->assertTrue($first['blank']);
        $this->assertNull($first['approved_at']);
        $this->assertSame('teacher', $first['origin']);
        $this->assertSame(['ก', 'ข', 'ค', 'ง'], array_column($first['options'], 'label'));

        $tf = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 2, 'default_points' => 0.5]);
        $this->assertSame([4, 5], array_column($tf['questions'], 'position'));
        $this->assertSame([], $tf['questions'][0]['options']);
        $this->assertSame(0.5, $tf['questions'][0]['max_points']);

        // A section put first renumbers everything after it.
        $num = $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 3, 'allow_decimal' => true], 'question_count' => 1, 'position' => 1]);
        $this->assertSame(1, $num['position']);
        $this->assertSame([1], array_column($num['questions'], 'position'));

        $json = $this->examJson($exam);
        $this->assertSame(['numeric', 'mcq', 'true_false'], array_column($json['sections'], 'type'));
        $this->assertSame([2, 3, 4], array_column($json['sections'][1]['questions'], 'position'));
        $this->assertSame([5, 6], array_column($json['sections'][2]['questions'], 'position'));
        $this->assertSame(['digits' => 3, 'allow_negative' => false, 'allow_decimal' => true], $json['sections'][0]['numeric']);
        $this->assertFalse($json['key_complete']);
        $this->assertCount(6, $json['incomplete_questions']);
        $this->assertSame(['not_approved', 'no_key'], $json['incomplete_questions'][0]['reasons']);
        $this->assertSame(['pages' => 1, 'overflow' => false], $json['sheet']);
        $this->assertTrue($json['versions_ready']);
    }

    public function test_section_settings_are_validated(): void
    {
        $exam = $this->createExam();
        $post = fn (array $body) => $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", $body);

        $post(['type' => 'mcq'])->assertStatus(422)->assertJsonValidationErrors(['option_count']);
        $post(['type' => 'mcq', 'option_count' => 7])->assertStatus(422)->assertJsonValidationErrors(['option_count']);
        $post(['type' => 'mcq', 'option_count' => 1])->assertStatus(422)->assertJsonValidationErrors(['option_count']);
        $post(['type' => 'true_false', 'option_count' => 4])->assertStatus(422)->assertJsonValidationErrors(['option_count']);
        $post(['type' => 'numeric'])->assertStatus(422)->assertJsonValidationErrors(['numeric']);
        $post(['type' => 'numeric', 'numeric' => ['digits' => 6]])->assertStatus(422)->assertJsonValidationErrors(['numeric.digits']);
        $post(['type' => 'essay'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $post(['type' => 'mcq', 'option_count' => 4, 'question_count' => 101])->assertStatus(422)->assertJsonValidationErrors(['question_count']);

        config(['eduvision.exams.max_questions' => 5, 'eduvision.exams.max_sections' => 2]);
        $post(['type' => 'mcq', 'option_count' => 4, 'question_count' => 6])->assertStatus(422)->assertJsonPath('code', 'too_many_questions');
        $post(['type' => 'true_false', 'question_count' => 5])->assertCreated();
        $post(['type' => 'true_false'])->assertCreated();
        $post(['type' => 'true_false'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $section = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->firstOrFail();
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$section->id}/questions", [])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_questions');
    }

    public function test_a_typed_question_is_approved_and_keeps_its_place_in_the_section(): void
    {
        $exam = $this->createExam();
        $section = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);

        $question = $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$section['id']}/questions", [
            'prompt_text' => ' x^2 = 9 แล้ว x มีค่าเท่าใด ',
            'options' => [['text' => '3'], ['text' => '-3'], ['text' => ' '], ['text' => 'ถูกทั้ง ก และ ข']],
            'answer_key' => ['accepted_options' => [4, '1']],
            'max_points' => 2,
            'position' => 1,
        ])->assertCreated()->json('data');

        $this->assertSame(1, $question['position']);
        $this->assertSame('x^2 = 9 แล้ว x มีค่าเท่าใด', $question['prompt_text']);
        $this->assertNotNull($question['approved_at']);
        $this->assertFalse($question['blank']);
        $this->assertSame(['accepted_options' => [1, 4]], $question['answer_key']);
        $this->assertSame(['3', '-3', null, 'ถูกทั้ง ก และ ข'], array_column($question['options'], 'text'));
        $this->assertEquals(2, $question['max_points']);
        $this->assertTrue($question['lock_options_suggested']);
        $this->assertFalse($question['lock_options']);

        $json = $this->examJson($exam);
        $this->assertSame([1, 2, 3], array_column($json['sections'][0]['questions'], 'position'));
        $this->assertSame($question['id'], $json['sections'][0]['questions'][0]['id']);
        $this->assertSame([4], array_column($json['sections'][1]['questions'], 'position'));

        // Confirming the suggestion clears it.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$question['id']}", ['lock_options' => true])
            ->assertOk()->assertJsonPath('data.lock_options', true)->assertJsonPath('data.lock_options_suggested', false);
    }

    public function test_keys_follow_the_section_type(): void
    {
        $exam = $this->createExam();
        $mcq = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 3, 'question_count' => 1])['questions'][0]['id'];
        $tf = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1])['questions'][0]['id'];
        $num = $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_decimal' => true, 'allow_negative' => true], 'question_count' => 1])['questions'][0]['id'];
        $patch = fn (int $id, array $body) => $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", $body);

        $patch($mcq, ['answer_key' => ['accepted_options' => [4]]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_options.0']);
        $patch($mcq, ['answer_key' => ['accepted_options' => [2, 2]]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_options.1']);
        $patch($mcq, ['answer_key' => ['accepted_values' => ['1']]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_values']);
        $patch($mcq, ['answer_key' => ['correct' => 'A']])->assertOk()->assertJsonPath('data.answer_key', null);
        $patch($mcq, ['answer_key' => ['accepted_options' => [3, 2]]])->assertOk()->assertJsonPath('data.answer_key', ['accepted_options' => [2, 3]]);

        $patch($tf, ['answer_key' => ['accepted_options' => [1, 2]]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_options']);
        $patch($tf, ['answer_key' => ['accepted_options' => [3]]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_options.0']);
        $patch($tf, ['answer_key' => ['accepted_options' => [2]]])->assertOk()->assertJsonPath('data.answer_key', ['accepted_options' => [2]]);
        $patch($tf, ['options' => [['text' => 'ถูก']]])->assertStatus(422)->assertJsonValidationErrors(['options']);
        $patch($tf, ['lock_options' => true])->assertStatus(422)->assertJsonValidationErrors(['lock_options']);

        $patch($num, ['answer_key' => ['accepted_values' => ['.5', '0.50', '-12', 7]]])
            ->assertOk()->assertJsonPath('data.answer_key', ['accepted_values' => ['0.5', '-12', '7']]);
        $patch($num, ['answer_key' => ['accepted_values' => ['1234']]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_values.0']);
        $patch($num, ['answer_key' => ['accepted_values' => ['1', '1..2']]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_values.1']);
        $patch($num, ['answer_key' => ['accepted_options' => [1]]])->assertStatus(422)->assertJsonValidationErrors(['answer_key.accepted_options']);
        $patch($num, ['answer_key' => null])->assertOk()->assertJsonPath('data.answer_key', null);
    }

    public function test_a_blank_question_is_approved_by_what_the_grading_method_uses(): void
    {
        $exam = $this->createExam();
        $id = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1])['questions'][0]['id'];

        // app: a prompt alone does not approve it, the key does.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['prompt_text' => 'โจทย์'])
            ->assertOk()->assertJsonPath('data.approved_at', null)->assertJsonPath('data.blank', true);
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['answer_key' => ['accepted_options' => [1]]])
            ->assertOk()->assertJsonPath('data.blank', false);
        $this->assertNotNull(Question::query()->findOrFail($id)->approved_at);

        // manual: the prompt approves it.
        $manual = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 10]);
        $blank = $this->addSection($manual, ['type' => 'true_false', 'question_count' => 2])['questions'];
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$blank[0]['id']}", ['answer_key' => ['accepted_options' => [1]]])
            ->assertOk()->assertJsonPath('data.approved_at', null);
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$blank[0]['id']}", ['prompt_text' => 'ดวงอาทิตย์ขึ้นทางทิศตะวันออก'])
            ->assertOk()->assertJsonPath('data.blank', false);
        // "อนุมัติข้อนี้" by hand always works.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$blank[1]['id']}", ['approve' => true])
            ->assertOk()->assertJsonPath('data.blank', false);
        $json = $this->examJson($manual);
        $this->assertSame([['question_id' => $blank[1]['id'], 'position' => 2, 'reasons' => ['no_prompt']]], $json['booklet_incomplete_questions']);
    }

    public function test_the_answer_key_table_saves_many_questions_and_reports_each_error(): void
    {
        $exam = $this->createExam();
        $mcq = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2])['questions'];
        $num = $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 3], 'question_count' => 1])['questions'][0];
        $other = $this->createExam();
        $foreign = $this->addSection($other, ['type' => 'true_false', 'question_count' => 1])['questions'][0];

        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => [
            ['question_id' => $mcq[0]['id'], 'accepted_options' => [2]],
            ['question_id' => $num['id'], 'accepted_values' => ['0.5']],
            ['question_id' => $foreign['id'], 'accepted_options' => [1]],
            ['question_id' => $mcq[1]['id'], 'accepted_options' => [9]],
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'answers.1.accepted_values.0', 'answers.2.question_id', 'answers.3.accepted_options.0',
        ])->assertJsonMissingValidationErrors(['answers.0.accepted_options']);
        // Nothing was written.
        $this->assertNull(Question::query()->findOrFail($mcq[0]['id'])->answer_key);

        $json = $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => [
            ['question_id' => $mcq[0]['id'], 'accepted_options' => [2]],
            ['question_id' => $mcq[1]['id'], 'accepted_options' => [1, 3]],
            ['question_id' => $num['id'], 'accepted_values' => ['012', '12.0']],
        ]])->assertOk()->json('data');

        $this->assertTrue($json['key_complete']);
        $this->assertSame([], $json['incomplete_questions']);
        $this->assertSame(['accepted_values' => ['12']], $json['sections'][1]['questions'][0]['answer_key']);
        $this->assertFalse($json['sections'][0]['questions'][1]['blank']);

        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['answers']);
    }

    public function test_the_key_approval_gate_of_an_app_exam(): void
    {
        $exam = $this->createExam();
        $questions = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2])['questions'];

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'answer_key_incomplete')
            ->assertJsonPath('errors.questions.0', 'ข้อ 1: ยังไม่อนุมัติ, ยังไม่มีเฉลย');

        // A key set straight in the table approves the blank; a question read from a file needs the teacher.
        Question::query()->whereKey($questions[1]['id'])->update(['origin' => Question::ORIGIN_DOCUMENT]);
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => [
            ['question_id' => $questions[0]['id'], 'accepted_options' => [1]],
            ['question_id' => $questions[1]['id'], 'accepted_options' => [2]],
        ]])->assertOk()->assertJsonPath('data.incomplete_questions.0.reasons', ['not_approved']);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/questions/approve", ['question_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['question_ids.0']);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/questions/approve", ['question_ids' => [$questions[1]['id']]])
            ->assertOk()->assertJsonPath('data.key_complete', true);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")
            ->assertOk()
            ->assertJsonPath('data.exam.status', 'ready')
            ->assertJsonPath('data.key_complete', true);
        $this->assertNotNull($exam->refresh()->key_approved_at);

        // Changing a key keeps the approval while it stays complete; a new blank question takes it back.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$questions[0]['id']}", ['answer_key' => ['accepted_options' => [1, 4]]])->assertOk();
        $this->assertSame('ready', $exam->refresh()->status);
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$questions[0]['section_id']}/questions", ['prompt_text' => 'ข้อใหม่'])->assertCreated();
        $exam->refresh();
        $this->assertSame('draft', $exam->status);
        $this->assertNull($exam->key_approved_at);
    }

    public function test_switching_the_grading_method(): void
    {
        $exam = $this->createExam();
        $q = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1])['questions'][0];
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$q['id']}", ['answer_key' => ['accepted_options' => [1]]])->assertOk();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/answer-key/approve")->assertOk();

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'manual', 'manual_full_marks' => 30])
            ->assertOk()->assertJsonPath('data.grading_method', 'manual')->assertJsonPath('data.status', 'ready');
        // Back to app: ready again because the approved key is still complete.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'app'])
            ->assertOk()->assertJsonPath('data.status', 'ready');

        // With the key cleared while manual, going back to app is a draft without approval.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'manual'])->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$q['id']}", ['answer_key' => null])->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'app'])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.key_approved_at', null);

        $this->scanSheet($exam);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'manual'])
            ->assertStatus(409)->assertJsonPath('code', 'exam_sheets_scanned');
    }

    public function test_the_structure_is_locked_after_printing_but_content_stays_editable(): void
    {
        $exam = $this->createExam();
        $section = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $id = $section['questions'][0]['id'];
        $exam->forceFill(['structure_locked_at' => now()])->save();
        $locked = fn ($response) => $response->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');

        $locked($this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'true_false']));
        $locked($this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$section['id']}", ['option_count' => 5]));
        $locked($this->asUser($this->teacher)->deleteJson("/api/v1/exam-sections/{$section['id']}"));
        $locked($this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$section['id']}/questions", []));
        $locked($this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['lock_options' => true]));
        $locked($this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['position' => 2]));
        $locked($this->asUser($this->teacher)->deleteJson("/api/v1/questions/{$id}"));
        $locked($this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['version_count' => 3]));
        $locked($this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/versions/reshuffle"));

        // Text, options, points, key and section title are not structural.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", [
            'prompt_text' => 'แก้โจทย์', 'options' => [['text' => 'หนึ่ง']], 'max_points' => 2, 'answer_key' => ['accepted_options' => [1]],
        ])->assertOk()->assertJsonPath('data.options.0.text', 'หนึ่ง');
        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$section['id']}", ['title' => 'ตอนที่ 1', 'option_count' => 4])
            ->assertOk()->assertJsonPath('data.title', 'ตอนที่ 1');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['duration_minutes' => 45, 'show_key_to_students' => true])
            ->assertOk()->assertJsonPath('data.duration_minutes', 45);

        // Unlocking shuffles anew; once a sheet was scanned it is refused.
        $nonce = $exam->refresh()->shuffle_nonce;
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/unlock-structure")
            ->assertOk()->assertJsonPath('data.structure_locked_at', null);
        $this->assertSame($nonce + 1, $exam->refresh()->shuffle_nonce);

        $exam->forceFill(['structure_locked_at' => now()])->save();
        $this->scanSheet($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/unlock-structure")
            ->assertStatus(409)->assertJsonPath('code', 'exam_sheets_scanned');
    }

    public function test_sections_change_their_options_points_and_order(): void
    {
        $exam = $this->createExam();
        $mcq = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $tf = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);
        [$q1, $q2] = array_column($mcq['questions'], 'id');
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$q1}", ['answer_key' => ['accepted_options' => [4]]])->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$q2}", ['answer_key' => ['accepted_options' => [1, 4]], 'max_points' => 3])->assertOk();

        $section = $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$mcq['id']}", ['option_count' => 3, 'default_points' => 2])
            ->assertOk()->json('data');
        $this->assertCount(3, $section['questions'][0]['options']);
        // Option ง is gone from the key; the only accepted answer was ง.
        $this->assertNull($section['questions'][0]['answer_key']);
        $this->assertSame(['accepted_options' => [1]], $section['questions'][1]['answer_key']);
        // Points follow the new default only where the old default was kept.
        $this->assertEquals([2, 3], array_column($section['questions'], 'max_points'));

        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$mcq['id']}", ['option_count' => 6])->assertOk()
            ->assertJsonCount(6, 'data.questions.0.options');
        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$mcq['id']}", ['type' => 'numeric'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$tf['id']}", ['position' => 1])->assertOk()->assertJsonPath('data.position', 1);
        $json = $this->examJson($exam);
        $this->assertSame([1], array_column($json['sections'][0]['questions'], 'position'));
        $this->assertSame([2, 3], array_column($json['sections'][1]['questions'], 'position'));
    }

    public function test_deleting_questions_and_sections_renumbers_the_exam(): void
    {
        $exam = $this->createExam();
        $first = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $second = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 2]);

        $this->asUser($this->teacher)->deleteJson("/api/v1/questions/{$first['questions'][0]['id']}")->assertNoContent();
        $this->assertSame(0, QuestionOption::query()->where('question_id', $first['questions'][0]['id'])->count());
        $json = $this->examJson($exam);
        $this->assertSame([1, 2], array_column($json['sections'][0]['questions'], 'position'));
        $this->assertSame([3, 4], array_column($json['sections'][1]['questions'], 'position'));

        $this->asUser($this->teacher)->deleteJson("/api/v1/exam-sections/{$first['id']}")->assertNoContent();
        $json = $this->examJson($exam);
        $this->assertCount(1, $json['sections']);
        $this->assertSame(1, $json['sections'][0]['position']);
        $this->assertSame($second['id'], $json['sections'][0]['id']);
        $this->assertSame([1, 2], array_column($json['sections'][0]['questions'], 'position'));
    }

    public function test_question_and_option_images_are_scaled_jpegs_behind_the_policy(): void
    {
        $exam = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 10]);
        $question = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1])['questions'][0];

        $this->asUser($this->teacher)->post("/api/v1/questions/{$question['id']}/image", [
            'image' => UploadedFile::fake()->image('figure.png', 2400, 1200),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.has_prompt_image', true)
            // A blank question of a manual exam now has a prompt, so it is approved.
            ->assertJsonPath('data.blank', false);

        $stored = Question::query()->findOrFail($question['id'])->prompt_image_path;
        $this->assertSame("exams/{$exam->school_id}/{$exam->id}/figures/q{$question['id']}.jpg", $stored);
        $size = getimagesizefromstring(Storage::disk('local')->get($stored));
        $this->assertSame([1600, 800, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
        $this->asUser($this->teacher)->get("/api/v1/questions/{$question['id']}/image")
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $option = $question['options'][1]['id'];
        $this->asUser($this->teacher)->post("/api/v1/question-options/{$option}/image", [
            'image' => UploadedFile::fake()->image('option.jpg', 300, 200),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.options.1.has_image', true);
        $this->asUser($this->teacher)->get("/api/v1/question-options/{$option}/image")->assertOk();

        $this->asUser($this->teacher)->post("/api/v1/questions/{$question['id']}/image", [
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->asUser($this->teacher)->post("/api/v1/questions/{$question['id']}/image", [
            'image' => UploadedFile::fake()->image('big.jpg', 100, 100)->size(6000),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->asUser($this->teacher)->postJson("/api/v1/questions/{$question['id']}/image")
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->asUser($this->teacher)->deleteJson("/api/v1/questions/{$question['id']}/image")
            ->assertOk()->assertJsonPath('data.has_prompt_image', false);
        Storage::disk('local')->assertMissing($stored);
        $this->asUser($this->teacher)->get("/api/v1/questions/{$question['id']}/image")->assertNotFound();

        // A homework question has no exam image routes.
        $homework = Question::factory()->short()->create([
            'assignment_id' => Assignment::factory()->for_classroom($this->classroom)->create()->id, 'position' => 1,
        ]);
        $this->asUser($this->teacher)->getJson("/api/v1/questions/{$homework->id}/image")->assertNotFound();

        // Deleting the (manual, unprinted) exam removes its figures.
        $this->asUser($this->teacher)->deleteJson("/api/v1/assignments/{$exam->id}")->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles("exams/{$exam->school_id}/{$exam->id}"));
    }

    public function test_another_teachers_exam_is_not_found(): void
    {
        $exam = $this->createExam();
        $section = $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);
        $other = $this->makeTeacher($this->teacher->school);

        $this->asUser($other)->getJson("/api/v1/exams/{$exam->id}")->assertNotFound();
        $this->asUser($other)->patchJson("/api/v1/exam-sections/{$section['id']}", ['title' => 'x'])->assertNotFound();
        $this->asUser($other)->patchJson("/api/v1/questions/{$section['questions'][0]['id']}", ['prompt_text' => 'x'])->assertNotFound();
        $this->asUser($other)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => []])->assertNotFound();
    }
}
