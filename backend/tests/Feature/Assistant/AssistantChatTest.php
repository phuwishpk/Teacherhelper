<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantChat;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Filament\Resources\AssistantConversations\Pages\ManageAssistantConversations;
use App\Models\AssistantConversation;
use App\Models\TeacherApiKey;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §37 step 1: a teacher asks "ผู้ช่วย Krucheck" about the system with
 * their own Gemini key; the conversation is kept, failures carry a code.
 */
class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->teacher = $this->makeTeacher();
    }

    private function giveKey(User $teacher): void
    {
        TeacherApiKey::query()->create(['user_id' => $teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTSyTeacherKeyForAssistant000000wxyz', 'key_last4' => 'wxyz', 'last_verified_at' => now()]);
    }

    public function test_only_the_teachers_own_key_is_used(): void
    {
        config(['services.gemini.api_key' => 'server-key-not-for-the-assistant-0000']);

        $this->asUser($this->teacher)->getJson('/api/v1/assistant/status')
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.daily_limit', 100);
        $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'ตั้งเกณฑ์เกรดยังไง'])
            ->assertStatus(422)->assertJsonPath('code', 'ai_key_missing')->assertJsonPath('ai_error_code', 'AI-KEY-MISSING');
        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame([], $this->gemini->requests);
    }

    public function test_a_conversation_keeps_its_history_and_the_knowledge_goes_with_it(): void
    {
        $this->giveKey($this->teacher);

        $first = $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'ตั้งเกณฑ์เกรดยังไง'])
            ->assertOk()
            ->assertJsonPath('data.conversation.title', 'ตั้งเกณฑ์เกรดยังไง')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.role', 'assistant');
        $this->assertStringContainsString('ประวัติ 0 ข้อความ', $first->json('data.messages.1.content'));
        $id = $first->json('data.conversation.id');

        $second = $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'แล้วเกรด ร ล่ะ', 'conversation_id' => $id])->assertOk();
        $this->assertStringContainsString('ประวัติ 2 ข้อความ', $second->json('data.messages.1.content'));

        // The core knowledge, the matching manual sections and the history were sent.
        $request = end($this->gemini->requests);
        $this->assertStringContainsString('# KNOWLEDGE: core', $request->systemInstruction);
        $this->assertStringContainsString('เกณฑ์เกรดตั้งต้น', $request->systemInstruction);
        $this->assertStringContainsString('สมุดคะแนน', $request->systemInstruction);
        $this->assertSame(['user', 'model'], array_column($request->history, 'role'));
        $this->assertSame('assistant_chat', $request->purpose);

        $this->assertDatabaseHas('ai_calls', ['purpose' => 'assistant_chat', 'user_id' => $this->teacher->id, 'key_source' => 'teacher', 'status' => 'ok']);
        $conversation = AssistantConversation::query()->findOrFail($id);
        $this->assertGreaterThan(0, $conversation->input_tokens);
        $this->asUser($this->teacher)->getJson("/api/v1/assistant/conversations/{$id}")->assertOk()->assertJsonCount(4, 'data.messages');
        $this->asUser($this->teacher)->getJson('/api/v1/assistant/conversations')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->asUser($this->teacher)->getJson('/api/v1/assistant/status')->assertJsonPath('data.used_today', 2)->assertJsonPath('data.available', true);
    }

    public function test_a_failed_answer_is_kept_with_its_code_and_can_be_retried(): void
    {
        $this->giveKey($this->teacher);

        $failed = $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'ทดสอบ [fake:error-once]'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_unavailable')
            ->assertJsonPath('ai_error_code', 'AI-SERVER')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'error')
            ->assertJsonPath('messages.1.error_code', 'AI-SERVER');
        $callId = $failed->json('ai_call_id');
        $this->assertSame($callId, $failed->json('messages.1.ai_call_id'));
        $this->assertDatabaseHas('ai_calls', ['id' => $callId, 'error_code' => 'AI-SERVER', 'purpose' => 'assistant_chat']);
        $id = $failed->json('conversation.id');

        $this->asUser($this->teacher)->postJson("/api/v1/assistant/conversations/{$id}/retry")
            ->assertOk()->assertJsonPath('data.messages.1.role', 'assistant');
        $this->asUser($this->teacher)->getJson("/api/v1/assistant/conversations/{$id}")
            ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.role', 'assistant');
        // Nothing left to retry, and the retry did not count as a new question.
        $this->asUser($this->teacher)->postJson("/api/v1/assistant/conversations/{$id}/retry")->assertStatus(409)->assertJsonPath('code', 'nothing_to_retry');
        $this->assertSame(1, AssistantChat::usedToday($this->teacher));

        $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'เต็ม [fake:quota]', 'conversation_id' => $id])
            ->assertStatus(502)->assertJsonPath('ai_error_code', 'AI-QUOTA');
    }

    public function test_the_daily_limit_and_privacy_between_teachers(): void
    {
        $this->giveKey($this->teacher);
        $conversation = AssistantConversation::create(['user_id' => $this->teacher->id, 'title' => 'เก่า']);
        for ($i = 0; $i < AssistantChat::DAILY_LIMIT; $i++) {
            $conversation->messages()->create(['role' => 'user', 'content' => "ข้อ {$i}"]);
        }
        $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => 'อีกข้อ'])
            ->assertStatus(429)->assertJsonPath('code', 'assistant_limit')->assertJsonPath('ai_error_code', 'CHAT-LIMIT');

        $other = $this->makeTeacher();
        $this->asUser($other)->getJson("/api/v1/assistant/conversations/{$conversation->id}")->assertNotFound();
        $this->asUser($other)->deleteJson("/api/v1/assistant/conversations/{$conversation->id}")->assertNotFound();
        $this->asUser($other)->getJson('/api/v1/assistant/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->deleteJson("/api/v1/assistant/conversations/{$conversation->id}")->assertNoContent();
        $this->assertDatabaseCount('assistant_messages', 0);

        $student = $this->enrollStudent($this->makeClassroom($this->teacher))['student'];
        $this->asUser($student)->getJson('/api/v1/assistant/status')->assertForbidden();
    }

    public function test_conversations_go_after_90_days_without_a_message(): void
    {
        $old = AssistantConversation::create(['user_id' => $this->teacher->id, 'title' => 'เก่า']);
        $old->forceFill(['updated_at' => now()->subDays(91)])->saveQuietly();
        $recent = AssistantConversation::create(['user_id' => $this->teacher->id, 'title' => 'ใหม่']);

        $this->artisan('eduvision:purge-images')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_the_admin_reads_a_conversation_with_its_error_codes(): void
    {
        Filament::setCurrentPanel('admin');
        $conversation = AssistantConversation::create(['user_id' => $this->teacher->id, 'title' => 'ถามเรื่องสแกน']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'สแกนไม่ผ่าน']);
        $conversation->messages()->create(['role' => 'error', 'content' => 'Google ขัดข้อง', 'error_code' => 'AI-SERVER', 'ai_call_id' => 77]);

        $this->actingAs($this->makeAdmin());
        Livewire::test(ManageAssistantConversations::class)
            ->assertCanSeeTableRecords([$conversation])
            ->mountAction(TestAction::make('view')->table($conversation))
            ->assertMountedActionModalSee(['สแกนไม่ผ่าน', 'รหัส AI-SERVER · #77']);
        $this->assertFalse($this->teacher->can('viewAny', AssistantConversation::class));
    }
}
