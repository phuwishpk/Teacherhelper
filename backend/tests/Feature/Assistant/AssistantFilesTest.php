<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantChat;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\ReadCourseDocumentJob;
use App\Jobs\ReadExamDocumentJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\AssistantMessage;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §37.10: the teacher attaches files; the assistant reads questions
 * from them, or sends them to the existing readers with a file card.
 */
class AssistantFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000069', 'key_last4' => '0069']);
    }

    private function upload(?User $as = null, ?UploadedFile $file = null): int
    {
        $file ??= UploadedFile::fake()->createWithContent('ใบงานเศษส่วน.jpg', "\xFF\xD8\xFF\xE0JFIF worksheet ".bin2hex(random_bytes(4)));

        return $this->asUser($as ?? $this->teacher)->post('/api/v1/documents', ['files' => [$file]], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.0.id');
    }

    private function ask(string $text, array $documents = [], ?int $conversation = null): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => $text, 'document_ids' => $documents, 'conversation_id' => $conversation]);
    }

    private function assignment(array $fields = []): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $fields + [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'การบ้านเศษส่วน',
        ])->assertCreated()->json('data.id');

        return Assignment::query()->findOrFail($id);
    }

    public function test_questions_are_read_from_an_attached_file_in_a_second_call(): void
    {
        $homework = $this->assignment();
        $doc = $this->upload();

        $data = $this->ask("[fake:need-file:{$homework->id}] สร้างข้อจากไฟล์นี้ลงการบ้านเศษส่วน", [$doc])->assertOk()->json('data');

        $this->assertSame([['id' => $doc, 'name' => 'ใบงานเศษส่วน.jpg', 'mime_type' => 'image/jpeg', 'page_count' => 1]], $data['messages'][0]['attachments']);
        $this->assertSame('questions', $data['messages'][1]['plan']['kind']);
        $this->assertSame($homework->id, $data['messages'][1]['plan']['target']['assignment_id']);
        $this->assertCount(2, $this->gemini->requests);
        $this->assertSame([], $this->gemini->requests[0]->images);
        $this->assertStringContainsString("- {$doc} | ใบงานเศษส่วน.jpg | image/jpeg | 1 pages", $this->gemini->requests[0]->systemInstruction);
        $this->assertCount(1, $this->gemini->requests[1]->images);
        $this->assertSame('image/jpeg', $this->gemini->requests[1]->images[0]->mimeType);
        $this->assertSame(2, AiCall::query()->where('purpose', 'assistant_chat')->count());

        // A later question still sees the files of the conversation.
        $this->ask('แล้วไฟล์นี้มีกี่หน้า', [], $data['conversation']['id'])->assertOk();
        $this->assertStringContainsString("- {$doc} | ใบงานเศษส่วน.jpg", end($this->gemini->requests)->systemInstruction);
        $this->assertStringContainsString("[ATTACHED: {$doc} ใบงานเศษส่วน.jpg (1 p.)]", end($this->gemini->requests)->history[0]['text']);
    }

    public function test_only_the_teachers_own_short_files_are_read(): void
    {
        $theirs = $this->upload($this->makeTeacher());
        $this->ask('อ่านไฟล์นี้', [$theirs])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $pages = '';
        for ($i = 3; $i < 14; $i++) {
            $pages .= "{$i} 0 obj << /Type /Page /Parent 2 0 R >> endobj\n";
        }
        $long = UploadedFile::fake()->createWithContent('ข้อสอบยาว.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Count 11 >> endobj\n{$pages}trailer << /Root 1 0 R >>\n%%EOF");
        $homework = $this->assignment();
        $reply = $this->ask("[fake:need-file:{$homework->id}] สร้างข้อจากไฟล์นี้", [$this->upload(null, $long)])->assertOk()->json('data.messages.1');
        $this->assertSame(AssistantChat::FILE_TOO_LONG, $reply['content']);
        $this->assertCount(1, $this->gemini->requests);
    }

    public function test_file_cards_start_the_existing_readers(): void
    {
        $doc = $this->upload();
        $homework = $this->assignment(['mode' => 'freeform']);
        $card = $this->ask("[fake:file:answer_key:{$homework->id}] อ่านเฉลยจากไฟล์นี้", [$doc])->assertOk()->json('data.messages.1');
        $this->assertSame(['kind' => 'file', 'action' => 'answer_key', 'documents' => [['id' => $doc, 'name' => 'ใบงานเศษส่วน.jpg', 'page_count' => 1]]], array_diff_key($card['plan'], ['target' => 0]));
        $result = $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$card['id']}/apply")->assertOk()->json('data.message.plan.result');
        $this->assertSame([$homework->id, false], [$result['assignment_id'], $result['cached']]);
        Queue::assertPushed(ExtractDocumentJob::class);

        $exam = $this->assignment(['title' => 'สอบเศษส่วน', 'kind' => 'exam', 'due_at' => '2026-10-30T02:00:00Z', 'duration_minutes' => 60]);
        $card = $this->ask("[fake:file:exam:{$exam->id}] อ่านข้อสอบจากไฟล์", [$doc])->assertOk()->json('data.messages.1');
        $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$card['id']}/apply")->assertOk();
        Queue::assertPushed(ReadExamDocumentJob::class);

        // An exam is not a homework: the card says so and cannot be applied.
        $wrong = $this->ask("[fake:file:answer_key:{$exam->id}] อ่านเฉลย", [$doc])->assertOk()->json('data.messages.1');
        $this->assertSame('อ่านเฉลยจากไฟล์ใช้กับการบ้าน ไม่ใช่ข้อสอบ', $wrong['plan']['target']['error']);
        $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$wrong['id']}/apply")->assertStatus(422)->assertJsonPath('code', 'plan_no_target');

        $course = $this->ask('[fake:file:course:0] อ่านโครงสร้างรายวิชาจากไฟล์', [$doc])->assertOk()->json('data.messages.1');
        $result = $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$course['id']}/apply")->assertOk()->json('data.message.plan.result');
        $this->assertIsInt($result['extraction_id']);
        Queue::assertPushed(ReadCourseDocumentJob::class);
        $this->assertSame('applied', AssistantMessage::query()->findOrFail($course['id'])->plan_status);
    }
}
