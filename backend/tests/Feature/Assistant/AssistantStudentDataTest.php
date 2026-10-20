<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantChat;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Filament\Resources\AssistantConversations\Pages\ManageAssistantConversations;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\AssistantConversation;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Question;
use App\Models\Submission;
use App\Models\TeacherApiKey;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §37.8: the assistant answers about students' results from data
 * the server computes, with students as labels; Gemini never sees a name.
 */
class AssistantStudentDataTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    private User $somchai;

    private User $malee;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->somchai = $this->enrollStudent($this->classroom, 1, 'เด็กชายธนาธิป ฉัตรชัย')['student'];
        $this->malee = $this->enrollStudent($this->classroom, 2, 'เด็กหญิงปุณยวีร์ ทองคำ')['student'];
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000067', 'key_last4' => '0067']);
    }

    private function ack(): void
    {
        $this->asUser($this->teacher)->postJson('/api/v1/assistant/student-data/ack')->assertOk()
            ->assertJsonPath('data.student_data', ['allowed' => true, 'acknowledged' => true]);
    }

    private function ask(array $body): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', $body);
    }

    /** Everything Gemini was sent. */
    private function sent(): string
    {
        return implode("\n", array_map(
            fn ($r) => $r->systemInstruction."\n".$r->userText."\n".implode("\n", array_column($r->history, 'text')),
            $this->gemini->requests,
        ));
    }

    private function assertNoNames(): void
    {
        // First names and full names; surnames like ใจดี also occur in the manual's examples.
        foreach (['ธนาธิป', 'ปุณยวีร์', 'เด็กชายธนาธิป ฉัตรชัย', 'เด็กหญิงปุณยวีร์ ทองคำ'] as $private) {
            $this->assertStringNotContainsString($private, $this->sent(), "Gemini saw {$private}");
        }
    }

    public function test_names_become_labels_and_the_answer_asks_for_data_in_a_second_call(): void
    {
        $this->ack();
        $data = $this->ask(['text' => 'คะแนนของธนาธิป ฉัตรชัยเป็นยังไงบ้าง [fake:need-student:s1]'])->assertOk()->json('data');

        $this->assertSame([['label' => 'S1', 'student_id' => $this->somchai->id, 'name' => 'เด็กชายธนาธิป ฉัตรชัย', 'classroom_id' => $this->classroom->id]], $data['labels']);
        $this->assertStringStartsWith('ผู้ช่วยทดสอบอ่านข้อมูล: # STUDENT DATA: S1 in classroom ป.5/1', $data['messages'][1]['content']);
        $this->assertSame('คะแนนของธนาธิป ฉัตรชัยเป็นยังไงบ้าง [fake:need-student:s1]', $data['messages'][0]['content']);
        $this->assertCount(2, $this->gemini->requests);
        $this->assertStringContainsString('คะแนนของS1เป็นยังไงบ้าง', $this->gemini->requests[0]->userText);
        $this->assertSame(2, AiCall::query()->where('purpose', 'assistant_chat')->count());
        $this->assertGreaterThan(0, AssistantConversation::query()->sole()->input_tokens);
        $this->assertSame(1, AssistantChat::usedToday($this->teacher));
        $this->assertNoNames();

        // A first name only one student has, in a later message, keeps its label.
        $this->ask(['text' => 'แล้วปุณยวีร์ล่ะ', 'conversation_id' => $data['conversation']['id']])->assertOk()
            ->assertJsonPath('data.labels.1.label', 'S2');
        $this->assertStringContainsString('แล้วS2ล่ะ', end($this->gemini->requests)->userText);
        $this->assertStringContainsString('S1', end($this->gemini->requests)->history[0]['text']);
        $this->assertNoNames();
    }

    public function test_a_chosen_classroom_or_assignment_is_one_call_with_labels_only(): void
    {
        $this->ack();
        $homework = Assignment::factory()->for_classroom($this->classroom)->create(['course_id' => $this->course->id, 'subject_id' => $this->course->subject_id, 'title' => 'การบ้านเศษส่วน', 'status' => 'ready']);
        Question::create(['assignment_id' => $homework->id, 'position' => 1, 'type' => 'short', 'prompt_text' => '1+1', 'max_points' => 10, 'answer_key' => ['accepted' => ['2']], 'rubric_status' => 'not_needed']);
        Submission::create(['assignment_id' => $homework->id, 'student_id' => $this->somchai->id, 'status' => 'published', 'total_score' => 4, 'published_at' => now(), 'late' => true]);

        $this->ask(['text' => 'ห้องนี้เป็นยังไงบ้าง', 'scope' => ['set' => 'classroom_summary', 'classroom_id' => $this->classroom->id]])->assertOk();
        $this->assertCount(1, $this->gemini->requests);
        $system = $this->gemini->requests[0]->systemInstruction;
        $this->assertStringContainsString('# STUDENT DATA: classroom ป.5/1 (2 students; labels only)', $system);
        $this->assertStringContainsString('- S1 | ', $system);
        $this->assertStringContainsString('- S2 | ', $system);

        $this->ask(['text' => 'งานนี้ข้อไหนผิดเยอะ', 'scope' => ['set' => 'assignment_analysis', 'assignment_id' => $homework->id]])->assertOk();
        $system = end($this->gemini->requests)->systemInstruction;
        $this->assertStringContainsString('# STUDENT DATA: assignment "การบ้านเศษส่วน" (homework) in ป.5/1, full marks 10', $system);
        $this->assertStringContainsString('Published results: 1', $system);
        $this->assertMatchesRegularExpression('/No submission yet: S\d/', $system);
        $this->assertMatchesRegularExpression('/Lowest totals: S\d 4/', $system);
        $this->assertStringContainsString('Late: 1', $system);
        $this->assertNoNames();
    }

    public function test_the_notice_the_switch_and_access(): void
    {
        $scope = ['set' => 'classroom_summary', 'classroom_id' => $this->classroom->id];
        $this->asUser($this->teacher)->getJson('/api/v1/assistant/status')
            ->assertJsonPath('data.student_data', ['allowed' => true, 'acknowledged' => false]);
        $this->ask(['text' => 'ห้องนี้', 'scope' => $scope])->assertStatus(409)->assertJsonPath('code', 'student_data_ack_required');

        // Not turned on: a request for data gets the fixed explanation, one call only.
        $reply = $this->ask(['text' => 'ห้องนี้ [fake:need:classroom_summary:'.$this->classroom->id.']'])->assertOk()->json('data.messages.1.content');
        $this->assertSame(AssistantChat::DATA_NOT_ON, $reply);
        $this->assertCount(1, $this->gemini->requests);
        $this->assertStringContainsString('STUDENT DATA ACCESS: not turned on yet', $this->gemini->requests[0]->systemInstruction);

        // Someone else's classroom or assignment: 404, and no empty conversation is left.
        $this->ack();
        $other = $this->makeTeacher();
        $theirs = $this->makeClassroom($other);
        $count = AssistantConversation::query()->count();
        $this->ask(['text' => 'ห้องนี้', 'scope' => ['set' => 'classroom_summary', 'classroom_id' => $theirs->id]])->assertNotFound();
        $this->ask(['text' => 'งานนี้', 'scope' => ['set' => 'assignment_analysis', 'assignment_id' => Assignment::factory()->for_classroom($theirs)->create()->id]])->assertNotFound();
        $this->ask(['text' => 'คนนี้', 'scope' => ['set' => 'student_history', 'student_id' => $this->enrollStudent($theirs, 1, 'คนอื่น')['student']->id]])->assertNotFound();
        $this->assertSame($count, AssistantConversation::query()->count());

        // The admin's switch.
        $this->teacher->school->forceFill(['assistant_student_data' => false])->save();
        $this->ask(['text' => 'ห้องนี้', 'scope' => $scope])->assertForbidden()->assertJsonPath('code', 'student_data_off');
        $this->asUser($this->teacher)->postJson('/api/v1/assistant/student-data/ack')->assertForbidden();
        $this->ask(['text' => 'ห้องนี้'])->assertOk();
        $this->assertStringContainsString('STUDENT DATA ACCESS: off', end($this->gemini->requests)->systemInstruction);
    }

    public function test_the_admin_opens_the_label_table(): void
    {
        // Through the service: an HTTP request before Livewire::test resets Livewire's test state.
        $this->teacher->forceFill(['assistant_data_ack_at' => now()])->save();
        app(AssistantChat::class)->send($this->teacher, null, 'ธนาธิป ฉัตรชัย เป็นยังไง');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());
        Livewire::test(ManageAssistantConversations::class)
            ->mountAction(TestAction::make('view')->table(AssistantConversation::query()->sole()))
            ->assertMountedActionModalSee(['ตารางป้ายนักเรียน', 'S1 = เด็กชายธนาธิป ฉัตรชัย']);
    }
}
