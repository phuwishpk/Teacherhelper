<?php

namespace Tests\Feature\Api;

use App\Domain\Courses\IndicatorSuggestions;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\TeacherGuidance;
use App\Jobs\SuggestIndicatorsJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\IndicatorSuggestion;
use App\Models\LessonPlan;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Review\ReviewFixtures;
use Tests\TestCase;

/**
 * DESIGN §20.3, §20.7, §20.10: Gemini suggests indicators per question
 * from the linked lesson plan only (codes outside the plan are dropped),
 * the teacher confirms or edits them into question_skill, and questions
 * without an indicator are a warning, never a block. Gemini is the
 * FakeGeminiClient.
 */
class IndicatorSuggestionTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private FakeGeminiClient $gemini;

    private Subject $math;

    private Skill $p51;

    private Skill $p52;

    private Skill $outside;

    private Classroom $room;

    private Course $course;

    private LessonPlan $plan;

    private Assignment $work;

    /** @var list<Question> */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);

        $this->teacher = $this->makeTeacher();
        $this->math = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $standard = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1', 'level' => Skill::LEVEL_STANDARD, 'grade_level' => null]);
        $this->p51 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/1', 'name' => 'บวกลบเศษส่วน', 'grade_level' => 5]);
        $this->p52 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/2', 'name' => 'คูณหารเศษส่วน', 'grade_level' => 5]);
        $this->outside = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $standard->id, 'code' => 'ค 1.1 ป.5/3', 'name' => 'ทศนิยม', 'grade_level' => 5]);

        $this->room = $this->makeClassroom($this->teacher, ['grade_level' => 5]);
        $this->course = $this->makeCourse($this->teacher, [$this->room], ['subject_id' => $this->math->id]);
        $this->plan = LessonPlan::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'การบวกเศษส่วน', 'objectives' => 'บวกเศษส่วนได้']);
        $this->plan->indicators()->sync([$this->p51->id, $this->p52->id]);

        $this->work = Assignment::factory()->for_classroom($this->room)->create([
            'subject_id' => $this->math->id,
            'course_id' => $this->course->id,
            'lesson_plan_id' => $this->plan->id,
        ]);
        foreach (['1/2 + 1/4 เท่ากับเท่าไร', '2/3 × 3/4 เท่ากับเท่าไร (ค 1.1 ป.5/2)', 'เขียนชื่อของตัวเอง [fake:no-indicator]', 'ลบเศษส่วน [fake:outside-plan]'] as $text) {
            $this->questions[] = Question::factory()->create(['assignment_id' => $this->work->id, 'prompt_text' => $text]);
        }
    }

    private function url(string $path = 'indicator-suggestions'): string
    {
        return "/api/v1/assignments/{$this->work->id}/{$path}";
    }

    public function test_gemini_suggests_only_indicators_of_the_plan_and_the_teacher_confirms(): void
    {
        [$q1, $q2, $q3, $q4] = $this->questions;

        // Before anything: every question is unmapped, a warning only.
        $this->asUser($this->teacher)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.lesson_plan.id', $this->plan->id)
            ->assertJsonPath('data.plan_indicators.0.code', 'ค 1.1 ป.5/1')
            ->assertJsonPath('data.plan_indicators.1.code', 'ค 1.1 ป.5/2')
            ->assertJsonPath('data.unmapped_question_count', 4)
            ->assertJsonPath('data.unmapped_warning', 'มี 4 ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ');

        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202)
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.suggested_question_count', 3)
            ->assertJsonPath('data.dropped_code_count', 1);

        $data = $this->asUser($this->teacher)->getJson($this->url())->assertOk()->json('data');
        $byId = array_column($data['questions'], null, 'question_id');
        $codes = fn (Question $q) => array_map(fn (array $s) => $s['skill']['code'], $byId[$q->id]['suggestions']);
        $this->assertSame(['ค 1.1 ป.5/1'], $codes($q1), 'no code in the text: the fake picks the first of the plan');
        $this->assertSame(['ค 1.1 ป.5/2'], $codes($q2));
        $this->assertSame([], $codes($q3), 'none fits');
        $this->assertSame(['ค 1.1 ป.5/1'], $codes($q4), 'the code outside the plan is dropped (§20.10)');
        $this->assertSame('ข้อนี้วัด ค 1.1 ป.5/2', $byId[$q2->id]['suggestions'][0]['reason_th']);
        $this->assertSame(0, IndicatorSuggestion::query()->where('skill_id', '!=', $this->p51->id)->where('skill_id', '!=', $this->p52->id)->count());
        // Suggestions are not confirmed yet: still four unmapped questions.
        $this->assertSame(4, $data['unmapped_question_count']);
        $this->assertSame(0, $q1->skills()->count());

        // One text-only call, thinking low, 1,024 tokens (§21.6), with the plan's indicators and no student data.
        $this->assertCount(1, $this->gemini->requests);
        $request = $this->gemini->requests[0];
        $this->assertSame(['indicator_suggest', 'indicator_suggest.general.v2', 'low', 1024, []], [$request->purpose, 'indicator_suggest.general.'.$request->promptVersion, $request->thinkingLevel, $request->maxOutputTokens, $request->images]);
        $this->assertStringContainsString('- ค 1.1 ป.5/1: บวกลบเศษส่วน', $request->userText);
        $this->assertStringNotContainsString('ค 1.1 ป.5/3', $request->userText, 'only the plan\'s indicators are offered');
        $call = AiCall::query()->sole();
        $this->assertSame(['indicator_suggest', 'indicator_suggest', $this->work->id, 4], [$call->purpose, $call->feature, $call->assignment_id, $call->question_count]);

        // The teacher confirms q1 and q2, edits q4 to an indicator outside the plan, leaves q3 empty.
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [
            ['question_id' => $q1->id, 'skill_ids' => [$this->p51->id]],
            ['question_id' => $q2->id, 'skill_ids' => [$this->p52->id]],
            ['question_id' => $q4->id, 'skill_ids' => [$this->outside->id, $this->p51->id]],
            ['question_id' => $q3->id, 'skill_ids' => []],
        ]])->assertOk()
            ->assertJsonPath('data.changed_question_count', 3)
            ->assertJsonPath('data.unmapped_question_count', 1)
            ->assertJsonPath('data.unmapped_warning', 'มี 1 ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ');
        $this->assertEqualsCanonicalizing([$this->outside->id, $this->p51->id], $q4->skills()->pluck('skills.id')->all());

        // The assignment detail carries the same count.
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->work->id}")->assertOk()->assertJsonPath('data.unmapped_question_count', 1);

        // Asking again replaces the rows; the confirmed mapping stays.
        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202)->assertJsonPath('data.status', 'done');
        $this->assertSame(3, IndicatorSuggestion::query()->count());
        $this->assertSame(1, $q1->skills()->count());
    }

    public function test_suggestions_need_a_plan_with_indicators_questions_and_a_key(): void
    {
        $unlinked = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->math->id, 'course_id' => $this->course->id]);
        Question::factory()->create(['assignment_id' => $unlinked->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$unlinked->id}/indicator-suggestions")
            ->assertStatus(422)->assertJsonPath('code', 'lesson_plan_required')->assertJsonValidationErrors(['lesson_plan_id']);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$unlinked->id}/indicator-suggestions")->assertOk()
            ->assertJsonPath('data.lesson_plan', null)->assertJsonPath('data.plan_indicators', [])->assertJsonPath('data.unmapped_question_count', 1);

        $emptyPlan = LessonPlan::create(['course_id' => $this->course->id, 'position' => 2, 'title' => 'ว่าง']);
        $unlinked->forceFill(['lesson_plan_id' => $emptyPlan->id])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$unlinked->id}/indicator-suggestions")
            ->assertStatus(422)->assertJsonPath('code', 'lesson_plan_no_indicators');

        $noQuestions = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->math->id, 'course_id' => $this->course->id, 'lesson_plan_id' => $this->plan->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$noQuestions->id}/indicator-suggestions")
            ->assertStatus(422)->assertJsonPath('code', 'no_questions');

        config(['services.gemini.api_key' => '']);
        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');
        $this->assertCount(0, $this->gemini->requests);

        // Another teacher of the school: 404.
        $this->asUser($this->makeTeacher($this->teacher->school))->postJson($this->url())->assertNotFound();
    }

    public function test_a_request_already_queued_is_not_queued_twice_and_a_failure_is_reported(): void
    {
        Queue::fake();
        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SuggestIndicatorsJob::class, 1);

        // Lost for longer than 15 minutes: queued again.
        $this->travel(16)->minutes();
        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202);
        Queue::assertPushed(SuggestIndicatorsJob::class, 2);

        // Transport errors are retried by the queue, then reported.
        $this->questions[0]->forceFill(['prompt_text' => 'ข้อ [fake:error]'])->save();
        $suggestions = app(IndicatorSuggestions::class);
        try {
            $suggestions->process($this->work->id, lastAttempt: false);
            $this->fail('a transient error is rethrown for the retry');
        } catch (GeminiException $e) {
            $this->assertSame(GeminiException::ERROR, $e->status);
        }
        $suggestions->process($this->work->id, lastAttempt: true);
        $this->asUser($this->teacher)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.code', 'ai_failed');

        // Invalid output twice (the gateway's one retry): failed at once, nothing written.
        $this->questions[0]->forceFill(['prompt_text' => 'ข้อ [fake:max-tokens]'])->save();
        $suggestions->process($this->work->id, lastAttempt: false);
        $this->assertSame('failed', $suggestions->state($this->work->id)['status']);
        $this->assertSame(0, IndicatorSuggestion::query()->count());

        // A rejected key.
        $this->questions[0]->forceFill(['prompt_text' => 'ข้อหนึ่ง'])->save();
        config(['services.gemini.api_key' => 'rejected-key']);
        $suggestions->process($this->work->id, lastAttempt: false);
        $this->assertSame('ai_key_invalid', $suggestions->state($this->work->id)['error']['code']);

        // The job gave up after its last try.
        $suggestions->request($this->work->refresh()->load('classroom'));
        (new SuggestIndicatorsJob($this->work->id))->failed(null);
        $this->assertSame('failed', $suggestions->state($this->work->id)['status']);
    }

    public function test_the_teachers_guidance_reaches_every_call_is_logged_and_shown(): void
    {
        Queue::fake();
        // DESIGN §21.12: guidance is optional, trimmed, and kept with the round.
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => "  เน้นตัวชี้วัดเรื่องการบวก\r\n  "])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.guidance', 'เน้นตัวชี้วัดเรื่องการบวก');
        // Asked again while queued: nothing queued, the running round (and its guidance) answers.
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => 'อย่างอื่น'])->assertStatus(202)
            ->assertJsonPath('data.guidance', 'เน้นตัวชี้วัดเรื่องการบวก');
        Queue::assertPushed(SuggestIndicatorsJob::class, 1);
        $job = Queue::pushed(SuggestIndicatorsJob::class)->first();
        $this->assertSame(['เน้นตัวชี้วัดเรื่องการบวก', $this->teacher->id], [$job->guidance, $job->guidanceBy]);

        $this->app->call([$job, 'handle']);
        $this->assertCount(1, $this->gemini->requests);
        $this->assertStringContainsString("TEACHER GUIDANCE:\n".TeacherGuidance::LABEL."\n<<<\nเน้นตัวชี้วัดเรื่องการบวก\n>>>", $this->gemini->requests[0]->userText);
        $call = AiCall::query()->sole();
        $this->assertSame(['เน้นตัวชี้วัดเรื่องการบวก', $this->teacher->id], [$call->teacher_guidance, $call->guidance_by]);
        $this->asUser($this->teacher)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.guidance', 'เน้นตัวชี้วัดเรื่องการบวก');

        // After a finished round other guidance starts a new one; none at all is "(ไม่มี)".
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => 'รอบใหม่'])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.guidance', 'รอบใหม่');
        Queue::assertPushed(SuggestIndicatorsJob::class, 2);
        app(IndicatorSuggestions::class)->process($this->work->id, lastAttempt: true);
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => '   '])->assertStatus(202)->assertJsonPath('data.guidance', null);
        app(IndicatorSuggestions::class)->process($this->work->id, lastAttempt: true);
        $this->assertStringContainsString("TEACHER GUIDANCE:\n(ไม่มี)", $this->gemini->requests[2]->userText);
        $this->assertSame([null, null], [AiCall::query()->orderByDesc('id')->first()->teacher_guidance, AiCall::query()->orderByDesc('id')->first()->guidance_by]);

        // Too long, or not text: 422 before anything is queued.
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => str_repeat('ก', 501)])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.guidance.0', 'คำแนะนำถึง AI ยาวได้ไม่เกิน 500 ตัวอักษร');
        $this->asUser($this->teacher)->postJson($this->url(), ['guidance' => ['x']])->assertStatus(422)->assertJsonPath('errors.guidance.0', 'คำแนะนำถึง AI ต้องเป็นข้อความ');
        Queue::assertPushed(SuggestIndicatorsJob::class, 3);
    }

    public function test_many_questions_are_split_into_calls_of_ten(): void
    {
        foreach (range(5, 23) as $n) {
            Question::factory()->create(['assignment_id' => $this->work->id, 'prompt_text' => "ข้อ {$n}"]);
        }

        $this->asUser($this->teacher)->postJson($this->url())->assertStatus(202)->assertJsonPath('data.status', 'done');

        $this->assertCount(3, $this->gemini->requests);
        $this->assertSame([10, 10, 3], AiCall::query()->orderBy('id')->pluck('question_count')->all());
        $this->assertSame(22, IndicatorSuggestion::query()->count(), 'every question but the one where none fits');
    }

    public function test_the_mapping_is_validated_and_follows_the_subject_and_school(): void
    {
        [$q1] = $this->questions;
        $standard = Skill::query()->where('code', 'ค 1.1')->firstOrFail();
        $otherSubject = Skill::factory()->create(['subject_id' => Subject::factory()->create(['code' => 'ว'])->id]);
        $otherSchool = Skill::factory()->create(['subject_id' => $this->math->id, 'school_id' => $this->makeSchool()->id, 'parent_id' => $this->p51->id, 'level' => Skill::LEVEL_SUB_INDICATOR]);
        $foreign = Question::factory()->create();

        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), [])->assertStatus(422)->assertJsonValidationErrors(['questions']);
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [
            ['question_id' => $foreign->id, 'skill_ids' => []],
            ['question_id' => $q1->id, 'skill_ids' => [$standard->id, $otherSubject->id, $otherSchool->id, $this->p51->id]],
            ['question_id' => $q1->id],
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'questions.0.question_id', 'questions.1.skill_ids.0', 'questions.1.skill_ids.1', 'questions.1.skill_ids.2', 'questions.2.question_id', 'questions.2.skill_ids',
        ])->assertJsonMissingValidationErrors(['questions.1.skill_ids.3']);
        $this->assertSame(0, $q1->skills()->count());
        // Twice in one question is an error; the same indicator on two questions is fine.
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [
            ['question_id' => $q1->id, 'skill_ids' => [$this->p51->id, $this->p51->id]],
        ]])->assertStatus(422)->assertJsonValidationErrors(['questions.0.skill_ids']);
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [
            ['question_id' => $q1->id, 'skill_ids' => [$this->p51->id]],
            ['question_id' => $this->questions[1]->id, 'skill_ids' => [$this->p51->id]],
        ]])->assertOk()->assertJsonPath('data.changed_question_count', 2);
        $q1->skills()->detach();

        // Allowed on a closed assignment: the mapping feeds the charts, not grading.
        $this->work->forceFill(['status' => Assignment::STATUS_CLOSED])->save();
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [['question_id' => $q1->id, 'skill_ids' => [$this->p51->id]]]])
            ->assertOk()->assertJsonPath('data.changed_question_count', 1);
        // The same set again changes nothing.
        $this->asUser($this->teacher)->putJson($this->url('indicator-mapping'), ['questions' => [['question_id' => $q1->id, 'skill_ids' => [$this->p51->id]]]])
            ->assertOk()->assertJsonPath('data.changed_question_count', 0);
    }

    public function test_mapping_after_publishing_records_the_published_answers_again(): void
    {
        $this->makeReviewWorld(1);
        $student = $this->students[0];
        $this->answerSheet($student);
        $submission = $this->submission($student);
        $this->reviewAll($submission);
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();
        $this->assertSame(0, SkillObservation::query()->count(), 'no question had an indicator');

        $indicator = Skill::factory()->create(['subject_id' => $this->assignment->subject_id, 'code' => 'ค 2.1 ป.5/1', 'grade_level' => 5]);
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$this->assignment->id}/indicator-mapping", ['questions' => [
            ['question_id' => $this->q['q1']->id, 'skill_ids' => [$indicator->id]],
        ]])->assertOk()->assertJsonPath('data.unmapped_question_count', 3);

        $this->assertSame(1, SkillObservation::query()->where('skill_id', $indicator->id)->count());
        $this->assertSame(0.5, (float) Mastery::query()->where('student_id', $student->id)->where('skill_id', $indicator->id)->value('value'));
    }

    public function test_approving_the_key_suggests_for_a_plan_linked_assignment_with_unmapped_questions(): void
    {
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->work->id}/answer-key/approve")->assertOk();

        $this->assertSame('done', app(IndicatorSuggestions::class)->state($this->work->id)['status']);
        $this->assertSame(3, IndicatorSuggestion::query()->count());

        // Approving again does not ask Gemini again: suggestions exist.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->work->id}/answer-key/approve")->assertOk();
        $this->assertCount(1, $this->gemini->requests);

        // Not linked to a plan: nothing.
        $other = Assignment::factory()->for_classroom($this->room)->create(['subject_id' => $this->math->id, 'course_id' => $this->course->id]);
        Question::factory()->create(['assignment_id' => $other->id]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$other->id}/answer-key/approve")->assertOk();
        $this->assertCount(1, $this->gemini->requests);
        $this->assertNull(app(IndicatorSuggestions::class)->state($other->id)['status']);
    }

    public function test_approving_again_after_a_round_that_found_nothing_does_not_pay_again(): void
    {
        Question::query()->where('assignment_id', $this->work->id)->update(['prompt_text' => 'ข้อที่ไม่เข้าตัวชี้วัดไหนเลย [fake:no-indicator]']);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->work->id}/answer-key/approve")->assertOk();
        $this->assertSame('done', app(IndicatorSuggestions::class)->state($this->work->id)['status']);
        $this->assertSame(0, IndicatorSuggestion::query()->count());
        $calls = count($this->gemini->requests);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->work->id}/answer-key/approve")->assertOk();
        $this->assertCount($calls, $this->gemini->requests);
    }
}
