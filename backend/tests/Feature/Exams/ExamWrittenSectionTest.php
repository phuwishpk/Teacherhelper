<?php

namespace Tests\Feature\Exams;

use App\Models\ExamSection;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.1–§22.20.2: an `app` exam may have written sections (open,
 * show_work). They come last, never on a manual exam or a shared sheet, and
 * their questions start as blank drafts with eight lines and no rubric.
 */
class ExamWrittenSectionTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
    }

    public function test_written_types_are_known_and_choice_types_unchanged(): void
    {
        $this->assertSame(['open', 'show_work'], ExamSection::WRITTEN_TYPES);
        $this->assertSame(['mcq', 'true_false', 'numeric'], ExamSection::TYPES);
        $this->assertTrue((new ExamSection(['type' => 'open']))->isWritten());
        $this->assertTrue(ExamSection::isWrittenType('show_work'));
        $this->assertFalse((new ExamSection(['type' => 'mcq']))->isWritten());
        $this->assertSame(0, (new ExamSection(['type' => 'show_work']))->choiceCount());
        $this->assertSame(8, ExamSection::DEFAULT_ANSWER_LINES);
    }

    public function test_written_section_is_created_with_blank_questions(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);

        $section = $this->addSection($exam, ['type' => 'open', 'question_count' => 3, 'default_points' => 5]);

        $this->assertSame('open', $section['type']);
        $this->assertNull($section['option_count']);
        $this->assertNull($section['numeric']);
        $questions = Question::query()->where('section_id', $section['id'])->orderBy('position')->get();
        $this->assertCount(3, $questions);
        foreach ($questions as $question) {
            $this->assertSame('open', $question->type);
            $this->assertSame(8, $question->answer_lines);
            $this->assertSame(Question::RUBRIC_DRAFT, $question->rubric_status);
            $this->assertNull($question->approved_at);
            $this->assertSame('teacher', $question->origin);
            $this->assertEquals(5, $question->max_points);
        }
        $this->assertSame([3, 4, 5], $questions->pluck('position')->all());
    }

    public function test_option_count_and_numeric_are_refused_for_written(): void
    {
        $exam = $this->createExam();

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'show_work', 'option_count' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('option_count');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'open', 'numeric' => ['digits' => 2]])
            ->assertStatus(422)->assertJsonValidationErrors('numeric');
    }

    public function test_written_section_must_come_last(): void
    {
        $exam = $this->createExam();
        $mcq = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);
        $open = $this->addSection($exam, ['type' => 'open', 'question_count' => 1]);
        $url = "/api/v1/exams/{$exam->id}/sections";

        $this->asUser($this->teacher)->postJson($url, ['type' => 'true_false'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_section_last')
            ->assertJsonPath('message', 'ตอนเขียนตอบต้องอยู่หลังตอนที่ฝนทุกตอน');
        $this->asUser($this->teacher)->postJson($url, ['type' => 'show_work', 'position' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_section_last');
        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$open['id']}", ['position' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_section_last');

        $this->assertSame(
            [$mcq['id'], $open['id']],
            ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->pluck('id')->all(),
        );
        $this->assertSame(2, ExamSection::query()->where('assignment_id', $exam->id)->count());
    }

    public function test_written_refused_on_manual_exam(): void
    {
        $manual = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 20]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$manual->id}/sections", ['type' => 'open'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');

        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'open', 'question_count' => 1]);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['grading_method' => 'manual', 'manual_full_marks' => 20])
            ->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
        $this->assertSame('app', $exam->refresh()->grading_method);
    }

    public function test_written_refused_on_code_sheet_exam(): void
    {
        $code = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$code->id}/sections", ['type' => 'show_work'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_needs_qr_sheet')
            ->assertJsonPath('message', 'ข้อเขียนใช้กับกระดาษคำตอบแบบ QR รายคนเท่านั้น');

        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'open', 'question_count' => 1]);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['sheet_identity' => 'code', 'student_code_digits' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_needs_qr_sheet');
        $this->assertSame('qr', $exam->refresh()->sheet_identity);
    }

    public function test_written_section_refused_when_structure_locked(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);
        $exam->forceFill(['structure_locked_at' => now()])->save();

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'open'])
            ->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
        $this->assertSame(1, ExamSection::query()->where('assignment_id', $exam->id)->count());
    }

    public function test_a_choice_section_can_be_inserted_before_a_written_one(): void
    {
        $exam = $this->createExam();
        $open = $this->addSection($exam, ['type' => 'open', 'question_count' => 1]);

        $mcq = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2, 'position' => 1]);

        $this->assertSame(
            [$mcq['id'], $open['id']],
            ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 2, 3],
            Question::query()->where('assignment_id', $exam->id)->orderBy('position')->pluck('position')->all(),
            'the written question is renumbered after the new bubble questions',
        );
        $this->assertSame('open', Question::query()->where('assignment_id', $exam->id)->orderByDesc('position')->value('type'));
    }
}
