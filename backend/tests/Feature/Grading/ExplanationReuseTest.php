<?php

namespace Tests\Feature\Grading;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Grading\ExplanationCache;
use App\Domain\Grading\GradeApplier;
use App\Domain\Grading\ResponseGrader;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Question;
use App\Models\Response;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §21.7 item 6: an identical (normalised) wrong answer to the same
 * question reuses the stored explanation instead of calling Gemini, the
 * teacher's edited text before Gemini's.
 */
class ExplanationReuseTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->short->update(['prompt_text' => $this->short->prompt_text.' [fake:wrong]']);
    }

    /** Scans page 1 of a (new) student and grades it; returns the short answer. */
    private function gradeStudent(User $student): Response
    {
        $meta = $this->metaFor(1, qr: $this->qr(1, 1, $student->id));
        $scanId = (int) $this->postScan($meta)->assertStatus(201)->json('scan_id');
        $this->app->call([(new GradeScanJob($scanId))->withFakeQueueInteractions(), 'handle']);

        return Response::query()->where('question_id', $this->short->id)->where('scan_id', $scanId)->sole();
    }

    private function newStudent(int $number): User
    {
        return $this->enrollStudent($this->classroom, $number, "นักเรียน {$number}")['student'];
    }

    private function explanationCalls(): int
    {
        return AiCall::query()->where('purpose', 'explanation')->count();
    }

    public function test_the_same_wrong_answer_reuses_the_first_explanation(): void
    {
        $first = $this->gradeStudent($this->student);
        $this->assertSame('ai', $first->explanation_source);
        $this->assertSame(1, $this->explanationCalls());
        $row = DB::table('explanation_cache')->sole();
        $this->assertSame([$this->short->id, 'ai', $first->id, $first->explanation], [(int) $row->question_id, $row->source, (int) $row->response_id, $row->explanation]);
        $this->assertSame(hash('sha256', ExplanationCache::fingerprint($this->short->fresh())."\n".'short:50'), $row->answer_hash, 'the fake writes 50 for a wrong answer to 20');

        $second = $this->gradeStudent($this->newStudent(13));
        $this->assertSame([$first->explanation, 'reused'], [$second->explanation, $second->explanation_source]);
        $this->assertSame(1, $this->explanationCalls(), 'no second explanation call');

        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$second->id}")->assertOk()
            ->assertJsonPath('data.explanation_source', 'reused');
    }

    public function test_the_teacher_s_edit_wins_over_gemini_s_text(): void
    {
        $first = $this->gradeStudent($this->student);
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$first->id}", [
            'final_score' => 0,
            'final_understanding' => 'not_yet',
            'explanation' => 'ครูอธิบาย: 12.5 + 7.5 ได้ 20 ลองบวกทศนิยมอีกครั้ง',
        ])->assertOk();
        $this->assertSame(['teacher', 'ครูอธิบาย: 12.5 + 7.5 ได้ 20 ลองบวกทศนิยมอีกครั้ง'], [
            DB::table('explanation_cache')->value('source'),
            DB::table('explanation_cache')->value('explanation'),
        ]);

        $second = $this->gradeStudent($this->newStudent(13));
        $this->assertSame(['ครูอธิบาย: 12.5 + 7.5 ได้ 20 ลองบวกทศนิยมอีกครั้ง', 'reused'], [$second->explanation, $second->explanation_source]);
        $this->assertSame(1, $this->explanationCalls());

        // The first edit of a reused text keeps it as the machine's original.
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$second->id}", [
            'final_score' => 0,
            'final_understanding' => 'not_yet',
            'explanation' => 'อีกแบบ',
        ])->assertOk()->assertJsonPath('data.ai_explanation', 'ครูอธิบาย: 12.5 + 7.5 ได้ 20 ลองบวกทศนิยมอีกครั้ง');

        // Regenerating never replaces the teacher's stored text.
        $this->asUser($this->teacher)->postJson("/api/v1/responses/{$first->id}/regenerate-explanation")->assertOk();
        $this->assertSame(['teacher', 'อีกแบบ'], [DB::table('explanation_cache')->value('source'), DB::table('explanation_cache')->value('explanation')]);
    }

    public function test_regenerating_replaces_a_stored_ai_text(): void
    {
        $first = $this->gradeStudent($this->student);
        DB::table('explanation_cache')->update(['explanation' => 'ข้อความเก่า']);

        $this->asUser($this->teacher)->postJson("/api/v1/responses/{$first->id}/regenerate-explanation")->assertOk();
        $this->assertSame((string) $first->refresh()->explanation, DB::table('explanation_cache')->value('explanation'));
    }

    public function test_a_full_score_edit_or_an_open_answer_stores_nothing(): void
    {
        $first = $this->gradeStudent($this->student);
        DB::table('explanation_cache')->delete();
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$first->id}", [
            'final_score' => 2,
            'final_understanding' => 'good',
            'reason' => 'อ่านใหม่แล้วถูก',
            'explanation' => 'ถูกต้อง',
        ])->assertOk();
        $this->assertSame(0, DB::table('explanation_cache')->count());

        $this->assertNull(ExplanationCache::key(Question::TYPE_OPEN, ['blank' => false, 'transcription' => 'x']));
        $this->assertNull(ExplanationCache::key(Question::TYPE_SHORT, ['blank' => true, 'answer_text' => '']));
        $this->assertNull(ExplanationCache::key(Question::TYPE_SHORT, ['blank' => false, 'answer_text' => '  ']));
    }

    public function test_the_key_follows_the_kind_of_explanation(): void
    {
        $short = fn (string $a) => ExplanationCache::key(Question::TYPE_SHORT, ['blank' => false, 'answer_text' => $a]);
        $this->assertSame('short:40', $short(' ๔๐ '), 'Thai digits and spaces normalised (§11.4)');
        $this->assertSame('short:bangkok', $short('Bangkok'));

        $work = fn (array $steps, string $final) => ExplanationCache::key(Question::TYPE_SHOW_WORK, ['blank' => false, 'steps' => $steps, 'final_answer_text' => $final]);
        $valid = [['line' => 1, 'text' => '3x = 15', 'valid' => true], ['line' => 2, 'text' => 'x = 5', 'valid' => true]];
        $this->assertSame('final:6', $work($valid, '6'), 'every step valid: about the final answer only');
        $this->assertSame($work($valid, '6'), $work([['line' => 1, 'text' => 'other', 'valid' => true]], '6'));

        $wrong = [['line' => 2, 'text' => 'x = 4', 'valid' => false], ['line' => 1, 'text' => '3x = 12', 'valid' => true]];
        $this->assertStringStartsWith('steps:', (string) $work($wrong, '4'));
        $this->assertSame($work($wrong, '4'), $work(array_reverse($wrong), '4'), 'line order, not output order');
        $changed = $wrong;
        $changed[1]['text'] = '3x = 13';
        $this->assertNotSame($work($wrong, '4'), $work($changed, '4'), 'one different line: its own explanation');
    }

    public function test_within_one_batch_only_the_first_identical_answer_asks_gemini(): void
    {
        $other = $this->newStudent(13);
        $ids = [];
        foreach ([$this->student, $other] as $student) {
            $scanId = (int) $this->postScan($this->metaFor(1, qr: $this->qr(1, 1, $student->id)))->assertStatus(201)->json('scan_id');
            $ids[] = Response::query()->where('question_id', $this->short->id)->where('scan_id', $scanId)->value('id');
        }
        $responses = Response::query()->with('question.rubricCriteria')->findMany($ids)->keyBy('id')->all();
        $extraction = ['blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'answer_text' => '40', 'key_match' => 'different', 'error_types' => ['concept']];
        $graded = [];
        foreach ($responses as $id => $response) {
            $graded[$id] = ResponseGrader::grade($response->question, [], 'normal', $extraction);
        }

        [$explanations, $errors] = app(GradeApplier::class)->explain($graded, $responses, array_fill_keys($ids, $extraction), new GeminiKey('k', 'server'), 'ป.5');

        $this->assertSame([], $errors);
        $this->assertSame(['ai', 'reused'], [$explanations[$ids[0]]['source'], $explanations[$ids[1]]['source']]);
        $this->assertSame($explanations[$ids[0]]['text'], $explanations[$ids[1]]['text']);
        $this->assertSame(1, $this->explanationCalls());
    }

    public function test_editing_the_answer_key_stops_reusing_the_old_text(): void
    {
        $first = $this->gradeStudent($this->student);
        $this->assertSame('ai', $first->explanation_source);

        // The key was typed wrong: the stored text explains against the old key.
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$this->short->id}", [
            'answer_key' => ['accepted' => ['21'], 'numeric' => ['value' => 21, 'abs_tol' => 0]],
        ])->assertOk();

        $second = $this->gradeStudent($this->newStudent(13));
        $this->assertSame('ai', $second->explanation_source, 'a fresh explanation, not the one written against the old key');
        $this->assertSame(2, $this->explanationCalls());
        $this->assertSame(2, DB::table('explanation_cache')->where('question_id', $this->short->id)->count());

        $third = $this->gradeStudent($this->newStudent(14));
        $this->assertSame('reused', $third->explanation_source, 'the new key reuses the new text');
        $this->assertSame(2, $this->explanationCalls());
    }

    public function test_the_fingerprint_follows_the_question_and_its_rubric(): void
    {
        $question = $this->short->fresh();
        $base = ExplanationCache::fingerprint($question);
        $this->assertSame($base, ExplanationCache::fingerprint($this->short->fresh()), 'stable');

        foreach ([
            ['prompt_text' => 'โจทย์ใหม่'],
            ['model_answer' => 'คำตอบตัวอย่าง'],
            ['max_points' => 3],
            ['match_mode' => 'exact'],
        ] as $change) {
            $this->assertNotSame($base, ExplanationCache::fingerprint($question->replicate()->fill($change)), (string) array_key_first($change));
        }

        $question->rubricCriteria()->create(['position' => 1, 'description' => 'บวกทศนิยมถูก', 'points' => 2, 'is_core' => true, 'source' => 'teacher']);
        $this->assertNotSame($base, ExplanationCache::fingerprint($question->fresh()), 'a rubric criterion');
    }
}
