<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantChat;
use App\Domain\Assistant\AssistantPlanApplier;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Jobs\DraftRubricJob;
use App\Models\Assignment;
use App\Models\AssistantMessage;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\ExamSection;
use App\Models\IndicatorSuggestion;
use App\Models\Question;
use App\Models\Skill;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §37.6: the assistant proposes questions as a plan card; «สร้างเลย»
 * (or a typed confirmation) creates the ticked ones through the teacher's
 * own services.
 */
class AssistantPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000065', 'key_last4' => '0065']);
    }

    private function assignment(array $fields = []): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $fields + [
            'classroom_id' => $this->classroom->id,
            'course_id' => $this->course->id,
            'title' => 'การบ้านเศษส่วน',
        ])->assertCreated()->json('data.id');

        return Assignment::query()->findOrFail($id);
    }

    private function exam(): Assignment
    {
        return $this->assignment(['title' => 'สอบเศษส่วน', 'kind' => 'exam', 'due_at' => '2026-10-30T02:00:00Z', 'duration_minutes' => 60]);
    }

    /** @return array<string, mixed> the send response's data */
    private function ask(string $text, ?int $conversation = null): array
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => $text, 'conversation_id' => $conversation])
            ->assertOk()->json('data');
    }

    private function apply(int $messageId, array $selected, ?array $target = null): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$messageId}/apply", ['selected' => $selected, 'target' => $target]);
    }

    public function test_the_card_is_checked_against_the_target_and_the_ai_sees_only_names(): void
    {
        $homework = $this->assignment();
        Question::create(['assignment_id' => $homework->id, 'position' => 1, 'type' => 'short', 'prompt_text' => 'ข้อเดิมที่มีอยู่แล้ว', 'max_points' => 1, 'answer_key' => ['accepted' => ['1']], 'rubric_status' => 'not_needed']);
        $student = $this->enrollStudent($this->classroom, 1, 'เด็กหญิงลับเฉพาะ')['student'];

        $data = $this->ask("[fake:plan:{$homework->id}] เพิ่มโจทย์ในการบ้านเศษส่วน 7 ข้อ");
        $card = $data['messages'][1];

        $this->assertSame('proposed', $card['plan_status']);
        $this->assertSame(['assignment_id' => $homework->id, 'title' => 'การบ้านเศษส่วน', 'kind' => 'worksheet', 'classroom' => 'ป.5/1', 'status' => 'draft'], $card['plan']['target']);
        $flags = array_column($card['plan']['questions'], 'flags');
        $this->assertSame([[], [], [], [], ['unsupported'], ['unsupported'], ['needs_figure', 'unsupported', 'incomplete_key']], $flags);
        $this->assertSame('0.5', $card['plan']['questions'][1]['numeric_value']);
        $this->assertNull($data['apply_message_id']);

        $system = $this->gemini->requests[0]->systemInstruction;
        $this->assertStringContainsString('# YOUR DATA', $system);
        $this->assertStringContainsString("{$this->classroom->id} | ป.5/1 | ป.5 | courses: {$this->course->id} ค15101", $system);
        $this->assertStringContainsString("## Assignment {$homework->id} \"การบ้านเศษส่วน\" (worksheet)", $system);
        $this->assertStringContainsString('1. short: ข้อเดิมที่มีอยู่แล้ว', $system);
        $this->assertStringNotContainsString($student->name, $system);
        $this->assertSame(8192, $this->gemini->requests[0]->maxOutputTokens);
    }

    public function test_apply_creates_homework_questions_like_the_teacher_would(): void
    {
        $homework = $this->assignment();
        $homework->update(['status' => Assignment::STATUS_READY]);
        $card = $this->ask("[fake:plan:{$homework->id}] เพิ่มโจทย์")['messages'][1];

        $result = $this->apply($card['id'], [0, 1, 2, 3, 4, 5])->assertOk()->json('data.message');

        $this->assertSame('applied', $result['plan_status']);
        $this->assertSame(4, $result['plan']['result']['created']);
        $this->assertSame([4, 5], array_column($result['plan']['result']['skipped'], 'index'));
        $questions = Question::query()->where('assignment_id', $homework->id)->orderBy('position')->get();
        $this->assertSame(['mcq', 'short', 'show_work', 'open'], $questions->pluck('type')->all());
        $this->assertStringContainsString("\nB. 3/4", $questions[0]->prompt_text);
        $this->assertSame(['correct' => 'B'], $questions[0]->answer_key);
        $this->assertTrue($questions[1]->is_numeric);
        $this->assertSame(['24 ÷ 2', '= 12'], $questions[2]->answer_key['reference_steps']);
        $this->assertSame('draft', $questions[3]->rubric_status);
        $this->assertSame(6, $questions[3]->answer_lines);
        Queue::assertPushed(DraftRubricJob::class, fn (DraftRubricJob $job) => $job->questionId === $questions[3]->id);
        // A ready worksheet goes back to draft, like an edit in the question screen.
        $this->assertSame(Assignment::STATUS_DRAFT, $homework->refresh()->status);

        $this->apply($card['id'], [0])->assertStatus(409)->assertJsonPath('code', 'plan_not_open');
    }

    public function test_a_typed_confirmation_points_at_the_open_card_and_a_new_card_replaces_it(): void
    {
        $homework = $this->assignment();
        $first = $this->ask("[fake:plan:{$homework->id}] เพิ่มโจทย์");
        $conversation = $first['conversation']['id'];
        $second = $this->ask("[fake:plan:{$homework->id}] ข้อ 3 ยากไป", $conversation);

        $this->assertSame('replaced', AssistantMessage::query()->findOrFail($first['messages'][1]['id'])->plan_status);
        $this->apply($first['messages'][1]['id'], [0])->assertStatus(409);

        $confirm = $this->ask('[fake:confirm] โอเค สร้างเลย', $conversation);
        $this->assertSame($second['messages'][1]['id'], $confirm['apply_message_id']);
        $this->assertSame(AssistantChat::APPLYING, $confirm['messages'][1]['content']);
        $this->assertNull($confirm['messages'][1]['plan']);
        // The history tells Gemini what the cards were.
        $history = end($this->gemini->requests)->history;
        $this->assertStringContainsString("[CARD replaced: assignment {$homework->id}", $history[1]['text']);
        $this->assertStringContainsString('[CARD proposed', $history[3]['text']);
        $this->assertStringContainsString('1. mcq: 1/2 + 1/4', $history[3]['text']);

        // A confirmation with no open card says so instead of the AI's words.
        $this->apply($second['messages'][1]['id'], [0])->assertOk();
        $again = $this->ask('[fake:confirm] สร้างเลย', $conversation);
        $this->assertNull($again['apply_message_id']);
        $this->assertSame(AssistantChat::NO_OPEN_CARD, $again['messages'][1]['content']);
    }

    public function test_a_new_freeform_assignment_is_a_draft_kept_away_from_classroom(): void
    {
        $card = $this->ask("[fake:plan-new:{$this->classroom->id}:{$this->course->id}:freeform] ทำการบ้านใหม่")['messages'][1];
        $this->assertSame(['classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'แบบฝึกเศษส่วน', 'kind' => 'freeform'], $card['plan']['target']['new']);

        $result = $this->apply($card['id'], [0, 1], ['new' => ['classroom_id' => $this->classroom->id, 'title' => 'แบบฝึกเศษส่วน ชุด 2', 'kind' => 'freeform']])
            ->assertOk()->json('data.message.plan.result');

        $assignment = Assignment::query()->findOrFail($result['assignment_id']);
        $this->assertSame('แบบฝึกเศษส่วน ชุด 2', $assignment->title);
        $this->assertSame(Assignment::MODE_FREEFORM, $assignment->mode);
        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);
        $this->assertFalse((bool) $assignment->send_to_google);
        $this->assertNull($assignment->due_at);
        $this->assertSame(Assignment::KEY_AI_DRAFT, $assignment->key_origin);
        $this->assertNull($assignment->key_approved_at);
        $this->assertSame(2, $assignment->questions()->count());
    }

    public function test_exam_questions_join_a_matching_section_or_get_a_new_one_with_indicator_suggestions(): void
    {
        $exam = $this->exam();
        $skill = Skill::create(['subject_id' => $this->course->subject_id, 'code' => 'FAKE-1', 'name' => 'บวกเศษส่วน', 'level' => 'indicator', 'source' => 'curriculum']);
        $this->course->indicators()->attach($skill->id);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", ['type' => 'mcq', 'option_count' => 4])->assertCreated();
        $card = $this->ask("[fake:plan:{$exam->id}] ออกข้อสอบ")['messages'][1];
        $this->assertSame([['unsupported'], ['unsupported'], ['unsupported'], [], [], ['needs_figure', 'incomplete_key']], array_slice(array_column($card['plan']['questions'], 'flags'), 1));

        $result = $this->apply($card['id'], [0, 4, 5, 6])->assertOk()->json('data.message.plan.result');

        $this->assertSame(3, $result['created']);
        $this->assertSame([['index' => 6, 'reason' => 'เฉลยไม่ครบ']], $result['skipped']);
        $sections = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $this->assertSame(['mcq', 'true_false', 'numeric'], $sections->pluck('type')->all());
        $this->assertSame(3, (int) $sections[2]->numeric_digits);
        $this->assertTrue((bool) $sections[2]->numeric_allow_decimal);
        $questions = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $this->assertSame([[2], [1]], [$questions[0]->answer_key['accepted_options'], $questions[1]->answer_key['accepted_options']]);
        $this->assertSame(['12.5'], $questions[2]->answer_key['accepted_values']);
        $this->assertSame(['1/6', '3/4', '2/6', '1'], $questions[0]->options()->orderBy('position')->pluck('text')->all());
        $this->assertTrue($questions->every(fn (Question $q) => $q->approved_at !== null));
        $this->assertSame(
            [['question_id' => $questions[0]->id, 'skill_id' => $skill->id, 'reason_th' => AssistantPlanApplier::SUGGESTION_REASON]],
            IndicatorSuggestion::query()->get(['question_id', 'skill_id', 'reason_th'])->toArray(),
        );
    }

    public function test_locks_closed_classrooms_and_nothing_to_create_change_nothing(): void
    {
        $exam = $this->exam();
        $exam->forceFill(['structure_locked_at' => now()])->save();
        $card = $this->ask("[fake:plan:{$exam->id}] ออกข้อสอบ")['messages'][1];
        $this->apply($card['id'], [4])->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
        $this->assertSame(0, ExamSection::query()->where('assignment_id', $exam->id)->count());
        $this->assertSame('proposed', AssistantMessage::query()->findOrFail($card['id'])->plan_status);

        // Only unsupported questions for a new homework: no empty assignment is left.
        $count = Assignment::query()->count();
        $this->apply($card['id'], [4, 5], ['new' => ['classroom_id' => $this->classroom->id, 'title' => 'ใหม่', 'kind' => 'worksheet']])
            ->assertStatus(422)->assertJsonPath('code', 'plan_nothing_created')->assertJsonPath('skipped.0.index', 4);
        $this->assertSame($count, Assignment::query()->count());

        $homework = $this->assignment();
        $this->classroom->forceFill(['closed_at' => now()])->save();
        $this->apply($card['id'], [0], ['assignment_id' => $homework->id])->assertStatus(409)->assertJsonPath('code', 'classroom_closed');
    }

    public function test_cards_and_targets_of_other_teachers_are_out_of_reach(): void
    {
        $homework = $this->assignment();
        $card = $this->ask("[fake:plan:{$homework->id}] เพิ่มโจทย์")['messages'][1];
        $other = $this->makeTeacher();
        $this->asUser($other)->postJson("/api/v1/assistant/messages/{$card['id']}/apply", ['selected' => [0]])->assertNotFound();

        $theirs = Assignment::factory()->for_classroom($this->makeClassroom($other))->create();
        $this->apply($card['id'], [0], ['assignment_id' => $theirs->id])->assertStatus(422)->assertJsonPath('code', 'plan_no_target');
        // A card naming someone else's assignment asks the teacher to pick one.
        $foreign = $this->ask("[fake:plan:{$theirs->id}] เพิ่มโจทย์")['messages'][1];
        $this->assertArrayHasKey('error', $foreign['plan']['target']);
        $this->apply($foreign['id'], [0])->assertStatus(422)->assertJsonPath('code', 'plan_no_target');
        $this->apply($card['id'], [])->assertStatus(422);
        $this->apply($card['id'], [9])->assertStatus(422);
        $this->assertSame(0, $theirs->questions()->count());
    }
}
