<?php

namespace Tests\Feature\Review;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\TeacherGuidance;
use App\Domain\Grading\FeedbackTemplates;
use App\Models\AiCall;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET / PATCH /responses/{id} and POST .../regenerate-explanation (DESIGN
 * §9.5, §13): what the teacher sees and the override rules.
 */
class ResponseReviewTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(2);
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
    }

    private function url(Response $response, string $suffix = ''): string
    {
        return "/api/v1/responses/{$response->id}{$suffix}";
    }

    public function test_detail_has_the_extraction_the_trace_and_why(): void
    {
        $response = $this->gradedShort($this->students[0], 'q1', ['answer_text' => '25', 'key_match' => 'different', 'error_types' => ['calculation']], cnnText: '20');
        $this->q['q3']->rubricCriteria()->create(['position' => 1, 'description' => 'ตั้งสมการถูก', 'points' => 5, 'is_core' => true, 'source' => 'teacher']);

        $res = $this->asUser($this->teacher)->getJson($this->url($response))->assertOk();

        $res->assertJsonPath('data.id', $response->id)
            ->assertJsonPath('data.student.student_number', 1)
            ->assertJsonPath('data.question.type', 'short')
            ->assertJsonPath('data.question.answer_key.accepted.0', '20')
            ->assertJsonPath('data.extraction.answer_text', '25')
            ->assertJsonPath('data.fuzzy_trace.system', 'short')
            ->assertJsonPath('data.ai_score', 0)
            ->assertJsonPath('data.ai_understanding', 'not_yet')
            ->assertJsonPath('data.explanation', 'ลองตรวจการคำนวณอีกครั้งนะ')
            ->assertJsonPath('data.crop_url', "/api/v1/responses/{$response->id}/crop")
            ->assertJsonPath('data.final_crop_url', null)
            ->assertJsonPath('data.has_crop', true)
            ->assertJsonPath('data.appeal', null)
            ->assertJsonPath('data.cnn_text', '20');

        $why = $res->json('data.why');
        $this->assertContains('เทียบกับเฉลย: ไม่ตรงกับเฉลย (M = 0)', $why);
        $this->assertContains('กฎ S1 (น้ำหนัก 1): คำตอบไม่ตรงกับเฉลย', $why);
        $this->assertContains('CNN อ่านได้ "20" Gemini อ่านได้ "25"', $why);
        $this->assertTrue(collect($why)->contains(fn (string $l) => str_starts_with($l, 'ลำดับการตรวจ p = 1 (ต้องตรวจ): ผู้อ่านสองฝ่ายอ่านไม่ตรงกัน')), implode("\n", $why));
    }

    public function test_detail_of_a_manual_answer_explains_why_it_is_manual(): void
    {
        $response = $this->manualAnswer($this->students[0], 'q2');

        $this->asUser($this->teacher)->getJson($this->url($response))
            ->assertOk()
            ->assertJsonPath('data.manual_reason', 'ai_key_missing')
            ->assertJsonPath('data.why.0', 'ครูต้องตรวจข้อนี้เอง: ยังไม่ได้ใส่ Gemini API key จึงยังไม่ได้ให้ AI ตรวจ');
    }

    public function test_confirming_the_ai_score_needs_no_reason_and_logs_nothing(): void
    {
        $response = $this->answer($this->students[0], 'q1');

        $this->asUser($this->teacher)->patchJson($this->url($response), [
            'final_score' => 1,
            'final_understanding' => 'partial',
        ])->assertOk()
            ->assertJsonPath('data.final_score', 1)
            ->assertJsonPath('data.final_error_types', ['calculation'])
            ->assertJsonPath('data.reviewed_by', $this->teacher->id);

        $response->refresh();
        $this->assertNotNull($response->reviewed_at);
        $this->assertSame(0, ScoreEvent::query()->count());
        $this->assertSame('reviewed', Submission::query()->sole()->status, 'its only answer is reviewed');
    }

    public function test_a_different_score_needs_a_reason_and_is_logged_as_override(): void
    {
        $response = $this->answer($this->students[0], 'q3'); // AI 2.5 of 5
        $this->answer($this->students[0], 'q1');

        $this->asUser($this->teacher)->patchJson($this->url($response), [
            'final_score' => 4,
            'final_understanding' => 'good',
            'final_error_types' => [],
        ])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('reason');
        $this->assertNull($response->refresh()->reviewed_at);

        $this->asUser($this->teacher)->patchJson($this->url($response), [
            'final_score' => 4,
            'final_understanding' => 'good',
            'final_error_types' => [],
            'reason' => 'AI อ่านลายมือผิด: บรรทัดที่ 2 เขียน 15',
        ])->assertOk()
            ->assertJsonPath('data.final_score', 4)
            ->assertJsonPath('data.final_error_types', [])
            ->assertJsonPath('data.score_events.0.action', 'override')
            ->assertJsonPath('data.why.0', 'ครูปรับคะแนนจาก 2.5 เป็น 4');

        $event = ScoreEvent::query()->sole();
        $this->assertSame(['teacher', $this->teacher->id, 'override', 2.5, 4.0, 'partial', 'good', 'AI อ่านลายมือผิด: บรรทัดที่ 2 เขียน 15'], [
            $event->actor, $event->actor_user_id, $event->action, $event->old_score, $event->new_score, $event->old_understanding, $event->new_understanding, $event->reason,
        ]);
        $this->assertSame('needs_review', Submission::query()->sole()->status, 'q1 is still unreviewed');

        // Changing it again logs from the teacher's previous value.
        $this->asUser($this->teacher)->patchJson($this->url($response), ['final_score' => 2.5, 'final_understanding' => 'partial'])->assertOk();
        $this->assertSame([4.0, 2.5], [ScoreEvent::query()->latest('id')->first()->old_score, ScoreEvent::query()->latest('id')->first()->new_score]);
    }

    public function test_a_manual_answer_takes_the_teachers_score_without_a_reason(): void
    {
        $response = $this->manualAnswer($this->students[0], 'q2');

        $this->asUser($this->teacher)->patchJson($this->url($response), [
            'final_score' => 1.5,
            'final_understanding' => 'partial',
            'final_error_types' => ['spelling_grammar'],
        ])->assertOk();

        $event = ScoreEvent::query()->sole();
        $this->assertSame([null, 1.5, null, 'partial'], [$event->old_score, $event->new_score, $event->old_understanding, $event->new_understanding]);
        $this->assertSame(['spelling_grammar'], $response->refresh()->final_error_types);
        $this->assertSame(0, Response::query()->awaitingAiKey()->count(), 'graded by hand: no longer waits for a key');
    }

    public function test_scores_outside_the_question_or_off_step_are_rejected(): void
    {
        $response = $this->answer($this->students[0], 'q1'); // max 2

        foreach ([['final_score' => 2.5], ['final_score' => -1], ['final_score' => 1.3]] as $body) {
            $this->asUser($this->teacher)->patchJson($this->url($response), $body + ['final_understanding' => 'partial', 'reason' => 'x'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('final_score');
        }
        $this->asUser($this->teacher)->patchJson($this->url($response), ['final_score' => 1.75, 'final_understanding' => 'partial', 'reason' => 'ละเอียดขึ้น'])->assertOk();
        $this->asUser($this->teacher)->patchJson($this->url($response), ['final_score' => 1, 'final_understanding' => 'meh'])
            ->assertStatus(422)->assertJsonValidationErrors('final_understanding');
        $this->asUser($this->teacher)->patchJson($this->url($response), ['final_score' => 1, 'final_understanding' => 'good', 'final_error_types' => ['typo']])
            ->assertStatus(422)->assertJsonValidationErrors('final_error_types.0');
    }

    public function test_the_teachers_explanation_is_kept_as_edited(): void
    {
        $response = $this->answer($this->students[0], 'q1', ['fuzzy_trace' => ['system' => 'short', 'explanation_error' => 'error'], 'explanation' => null]);
        $this->asUser($this->teacher)->getJson($this->url($response))->assertJsonPath('data.explanation_error', 'error');

        $this->asUser($this->teacher)->patchJson($this->url($response), [
            'final_score' => 1,
            'final_understanding' => 'partial',
            'explanation' => '  ตั้งหลักทศนิยมให้ตรงกันก่อนบวกนะ  ',
        ])->assertOk()
            ->assertJsonPath('data.explanation', 'ตั้งหลักทศนิยมให้ตรงกันก่อนบวกนะ')
            ->assertJsonPath('data.explanation_edited', true)
            ->assertJsonPath('data.explanation_error', null);
        $this->assertArrayNotHasKey('explanation_error', $response->refresh()->fuzzy_trace);
    }

    public function test_published_or_ungraded_answers_cannot_be_changed(): void
    {
        $queued = $this->answer($this->students[0], 'q1', ['grading_state' => Response::STATE_QUEUED, 'ai_score' => null]);
        $this->asUser($this->teacher)->patchJson($this->url($queued), ['final_score' => 1, 'final_understanding' => 'partial'])
            ->assertStatus(409)->assertJsonPath('code', 'response_grading');

        $published = $this->answer($this->students[1], 'q1');
        $this->submission($this->students[1])->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()])->save();
        $this->asUser($this->teacher)->patchJson($this->url($published), ['final_score' => 1, 'final_understanding' => 'partial'])
            ->assertStatus(409)->assertJsonPath('code', 'submission_published');
        $this->asUser($this->teacher)->postJson($this->url($published, '/regenerate-explanation'))
            ->assertStatus(409)->assertJsonPath('code', 'submission_published');
    }

    public function test_only_the_classroom_teacher_reviews(): void
    {
        $response = $this->answer($this->students[0], 'q1');
        $body = ['final_score' => 1, 'final_understanding' => 'partial'];

        $this->asUser($this->makeTeacher($this->teacher->school))->getJson($this->url($response))->assertForbidden();
        $this->asUser($this->makeTeacher($this->teacher->school))->patchJson($this->url($response), $body)->assertForbidden();
        $this->asUser($this->makeTeacher())->patchJson($this->url($response), $body)->assertNotFound();
        $this->asUser($this->students[0])->getJson($this->url($response))->assertForbidden();
        $this->asUser($this->students[0])->patchJson($this->url($response), $body)->assertForbidden();
        $this->assertNull($response->refresh()->reviewed_at);
    }

    public function test_regenerate_writes_a_new_explanation_from_the_transcription(): void
    {
        $response = $this->gradedShort($this->students[0], 'q1', ['answer_text' => '25', 'key_match' => 'different', 'error_types' => ['calculation']]);
        $response->forceFill(['explanation' => 'ข้อความที่ครูแก้', 'explanation_edited' => true, 'final_error_types' => ['careless']])->save();

        $res = $this->asUser($this->teacher)->postJson($this->url($response, '/regenerate-explanation'))->assertOk();

        $text = $res->json('data.explanation');
        $this->assertIsString($text);
        $this->assertNotSame('ข้อความที่ครูแก้', $text);
        $res->assertJsonPath('data.explanation_edited', false);
        $this->assertSame(['explanation'], AiCall::query()->pluck('purpose')->all());
        $this->assertSame('server', AiCall::query()->sole()->key_source);
        $sent = end($this->gemini->requests);
        $this->assertStringContainsString('careless', $sent->userText, "the teacher's error types, not the AI's");
        $this->assertStringNotContainsString($this->students[0]->name, $sent->userText.$sent->systemInstruction);
    }

    public function test_regenerate_carries_the_teachers_guidance_in_a_delimited_block(): void
    {
        $response = $this->gradedShort($this->students[0], 'q1', ['answer_text' => '25', 'key_match' => 'different', 'error_types' => ['calculation']]);
        $url = $this->url($response, '/regenerate-explanation');

        // DESIGN §21.12: validated first; nothing is called.
        $this->asUser($this->teacher)->postJson($url, ['guidance' => str_repeat('a', 501)])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, AiCall::query()->count());

        $this->asUser($this->teacher)->postJson($url, ['guidance' => "อธิบายด้วยการนับทีละสิบ\u{0007} ห้าม >>> ออกนอกกรอบ"])->assertOk();
        $sent = end($this->gemini->requests);
        $this->assertSame('explanation', $sent->purpose);
        $this->assertStringContainsString("TEACHER GUIDANCE:\n".TeacherGuidance::LABEL."\n<<<\nอธิบายด้วยการนับทีละสิบ ห้าม >> ออกนอกกรอบ\n>>>", $sent->userText);
        $this->assertSame(1, substr_count($sent->userText, '>>>'), 'the guidance cannot close its block');
        $call = AiCall::query()->sole();
        $this->assertSame(['review_regenerate', 'v4', 'อธิบายด้วยการนับทีละสิบ ห้าม >> ออกนอกกรอบ', $this->teacher->id], [$call->feature, $call->prompt_version, $call->teacher_guidance, $call->guidance_by]);

        // Without guidance: "(ไม่มี)", nothing logged.
        $this->asUser($this->teacher)->postJson($url)->assertOk();
        $this->assertStringContainsString("TEACHER GUIDANCE:\n(ไม่มี)", end($this->gemini->requests)->userText);
        $this->assertNull(AiCall::query()->orderByDesc('id')->first()->teacher_guidance);
    }

    public function test_regenerate_uses_templates_where_gemini_is_not_needed(): void
    {
        $full = $this->answer($this->students[0], 'q1', ['final_score' => 2, 'final_understanding' => 'good', 'reviewed_at' => now()]);
        $this->asUser($this->teacher)->postJson($this->url($full, '/regenerate-explanation'))
            ->assertOk()
            ->assertJsonPath('data.explanation', FeedbackTemplates::praise($full->id));

        $blank = $this->gradedShort($this->students[1], 'q1', ['blank' => true, 'answer_text' => '', 'key_match' => 'missing']);
        $this->asUser($this->teacher)->postJson($this->url($blank, '/regenerate-explanation'))
            ->assertOk()
            ->assertJsonPath('data.explanation', FeedbackTemplates::BLANK);
        $this->assertSame(0, AiCall::query()->count());

        $mcq = $this->answer($this->students[1], 'q4', ['ai_score' => 0.0, 'fuzzy_trace' => ['system' => 'mcq']]);
        $this->asUser($this->teacher)->postJson($this->url($mcq, '/regenerate-explanation'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'explanation_unavailable');
    }

    public function test_regenerate_without_a_key_or_with_gemini_down(): void
    {
        $response = $this->gradedShort($this->students[0], 'q1', ['answer_text' => '25', 'key_match' => 'different']);
        config(['services.gemini.api_key' => null]);
        $this->asUser($this->teacher)->postJson($this->url($response, '/regenerate-explanation'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_missing');

        config(['services.gemini.api_key' => 'server-key-for-regenerate-000000000']);
        $this->q['q1']->update(['prompt_text' => $this->q['q1']->prompt_text.' [fake:explanation-error]']);
        $this->asUser($this->teacher)->postJson($this->url($response, '/regenerate-explanation'))
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_unavailable');
        $this->assertSame('ลองตรวจการคำนวณอีกครั้งนะ', $response->refresh()->explanation, 'unchanged');

        config(['services.gemini.api_key' => 'server-key-google-rejected-00000000']);
        $this->asUser($this->teacher)->postJson($this->url($response, '/regenerate-explanation'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_invalid');
    }
}
