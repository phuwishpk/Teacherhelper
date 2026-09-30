<?php

namespace Tests\Feature\Analysis;

use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\StudentAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §20.5, §20.7, §20.9, §20.10 (analysis): code-computed strengths
 * and areas, "วิเคราะห์ตอนนี้" with the fake Gemini client, no student data
 * in the payload, the forbidden word, edit / approve / auto-share, and the
 * student seeing only their own approved text.
 */
class StudentAnalysisTest extends TestCase
{
    use AnalysisWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeAnalysisWorld();
    }

    private function showUrl(string $student): string
    {
        return "/api/v1/students/{$this->students[$student]->id}/analysis?classroom_id={$this->room->id}";
    }

    private function run_(string $student)
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/students/{$this->students[$student]->id}/analysis/run", ['classroom_id' => $this->room->id]);
    }

    public function test_the_teacher_sees_code_computed_strengths_and_areas_at_once(): void
    {
        $data = $this->asUser($this->teacher)->getJson($this->showUrl('A'))->assertOk()->json('data');

        $this->assertSame('computed', $data['status']);
        // Weakest first below 0.75; the three strongest at or above 0.75, strongest first.
        $this->assertSame(['ค 1.1 ป.5/4', 'ค 1.1 ป.5/3'], array_column(array_column($data['areas'], 'skill'), 'code'));
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/2', 'ค 1.1 ป.5/5'], array_column(array_column($data['strengths'], 'skill'), 'code'));
        $this->assertSame([false, true, false], array_column($data['strengths'], 'too_little'), 'n_obs < 2 = ข้อมูลยังน้อย');
        $this->assertSame([0.3, 0.6], array_column($data['areas'], 'value'));
        $this->assertNull($data['teacher_text']);
        $this->assertNull($data['student_text']);
        $this->assertFalse($data['stale']);
        $this->assertSame(0, AiCall::query()->count(), 'no Gemini call before the teacher asks or the night comes');

        // Nothing assessed in this classroom yet: no analysis.
        $this->asUser($this->teacher)->getJson($this->showUrl('C'))->assertOk()->assertJsonPath('data', null);
        $this->assertSame(1, StudentAnalysis::query()->count());
    }

    public function test_analyse_now_writes_both_texts_with_one_call_and_sends_no_student_data(): void
    {
        $data = $this->run_('A')->assertOk()->json('data');

        $this->assertSame(['drafted', 'now'], [$data['status'], $data['generated_via']]);
        $this->assertStringContainsString('จุดเด่น', $data['teacher_text']);
        $this->assertStringContainsString('ทำได้ดี', $data['student_text']);
        $this->assertSame(['ค 1.1 ป.5/4'], array_column(array_column($data['next_steps'], 'skill'), 'code'), 'an area with approved practice');
        $this->assertNull($data['shared_student_text'], 'students see it only after approval');
        $this->assertTrue($data['awaiting_approval']);

        $row = StudentAnalysis::query()->sole();
        $this->assertSame($row->computed_input_hash, $row->generated_input_hash);

        $calls = AiCall::query()->get();
        $this->assertCount(1, $calls);
        $this->assertSame(['student_analysis', 'analysis_now', 'ok', false], [$calls[0]->purpose, $calls[0]->feature, $calls[0]->status, (bool) $calls[0]->batch]);

        // §20.9: the prompt has indicator data and numbers only.
        $request = $this->fake()->requests[0];
        $payload = json_encode([$request->systemInstruction, $request->userText, $request->hints], JSON_UNESCAPED_UNICODE);
        $student = $this->students['A'];
        $this->assertStringNotContainsString('สมชาย', $payload);
        $this->assertStringNotContainsString($student->name, $payload);
        $this->assertStringNotContainsString('student_id', $payload);
        $this->assertStringNotContainsString('"'.$student->id.'"', $payload);
        $this->assertStringNotContainsString('เลขที่', $request->userText);
        $this->assertStringContainsString('ค 1.1 ป.5/4', $request->userText);
        $this->assertStringContainsString('"mastery":30', $request->userText);
        $this->assertSame(['low', 1536], [$request->thinkingLevel, $request->maxOutputTokens]);
    }

    public function test_the_forbidden_word_in_the_student_text_is_retried_once(): void
    {
        $this->s['i1']->forceFill(['name' => 'บวกเลข [fake:weak-word-once]'])->save();
        $this->run_('A')->assertOk()->assertJsonPath('data.status', 'drafted');
        $this->assertStringNotContainsString('อ่อน', (string) StudentAnalysis::query()->sole()->student_text);
        $this->assertSame(['invalid_output', 'ok'], AiCall::query()->orderBy('id')->pluck('status')->all());

        // Every time: the call fails and nothing is written.
        $this->s['i4']->forceFill(['name' => 'เศษส่วน [fake:weak-word]'])->save();
        $this->run_('B')->assertStatus(502)->assertJsonPath('code', 'ai_unavailable');
        $this->assertNull(StudentAnalysis::query()->where('student_id', $this->students['B']->id)->value('student_text'));
    }

    public function test_analyse_now_needs_data_and_a_key(): void
    {
        $this->run_('C')->assertStatus(422)->assertJsonPath('code', 'analysis_no_data');

        config(['services.gemini.api_key' => '']);
        $this->run_('A')->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');
        $this->assertSame(0, AiCall::query()->count());

        $this->asUser($this->teacher)->postJson("/api/v1/students/{$this->students['A']->id}/analysis/run", [])
            ->assertStatus(422)->assertJsonValidationErrors('classroom_id');
        $other = $this->makeClassroom($this->teacher);
        $this->asUser($this->teacher)->postJson("/api/v1/students/{$this->students['A']->id}/analysis/run", ['classroom_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('classroom_id');
    }

    public function test_the_teacher_edits_and_approves_and_the_student_sees_only_the_approved_text(): void
    {
        $id = $this->run_('A')->json('data.id');
        $studentA = $this->students['A'];

        $this->asUser($studentA)->getJson('/api/v1/student/analysis')->assertOk()->assertJsonCount(0, 'data');

        $this->asUser($this->teacher)->patchJson("/api/v1/analyses/{$id}", [])->assertStatus(422);
        $this->asUser($this->teacher)->patchJson("/api/v1/analyses/{$id}", ['student_text' => ''])->assertStatus(422)->assertJsonValidationErrors('student_text');
        $this->asUser($this->teacher)->patchJson("/api/v1/analyses/{$id}", ['student_text' => 'เก่งมาก ฝึกเศษส่วนต่ออีกนิดนะ', 'teacher_text' => 'ครูเขียนเอง'])
            ->assertOk()->assertJsonPath('data.student_text', 'เก่งมาก ฝึกเศษส่วนต่ออีกนิดนะ')->assertJsonPath('data.shared_student_text', null);
        $this->asUser($studentA)->getJson('/api/v1/student/analysis')->assertOk()->assertJsonCount(0, 'data');

        $this->asUser($this->teacher)->postJson("/api/v1/analyses/{$id}/approve")->assertOk()
            ->assertJsonPath('data.shared_student_text', 'เก่งมาก ฝึกเศษส่วนต่ออีกนิดนะ')
            ->assertJsonPath('data.approved_by', $this->teacher->id)
            ->assertJsonPath('data.awaiting_approval', false);

        $mine = $this->asUser($studentA)->getJson('/api/v1/student/analysis')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame(['id' => $this->room->id, 'name' => 'ป.5/1'], $mine[0]['classroom']);
        $this->assertSame('เก่งมาก ฝึกเศษส่วนต่ออีกนิดนะ', $mine[0]['text']);
        $this->assertSame(['ค 1.1 ป.5/4'], array_column(array_column($mine[0]['next_steps'], 'skill'), 'code'));
        $this->assertArrayNotHasKey('teacher_text', $mine[0]);
        $this->assertStringNotContainsString('ครูเขียนเอง', json_encode($mine, JSON_UNESCAPED_UNICODE));

        // A new draft keeps the approved text on show until it is approved in turn.
        $this->run_('A')->assertOk()->assertJsonPath('data.awaiting_approval', true);
        $this->assertSame('เก่งมาก ฝึกเศษส่วนต่ออีกนิดนะ', $this->asUser($studentA)->getJson('/api/v1/student/analysis')->json('data.0.text'));

        // A classmate never sees it.
        $this->asUser($this->students['B'])->getJson('/api/v1/student/analysis')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_approving_without_a_student_text_is_refused(): void
    {
        $id = $this->asUser($this->teacher)->getJson($this->showUrl('A'))->json('data.id');

        $this->asUser($this->teacher)->postJson("/api/v1/analyses/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'analysis_not_ready');
    }

    public function test_auto_share_sends_new_texts_to_the_student_without_approval(): void
    {
        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$this->room->id}", ['auto_share_analysis' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('auto_share_analysis');
        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$this->room->id}", ['auto_share_analysis' => true])
            ->assertOk()->assertJsonPath('data.auto_share_analysis', true);

        $data = $this->run_('A')->assertOk()->json('data');
        $this->assertSame($data['student_text'], $data['shared_student_text']);
        $this->assertNull($data['approved_by'], 'shared by the classroom setting');
        $this->assertSame($data['student_text'], $this->asUser($this->students['A'])->getJson('/api/v1/student/analysis')->json('data.0.text'));
    }

    public function test_the_classroom_list_shows_every_student_in_number_order(): void
    {
        $this->run_('A')->assertOk();
        $this->asUser($this->teacher)->getJson($this->showUrl('B'))->assertOk();

        $data = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->room->id}/analyses")->assertOk()->json('data');

        $this->assertSame([$this->room->id, false], [$data['classroom_id'], $data['auto_share_analysis']]);
        $this->assertSame([17, 18, 19], array_column(array_column($data['students'], 'student'), 'student_number'));
        [$a, $b, $c] = array_column($data['students'], 'analysis');
        $this->assertSame(['drafted', true, true], [$a['status'], $a['has_text'], $a['awaiting_approval']]);
        $this->assertArrayNotHasKey('teacher_text', $a);
        $this->assertSame(['computed', false], [$b['status'], $b['has_text']]);
        $this->assertSame([true], array_column($b['areas'], 'too_little'));
        $this->assertNull($c);
    }

    public function test_other_teachers_cannot_reach_the_analysis(): void
    {
        $id = $this->run_('A')->json('data.id');
        $colleague = $this->makeTeacher($this->teacher->school);

        $this->asUser($colleague)->getJson($this->showUrl('A'))->assertNotFound();
        $this->asUser($colleague)->patchJson("/api/v1/analyses/{$id}", ['teacher_text' => 'x'])->assertNotFound();
        $this->asUser($colleague)->postJson("/api/v1/analyses/{$id}/approve")->assertNotFound();
        $this->asUser($colleague)->getJson("/api/v1/classrooms/{$this->room->id}/analyses")->assertNotFound();
    }

    public function test_a_classroom_without_a_course_uses_the_skills_of_its_questions(): void
    {
        $this->course->classrooms()->detach();
        $this->asUser($this->teacher)->getJson($this->showUrl('A'))->assertOk()->assertJsonPath('data', null);

        $assignment = Assignment::factory()->for_classroom($this->room)->create();
        Question::factory()->create(['assignment_id' => $assignment->id])->skills()->attach([$this->s['i1']->id, $this->s['i4']->id]);

        $data = $this->asUser($this->teacher)->getJson($this->showUrl('A'))->assertOk()->json('data');
        $this->assertSame(['ค 1.1 ป.5/1'], array_column(array_column($data['strengths'], 'skill'), 'code'));
        $this->assertSame(['ค 1.1 ป.5/4'], array_column(array_column($data['areas'], 'skill'), 'code'));
    }
}
