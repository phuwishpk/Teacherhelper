<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamImages;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.4 item 3, §22.15, §22.17: the teacher's own earlier exams are a
 * library of questions to copy (never another teacher's), with text,
 * options, images, key, "ห้ามสลับตัวเลือก" and the indicators the school
 * still sees, keeping the source's approval.
 */
class ExamCopyQuestionsTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $source;

    /** @var array<string, mixed> */
    private array $mcq;

    /** @var array<string, mixed> */
    private array $numeric;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();

        $this->source = $this->createExam(['title' => 'สอบกลางภาค 2568']);
        $this->mcq = $this->addSection($this->source, ['title' => 'ตอนที่ 1', 'instructions' => 'เลือกคำตอบ', 'type' => 'mcq', 'option_count' => 4, 'default_points' => 2]);
        $this->numeric = $this->addSection($this->source, ['title' => 'ตอนที่ 2', 'type' => 'numeric', 'numeric' => ['digits' => 3, 'allow_decimal' => true]]);
        $this->typed($this->mcq['id'], ['prompt_text' => 'รูปใดเป็นสามเหลี่ยม', 'options' => [['text' => 'ก'], ['text' => 'ข'], ['text' => 'ค'], ['text' => 'ถูกทุกข้อ']], 'answer_key' => ['accepted_options' => [4]], 'lock_options' => true, 'max_points' => 3]);
        $this->typed($this->mcq['id'], ['prompt_text' => 'สองบวกสอง', 'options' => [['text' => '3'], ['text' => '4'], ['text' => '5'], ['text' => '6']], 'answer_key' => ['accepted_options' => [2]]]);
        $this->typed($this->numeric['id'], ['prompt_text' => 'ครึ่งหนึ่งเป็นทศนิยม', 'answer_key' => ['accepted_values' => ['0.5', '12.5']]]);
    }

    /** @param  array<string, mixed>  $fields */
    private function typed(int $sectionId, array $fields): Question
    {
        $id = $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$sectionId}/questions", $fields)->assertCreated()->json('data.id');

        return Question::query()->findOrFail($id);
    }

    /** @return list<int> */
    private function sourceIds(): array
    {
        return Question::query()->where('assignment_id', $this->source->id)->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_the_library_lists_only_the_teachers_own_exam_questions(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $classroom = $this->makeClassroom($colleague);
        $course = $this->makeCourse($colleague, [$classroom]);
        $theirs = $this->asUser($colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $classroom->id, 'course_id' => $course->id, 'title' => 'ของครูอื่น', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ])->assertCreated()->json('data.id');
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs}/sections", ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3])->assertCreated();
        $homework = $this->asUser($this->teacher)->postJson('/api/v1/assignments', ['classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'การบ้าน'])->assertCreated()->json('data.id');

        $list = $this->asUser($this->teacher)->getJson('/api/v1/teacher/exam-questions')->assertOk()->json();
        $this->assertSame($this->sourceIds(), array_column($list['data'], 'id'), 'no colleague question, no homework question');
        $this->assertSame(['id' => $this->source->id, 'title' => 'สอบกลางภาค 2568', 'course_id' => $this->course->id], $list['data'][0]['exam']);
        $this->assertSame(['type' => 'numeric', 'numeric' => ['digits' => 3, 'allow_negative' => false, 'allow_decimal' => true]], array_intersect_key($list['data'][2]['section'], ['type' => 1, 'numeric' => 1]));
        $this->assertNull($list['meta']['next_cursor']);
        $this->assertNotNull($homework);

        $this->asUser($this->teacher)->getJson('/api/v1/teacher/exam-questions?q='.urlencode('สองบวก'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prompt_text', 'สองบวกสอง');
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/exam-questions?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->getJson("/api/v1/teacher/exam-questions?exclude_exam={$this->source->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->getJson("/api/v1/teacher/exam-questions?exam_id={$this->source->id}&course_id={$this->course->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($this->teacher)->getJson("/api/v1/teacher/exam-questions?exam_id={$theirs}")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($colleague)->getJson('/api/v1/teacher/exam-questions')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.exam.title', 'ของครูอื่น');
    }

    public function test_questions_copy_into_new_sections_with_their_images_key_and_approval(): void
    {
        [$first, $second, $third] = $this->sourceIds();
        $this->asUser($this->teacher)->post("/api/v1/questions/{$first}/image", ['image' => UploadedFile::fake()->image('fig.png', 40, 30)], ['Accept' => 'application/json'])->assertOk();
        $option = QuestionOption::query()->where('question_id', $first)->where('position', 2)->sole();
        $this->asUser($this->teacher)->post("/api/v1/question-options/{$option->id}/image", ['image' => UploadedFile::fake()->image('opt.png', 20, 20)], ['Accept' => 'application/json'])->assertOk();
        $subject = Subject::query()->where('code', 'ค')->sole();
        $visible = Skill::factory()->create(['subject_id' => $subject->id, 'code' => 'ค 1.1 ป.5/1', 'level' => Skill::LEVEL_INDICATOR]);
        $hidden = Skill::factory()->create(['subject_id' => $subject->id, 'code' => 'ค 1.1 ป.5/9', 'level' => Skill::LEVEL_INDICATOR, 'school_id' => $this->makeSchool()->id]);
        Question::query()->findOrFail($first)->skills()->sync([$visible->id, $hidden->id]);
        Question::query()->findOrFail($second)->forceFill(['approved_at' => null])->save();

        $target = $this->createExam(['title' => 'สอบกลางภาค 2569']);
        $this->addSection($target, ['type' => 'true_false', 'question_count' => 2]);
        $data = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$third, $first, $second]])
            ->assertCreated()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.skipped', [])
            ->json('data');

        $sections = $data['exam']['sections'];
        $this->assertSame(['true_false', 'numeric', 'mcq'], array_column($sections, 'type'), 'one new section per source section, in order of the selection');
        $this->assertSame(['ตอนที่ 2', 'ตอนที่ 1'], [$sections[1]['title'], $sections[2]['title']]);
        $this->assertSame(['เลือกคำตอบ', 2.0], [$sections[2]['instructions'], (float) $sections[2]['default_points']]);
        $copies = Question::query()->whereKey($data['question_ids'])->get()->keyBy('copied_from_question_id');
        $this->assertSame([3, 4, 5], [$copies[$third]->position, $copies[$first]->position, $copies[$second]->position]);
        $this->assertSame(['origin' => 'copied', 'lock_options' => true, 'max_points' => 3.0], ['origin' => $copies[$first]->origin, 'lock_options' => $copies[$first]->lock_options, 'max_points' => $copies[$first]->max_points]);
        $this->assertSame(['accepted_options' => [4]], $copies[$first]->answer_key);
        $this->assertSame(['accepted_values' => ['0.5', '12.5']], $copies[$third]->answer_key);
        $this->assertNotNull($copies[$first]->approved_at);
        $this->assertNull($copies[$second]->approved_at, 'the source draft stays a draft');
        $this->assertSame([$visible->id], $copies[$first]->skills()->pluck('skills.id')->map(fn ($id) => (int) $id)->all(), 'only indicators the school still sees');

        // The image files are copied, not shared.
        $copy = $copies[$first];
        $this->assertSame("exams/{$target->school_id}/{$target->id}/figures/q{$copy->id}.jpg", $copy->prompt_image_path);
        $this->assertTrue(ExamImages::disk()->exists($copy->prompt_image_path));
        $copiedOption = QuestionOption::query()->where('question_id', $copy->id)->where('position', 2)->sole();
        $this->assertSame("exams/{$target->school_id}/{$target->id}/figures/o{$copiedOption->id}.jpg", $copiedOption->image_path);
        $this->assertSame('ถูกทุกข้อ', QuestionOption::query()->where('question_id', $copy->id)->where('position', 4)->sole()->text);
        $this->asUser($this->teacher)->deleteJson("/api/v1/questions/{$copy->id}/image")->assertOk();
        $this->assertTrue(ExamImages::disk()->exists(Question::query()->findOrFail($first)->prompt_image_path), 'the source keeps its figure');
    }

    public function test_copying_into_a_section_skips_what_does_not_fit_it(): void
    {
        [$first, $second, $third] = $this->sourceIds();
        $other = $this->addSection($this->source, ['type' => 'mcq', 'option_count' => 5, 'question_count' => 1]);
        $five = Question::query()->where('section_id', $other['id'])->sole()->id;

        $target = $this->createExam(['title' => 'สอบย่อย']);
        $mcq = $this->addSection($target, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);
        $numeric = $this->addSection($target, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_decimal' => false]]);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$first, $five, $third], 'section_id' => $mcq['id']])
            ->assertCreated()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', [
                ['question_id' => $five, 'reason' => 'option_count_mismatch', 'reason_th' => 'จำนวนตัวเลือกไม่ตรงกับตอนปลายทาง'],
                ['question_id' => $third, 'reason' => 'type_mismatch', 'reason_th' => 'ชนิดของข้อไม่ตรงกับตอนปลายทาง'],
            ])
            ->assertJsonPath('data.exam.sections.0.question_count', 2)
            ->assertJsonPath('data.exam.sections.0.questions.1.prompt_text', 'รูปใดเป็นสามเหลี่ยม');

        // Into a 2-digit block without decimals: 0.5 and 12.5 do not fit, so the copy has no key.
        $copy = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$third], 'section_id' => $numeric['id']])
            ->assertCreated()->json('data.question_ids.0');
        $this->assertNull(Question::query()->findOrFail($copy)->answer_key);
        $this->assertSame(3, Question::query()->findOrFail($copy)->position);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$second], 'section_id' => ExamSection::query()->where('assignment_id', $this->source->id)->value('id')])
            ->assertStatus(422)->assertJsonValidationErrors('section_id');
    }

    public function test_another_teachers_question_cannot_be_copied_and_limits_hold(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $classroom = $this->makeClassroom($colleague);
        $course = $this->makeCourse($colleague, [$classroom]);
        $theirs = $this->asUser($colleague)->postJson('/api/v1/assignments', [
            'classroom_id' => $classroom->id, 'course_id' => $course->id, 'title' => 'ของครูอื่น', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ])->assertCreated()->json('data.id');
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs}/sections", ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1])->assertCreated();
        $foreign = Question::query()->where('assignment_id', $theirs)->value('id');

        $target = $this->createExam(['title' => 'สอบย่อย']);
        [$first] = $this->sourceIds();
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$first, $foreign]])
            ->assertStatus(422)->assertJsonValidationErrors('question_ids.1');
        $this->asUser($colleague)->postJson("/api/v1/exams/{$theirs}/copy-questions", ['question_ids' => [$first]])
            ->assertStatus(422)->assertJsonValidationErrors('question_ids.0');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", [])->assertStatus(422)->assertJsonValidationErrors('question_ids');
        $this->assertSame(0, Question::query()->where('assignment_id', $target->id)->count());

        $this->addSection($target, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 100]);
        $this->addSection($target, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 99]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => $this->sourceIds()])
            ->assertStatus(422)->assertJsonPath('code', 'too_many_questions');

        $target->forceFill(['structure_locked_at' => now()])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => [$first]])
            ->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
    }
}
