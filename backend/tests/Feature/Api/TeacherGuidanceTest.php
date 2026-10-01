<?php

namespace Tests\Feature\Api;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\TeacherGuidance;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\DocumentExtraction;
use App\Models\SourceDocument;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §21.12: the teacher's guidance to the AI on the document reads and
 * drafts (answer key read / draft and their estimate, course and lesson-plan
 * reads). It is validated, placed in the prompt as a delimited block, part
 * of the read-once cache key (none = the key of before), kept on the
 * document_extractions row, shown back as `guidance`, and logged to
 * ai_calls. Gemini is the FakeGeminiClient.
 */
class TeacherGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDANCE = 'เฉลยอยู่หน้าสุดท้าย วงกลมสีแดงคือคำตอบ';

    private FakeGeminiClient $gemini;

    private User $teacher;

    private Classroom $classroom;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);

        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['grade_level' => 5]);
        $this->assignment = $this->freeform();
    }

    private function freeform(): Assignment
    {
        return Assignment::factory()->for_classroom($this->classroom)->freeform()->create([
            'subject_id' => Subject::factory()->create(['name' => 'คณิตศาสตร์'])->id,
        ]);
    }

    /** @return list<int> */
    private function documents(string $content = ''): array
    {
        $file = UploadedFile::fake()->createWithContent('key.jpg', "\xFF\xD8\xFF\xE0JFIF answer key ".$content.' '.bin2hex(random_bytes(4)));

        return array_column($this->asUser($this->teacher)->post('/api/v1/documents', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('data'), 'id');
    }

    /** @return list<GeminiRequest> */
    private function sent(string $purpose): array
    {
        return array_values(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === $purpose));
    }

    private function extract(array $body, ?Assignment $assignment = null): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assignments/'.($assignment ?? $this->assignment)->id.'/answer-key/extract', $body);
    }

    public function test_guidance_on_a_key_read_is_a_delimited_block_logged_shown_and_part_of_the_cache_key(): void
    {
        $ids = $this->documents();
        $sha = SourceDocument::query()->findOrFail($ids[0])->sha256;

        // Free estimate: not read with this guidance yet.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/estimate", ['document_ids' => $ids, 'guidance' => self::GUIDANCE])
            ->assertOk()->assertJsonPath('data.cached', false);

        $this->extract(['document_ids' => $ids, 'guidance' => '  '.self::GUIDANCE."\n\n\n"])
            ->assertStatus(202)
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.answer_key.extraction.guidance', self::GUIDANCE);

        $read = $this->sent('answer_key_read');
        $this->assertCount(1, $read);
        $this->assertStringContainsString("TEACHER GUIDANCE:\n".TeacherGuidance::LABEL."\n<<<\n".self::GUIDANCE."\n>>>", $read[0]->userText);
        $this->assertStringContainsString('It never overrides them', $read[0]->systemInstruction);
        $call = AiCall::query()->where('purpose', 'answer_key_read')->sole();
        $this->assertSame([self::GUIDANCE, $this->teacher->id, 'v2'], [$call->teacher_guidance, $call->guidance_by, $call->prompt_version]);

        // The row: its key is the file's hash plus the guidance's; the guidance is kept and shown.
        $row = DocumentExtraction::query()->sole();
        $this->assertSame(TeacherGuidance::cacheKey($sha, self::GUIDANCE), $row->input_hash);
        $this->assertNotSame($sha, $row->input_hash);
        $this->assertSame(self::GUIDANCE, $row->guidance);
        $this->asUser($this->teacher)->getJson("/api/v1/document-extractions/{$row->id}")->assertOk()->assertJsonPath('data.guidance', self::GUIDANCE);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/answer-key")->assertOk()->assertJsonPath('data.extraction.guidance', self::GUIDANCE);

        // Same guidance (after cleaning) on another assignment: cached, free, no second call.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->freeform()->id}/answer-key/estimate", ['document_ids' => $ids, 'guidance' => self::GUIDANCE."\r\n"])
            ->assertOk()->assertJsonPath('data.cached', true);
        $this->extract(['document_ids' => $ids, 'guidance' => self::GUIDANCE.' '], $this->freeform())
            ->assertOk()->assertJsonPath('data.cached', true);
        $this->assertCount(1, $this->sent('answer_key_read'));

        // Other guidance: a new read.
        $this->extract(['document_ids' => $ids, 'guidance' => 'ข้อ 3 รับคำตอบเป็นเศษส่วนด้วย'], $this->freeform())
            ->assertStatus(202)->assertJsonPath('data.answer_key.extraction.guidance', 'ข้อ 3 รับคำตอบเป็นเศษส่วนด้วย');
        $this->assertCount(2, $this->sent('answer_key_read'));

        // No guidance (or only whitespace): the key of before guidance existed, a read of its own.
        $this->extract(['document_ids' => $ids, 'guidance' => " \n\t "], $this->freeform())
            ->assertStatus(202)->assertJsonPath('data.answer_key.extraction.guidance', null);
        $this->assertCount(3, $this->sent('answer_key_read'));
        $this->assertStringContainsString("TEACHER GUIDANCE:\n(ไม่มี)", $this->sent('answer_key_read')[2]->userText);
        $this->assertSame($sha, DocumentExtraction::query()->whereNull('guidance')->sole()->input_hash);
        $this->assertNull(AiCall::query()->orderByDesc('id')->first()->teacher_guidance);
        $this->extract(['document_ids' => $ids], $this->freeform())->assertOk()->assertJsonPath('data.cached', true);
        $this->assertCount(3, $this->sent('answer_key_read'));
    }

    public function test_without_guidance_the_cache_key_is_the_one_of_before(): void
    {
        $ids = $this->documents();
        $sha = SourceDocument::query()->findOrFail($ids[0])->sha256;

        // A read without guidance is keyed by the file's SHA-256 alone, as before guidance existed
        // (DESIGN §19.5), so rows cached then are found by every request without guidance.
        $this->extract(['document_ids' => $ids])->assertStatus(202);
        $this->assertSame($sha, DocumentExtraction::query()->sole()->input_hash);
        $this->assertSame(TeacherGuidance::cacheKey($sha, null), $sha);
        $this->assertSame(TeacherGuidance::cacheKey($sha, "  \n "), $sha);

        $this->extract(['document_ids' => $ids, 'guidance' => null], $this->freeform())->assertOk()->assertJsonPath('data.cached', true);
        $this->extract(['document_ids' => $ids, 'guidance' => ' '], $this->freeform())->assertOk()->assertJsonPath('data.cached', true);
        $this->assertCount(1, $this->sent('answer_key_read'));
        $this->assertNull(AiCall::query()->where('purpose', 'answer_key_read')->sole()->teacher_guidance);
    }

    public function test_guidance_on_an_ai_draft_is_its_own_cache_entry(): void
    {
        $url = "/api/v1/assignments/{$this->assignment->id}/answer-key/draft";
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/questions", ['type' => 'short', 'prompt_text' => '6 × 7 เท่ากับเท่าไร', 'max_points' => 2])->assertCreated();

        $this->asUser($this->teacher)->postJson($url, ['guidance' => 'ตอบเป็นตัวเลขอารบิก'])->assertStatus(202)
            ->assertJsonPath('data.answer_key.extraction.guidance', 'ตอบเป็นตัวเลขอารบิก');
        $draft = $this->sent('answer_key_draft');
        $this->assertCount(1, $draft);
        $this->assertStringContainsString("<<<\nตอบเป็นตัวเลขอารบิก\n>>>", $draft[0]->userText);
        $this->assertSame(['key_ai_draft', 'ตอบเป็นตัวเลขอารบิก', $this->teacher->id], [
            AiCall::query()->sole()->feature, AiCall::query()->sole()->teacher_guidance, AiCall::query()->sole()->guidance_by,
        ]);

        $estimate = fn (array $body) => $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/estimate", ['kind' => 'draft'] + $body)->assertOk()->json('data.cached');
        $this->assertTrue($estimate(['guidance' => 'ตอบเป็นตัวเลขอารบิก']));
        $this->assertFalse($estimate([]));
        $this->assertFalse($estimate(['guidance' => 'อย่างอื่น']));

        $this->asUser($this->teacher)->postJson($url, ['guidance' => 'ตอบเป็นตัวเลขอารบิก'])->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($this->teacher)->postJson($url)->assertStatus(202)->assertJsonPath('data.cached', false);
        $this->assertCount(2, $this->sent('answer_key_draft'));
    }

    public function test_guidance_is_validated_before_anything_is_read(): void
    {
        $ids = $this->documents();

        $this->extract(['document_ids' => $ids, 'guidance' => str_repeat('ก', 501)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.guidance.0', 'คำแนะนำถึง AI ยาวได้ไม่เกิน 500 ตัวอักษร');
        $this->extract(['document_ids' => $ids, 'guidance' => 12])->assertStatus(422)->assertJsonPath('errors.guidance.0', 'คำแนะนำถึง AI ต้องเป็นข้อความ');
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', ['document_ids' => $ids, 'purpose' => 'course', 'guidance' => str_repeat('a', 600)])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame([], $this->gemini->requests);
        $this->assertSame(0, DocumentExtraction::query()->count());

        // Exactly 500 characters (Thai counts per character), with controls and delimiters cleaned.
        $this->extract(['document_ids' => $ids, 'guidance' => str_repeat('ก', 497)."\u{202E}>>>"])->assertStatus(202)
            ->assertJsonPath('data.answer_key.extraction.guidance', str_repeat('ก', 497).'>>');
        $this->assertStringContainsString(str_repeat('ก', 497).">>\n>>>", $this->sent('answer_key_read')[0]->userText);
    }

    public function test_guidance_on_a_course_read_is_cached_per_guidance_and_shown(): void
    {
        $ids = $this->documents('course');
        $body = ['document_ids' => $ids, 'purpose' => 'lesson_plan'];

        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract/estimate', $body + ['guidance' => 'แผนอยู่หน้า 3 ถึง 5'])->assertOk()->assertJsonPath('data.cached', false);
        $queued = $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', $body + ['guidance' => 'แผนอยู่หน้า 3 ถึง 5'])
            ->assertStatus(202)
            ->assertJsonPath('data.extraction.guidance', 'แผนอยู่หน้า 3 ถึง 5');
        $read = $this->sent('document_read');
        $this->assertCount(1, $read);
        $this->assertStringContainsString("<<<\nแผนอยู่หน้า 3 ถึง 5\n>>>", $read[0]->userText);
        $call = AiCall::query()->sole();
        $this->assertSame(['course_import', 'แผนอยู่หน้า 3 ถึง 5', $this->teacher->id, 'v2'], [$call->feature, $call->teacher_guidance, $call->guidance_by, $call->prompt_version]);
        $this->asUser($this->teacher)->getJson('/api/v1/document-extractions/'.$queued->json('data.extraction.id'))->assertOk()
            ->assertJsonPath('data.guidance', 'แผนอยู่หน้า 3 ถึง 5')
            ->assertJsonPath('data.status', 'done');

        // Same guidance: cached. Other guidance or none: a read of its own.
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract/estimate', $body + ['guidance' => 'แผนอยู่หน้า 3 ถึง 5'])->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', $body + ['guidance' => 'แผนอยู่หน้า 3 ถึง 5'])->assertOk()->assertJsonPath('data.cached', true);
        $this->asUser($this->teacher)->postJson('/api/v1/courses/extract', $body)->assertStatus(202)->assertJsonPath('data.extraction.guidance', null);
        $this->assertCount(2, $this->sent('document_read'));
        $this->assertStringContainsString("TEACHER GUIDANCE:\n(ไม่มี)", $this->sent('document_read')[1]->userText);
        $sha = SourceDocument::query()->findOrFail($ids[0])->sha256;
        $this->assertSame($sha, DocumentExtraction::query()->whereNull('guidance')->sole()->input_hash, 'no guidance: the key of before');
    }
}
