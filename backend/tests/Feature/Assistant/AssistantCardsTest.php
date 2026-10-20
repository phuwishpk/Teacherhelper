<?php

namespace Tests\Feature\Assistant;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\IndicatorSuggestion;
use App\Models\Question;
use App\Models\Skill;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §37.7: rubric and indicator cards of "ผู้ช่วย Krucheck".
 */
class AssistantCardsTest extends TestCase
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
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000066', 'key_last4' => '0066']);
    }

    private function homework(): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'การบ้านเศษส่วน',
        ])->assertCreated()->json('data.id');
        $assignment = Assignment::query()->findOrFail($id);
        foreach ([
            ['open', 3, null], ['show_work', 2, ['final' => ['accepted' => ['12']], 'reference_steps' => []]],
            ['mcq', 1, ['correct' => 'A']], ['open', 5, null],
        ] as $i => [$type, $points, $key]) {
            Question::create([
                'assignment_id' => $assignment->id, 'position' => $i + 1, 'type' => $type, 'prompt_text' => "ข้อ {$type}",
                'max_points' => $points, 'answer_key' => $key, 'answer_lines' => $type === 'mcq' ? null : 5,
                'rubric_status' => $type === 'mcq' ? 'not_needed' : 'draft',
            ]);
        }

        return $assignment;
    }

    /** @return array<string, mixed> the card message */
    private function card(string $text): array
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => $text])->assertOk()->json('data.messages.1');
    }

    private function apply(int $id, ?array $selected = null): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$id}/apply", $selected === null ? [] : ['selected' => $selected]);
    }

    public function test_a_rubric_card_saves_a_draft_the_teacher_approves_later(): void
    {
        $homework = $this->homework();
        $open = Question::query()->where('assignment_id', $homework->id)->where('position', 1)->firstOrFail();
        $open->rubricCriteria()->create(['position' => 1, 'description' => 'เกณฑ์เดิม', 'points' => 3, 'is_core' => true, 'source' => 'teacher']);
        $open->update(['rubric_status' => 'approved']);
        $homework->update(['status' => Assignment::STATUS_READY]);

        $card = $this->card("[fake:rubric:{$homework->id}:1] ทำ rubric ข้อ 1 ของการบ้านเศษส่วน");
        $this->assertSame('rubric', $card['plan']['kind']);
        $this->assertSame(['id' => $open->id, 'position' => 1, 'type' => 'open', 'prompt' => 'ข้อ open', 'max_points' => 3, 'rubric_status' => 'approved'], $card['plan']['question']);
        $this->assertCount(2, $card['plan']['criteria']);
        $this->assertSame([], $card['plan']['reference_steps']);
        $this->assertArrayNotHasKey('problem', $card['plan']);
        // The context lists points and rubric states.
        $this->assertStringContainsString('1. open (3 pts, rubric approved): ข้อ open', $this->gemini->requests[0]->systemInstruction);

        $result = $this->apply($card['id'])->assertOk()->json('data.message.plan.result');
        $this->assertSame(['assignment_id' => $homework->id, 'title' => 'การบ้านเศษส่วน', 'question_id' => $open->id, 'position' => 1], array_diff_key($result, ['at' => 0]));
        $open->refresh();
        $this->assertSame('draft', $open->rubric_status);
        $this->assertSame([['อธิบายความหมายของเศษส่วนที่เท่ากันได้ถูกต้อง', true, 'ai'], ['ยกตัวอย่างได้ 1 คู่', false, 'ai']],
            $open->rubricCriteria()->orderBy('position')->get()->map(fn ($c) => [$c->description, (bool) $c->is_core, $c->source])->all());
        $this->assertSame(Assignment::STATUS_DRAFT, $homework->refresh()->status);

        // show_work: the reference steps.
        $steps = $this->card("[fake:rubric:{$homework->id}:2] ขั้นตอนข้อ 2");
        $this->assertSame([], $steps['plan']['criteria']);
        $this->apply($steps['id'])->assertOk();
        $this->assertSame(['ตั้งโจทย์ 24 ÷ 2', 'ได้ 12'], Question::query()->where('assignment_id', $homework->id)->where('position', 2)->firstOrFail()->answer_key['reference_steps']);
    }

    public function test_a_rubric_card_that_cannot_be_used_says_why(): void
    {
        $homework = $this->homework();
        $mcq = $this->card("[fake:rubric:{$homework->id}:3] rubric ข้อ 3");
        $this->assertSame('ข้อ 3 เป็นข้อปรนัย ไม่ต้องใช้ rubric', $mcq['plan']['problem']);
        $this->apply($mcq['id'])->assertStatus(422)->assertJsonPath('code', 'plan_invalid');

        $points = $this->card("[fake:rubric:{$homework->id}:4] rubric ข้อ 4");
        $this->assertSame('เกณฑ์ต้องมีเกณฑ์หลัก 1 ข้อ และคะแนนรวมเท่ากับคะแนนเต็ม 5 คะแนน', $points['plan']['problem']);

        $missing = $this->card("[fake:rubric:{$homework->id}:9] rubric ข้อ 9");
        $this->assertNull($missing['plan']['question']);
        $this->assertArrayHasKey('problem', $missing['plan']);

        // The question changed after the card: ask again.
        $card = $this->card("[fake:rubric:{$homework->id}:1] rubric ข้อ 1");
        Question::query()->where('assignment_id', $homework->id)->where('position', 1)->update(['max_points' => 4]);
        $this->apply($card['id'])->assertStatus(409)->assertJsonPath('code', 'plan_stale');
    }

    public function test_an_indicator_card_creates_adds_to_mine_and_suggests_for_questions(): void
    {
        $subject = $this->course->subject_id;
        $standard = Skill::create(['subject_id' => $subject, 'code' => 'FAKE-STD', 'name' => 'มาตรฐานการอ่าน', 'level' => 'standard', 'source' => 'curriculum']);
        $indicator = Skill::create(['subject_id' => $subject, 'parent_id' => $standard->id, 'code' => 'FAKE-1', 'name' => 'บวกเศษส่วน', 'level' => 'indicator', 'grade_level' => 5, 'source' => 'curriculum']);
        $this->course->indicators()->attach($indicator->id);
        $examId = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบเศษส่วน',
            'kind' => 'exam', 'due_at' => '2026-10-30T02:00:00Z', 'duration_minutes' => 60,
        ])->assertCreated()->json('data.id');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$examId}/sections", ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1])->assertCreated();

        $card = $this->card("[fake:indicators:{$examId}] ตัวชี้วัดของข้อสอบนี้");
        $system = $this->gemini->requests[0]->systemInstruction;
        $this->assertStringContainsString('Indicators of your subjects', $system);
        $this->assertStringContainsString('- FAKE-STD | standard | มาตรฐานการอ่าน', $system);
        $this->assertStringContainsString('- FAKE-1 | indicator | บวกเศษส่วน', $system);
        $this->assertSame('indicators', $card['plan']['kind']);
        $this->assertSame([[], [], ['not_found'], ['code_taken']], array_column($card['plan']['items'], 'flags'));
        $this->assertSame(['id' => $standard->id, 'code' => 'FAKE-STD', 'name' => 'มาตรฐานการอ่าน'], $card['plan']['items'][0]['parent']);
        $this->assertSame($indicator->id, $card['plan']['items'][1]['skill_id']);

        $result = $this->apply($card['id'], [0, 1, 2, 3])->assertOk()->json('data.message.plan.result');
        $this->assertSame([1, 1, 1], [$result['created'], $result['added_mine'], $result['mapped']]);
        $this->assertSame([2, 3], array_column($result['skipped'], 'index'));
        $this->assertSame($examId, $result['assignment_id']);
        $made = Skill::query()->where('source', 'teacher')->sole();
        $this->assertSame(['FAKE-STD/ค1', 'indicator', $this->teacher->school_id, 5], [$made->code, $made->level, $made->school_id, $made->grade_level]);
        $this->assertSame([$indicator->id], DB::table('teacher_indicators')->where('user_id', $this->teacher->id)->pluck('skill_id')->map(fn ($id) => (int) $id)->all());
        $question = Question::query()->where('assignment_id', $examId)->sole();
        $this->assertSame([[$question->id, $indicator->id]], IndicatorSuggestion::query()->get()->map(fn ($s) => [$s->question_id, $s->skill_id])->all());

        // Without an assignment there is nothing to map; FAKE-1 is now "mine".
        $again = $this->card('[fake:indicators] แนะนำตัวชี้วัด');
        $this->assertNull($again['plan']['target']);
        $this->assertSame([], $again['plan']['items'][1]['question_positions']);
        $this->assertSame(['already_mine'], $again['plan']['items'][1]['flags']);
        $this->apply($again['id'], [])->assertStatus(422);
        $this->apply($again['id'], [2])->assertStatus(422)->assertJsonPath('code', 'plan_nothing_created');
    }
}
