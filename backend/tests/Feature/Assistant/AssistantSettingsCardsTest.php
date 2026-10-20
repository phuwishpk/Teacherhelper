<?php

namespace Tests\Feature\Assistant;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §37.9: gradebook settings and practice items from the assistant.
 */
class AssistantSettingsCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Course $course;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->teacher = $this->makeTeacher();
        $this->course = $this->makeCourse($this->teacher, [$this->makeClassroom($this->teacher)]);
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000068', 'key_last4' => '0068']);
    }

    /** @return array<string, mixed> */
    private function card(string $text): array
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assistant/messages', ['text' => $text])->assertOk()->json('data.messages.1');
    }

    private function apply(int $id, ?array $selected = null): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/assistant/messages/{$id}/apply", $selected === null ? [] : ['selected' => $selected]);
    }

    public function test_a_gradebook_card_keeps_same_named_categories_and_lists_the_removed(): void
    {
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['categories' => [
            ['name' => 'งาน', 'weight' => 50, 'is_homework_default' => true],
            ['name' => 'จิตพิสัย', 'weight' => 50],
        ]])->assertOk();
        $work = GradebookCategory::query()->where('course_id', $this->course->id)->where('name', 'งาน')->sole();
        $affective = GradebookCategory::query()->where('course_id', $this->course->id)->where('name', 'จิตพิสัย')->sole();
        $homework = Assignment::factory()->create(['course_id' => $this->course->id, 'gradebook_category_id' => $affective->id]);

        $card = $this->card("[fake:gradebook:{$this->course->id}] ตั้งหมวดคะแนนใหม่ งาน 60 สอบ 40");
        $system = $this->gemini->requests[0]->systemInstruction;
        $this->assertStringContainsString("- {$this->course->id} ค15101 คณิตศาสตร์ 5 | งาน 50 [0] (homework default); จิตพิสัย 50 [1] | 80,75,70,65,60,55,50", $system);
        $this->assertSame('gradebook', $card['plan']['kind']);
        $this->assertSame($work->id, $card['plan']['categories'][0]['id']);
        $this->assertArrayNotHasKey('id', $card['plan']['categories'][1]);
        $this->assertSame([['name' => 'จิตพิสัย', 'item_count' => 1]], $card['plan']['removed']);
        $this->assertArrayNotHasKey('problem', $card['plan']);

        $result = $this->apply($card['id'])->assertOk()->json('data.message.plan.result');
        $this->assertSame([$this->course->id, 2, true], [$result['course_id'], $result['categories'], $result['cutoffs']]);
        $this->assertSame(['งาน' => '60.00', 'สอบ' => '40.00'], GradebookCategory::query()->where('course_id', $this->course->id)->orderBy('position')->pluck('weight', 'name')->map(fn ($w) => number_format((float) $w, 2, '.', ''))->all());
        $this->assertTrue(GradebookCategory::query()->whereKey($work->id)->exists());
        $this->assertNull($homework->refresh()->gradebook_category_id);
    }

    public function test_a_gradebook_card_that_does_not_add_up_cannot_be_applied(): void
    {
        $card = $this->card("[fake:gradebook-bad:{$this->course->id}] หมวดคะแนน");
        $this->assertSame('น้ำหนักทุกหมวดรวมกันต้องได้ 100 (ตอนนี้ได้ 90)', $card['plan']['problem']);
        $this->apply($card['id'])->assertStatus(422)->assertJsonPath('code', 'plan_invalid');
        $this->assertSame(0, GradebookCategory::query()->count());

        $other = $this->makeCourse($this->makeTeacher());
        $foreign = $this->card("[fake:gradebook:{$other->id}] หมวดคะแนน");
        $this->assertArrayHasKey('problem', $foreign['plan']);
        $this->apply($foreign['id'])->assertStatus(422);
    }

    public function test_a_practice_card_saves_ticked_items_as_ai_drafts(): void
    {
        Skill::create(['subject_id' => $this->course->subject_id, 'code' => 'FAKE-1', 'name' => 'บวกเศษส่วน', 'level' => 'indicator', 'grade_level' => 5, 'source' => 'curriculum']);
        $card = $this->card('[fake:practice:fake-1] ขอแบบฝึกบวกเศษส่วน');
        $this->assertStringContainsString('- FAKE-1 | indicator | บวกเศษส่วน', $this->gemini->requests[0]->systemInstruction);
        $this->assertSame('practice', $card['plan']['kind']);
        $this->assertSame('FAKE-1 บวกเศษส่วน', $card['plan']['target']['title']);
        $this->assertSame([['key' => 'A', 'text' => '1/6'], ['key' => 'B', 'text' => '3/4'], ['key' => 'C', 'text' => '2/6']], $card['plan']['items'][0]['options']);
        $this->assertSame(['correct' => 'B'], $card['plan']['items'][0]['answer_key']);
        $this->assertArrayHasKey('error', $card['plan']['items'][3]);

        $result = $this->apply($card['id'], [0, 1, 2, 3])->assertOk()->json('data.message.plan.result');
        $this->assertSame(3, $result['created']);
        $this->assertSame([3], array_column($result['skipped'], 'index'));
        $items = PracticeItem::query()->orderBy('id')->get();
        $this->assertSame(['mcq', 'short', 'numeric'], $items->pluck('answer_type')->all());
        $this->assertTrue($items->every(fn (PracticeItem $i) => $i->status === 'draft' && $i->source === 'ai' && $i->school_id === $this->teacher->school_id));
        $this->assertEqualsWithDelta(0.75, $items[2]->answer_key['numeric']['value'], 0.0001);

        $unknown = $this->card('[fake:practice:nope-9] แบบฝึก');
        $this->assertArrayHasKey('error', $unknown['plan']['target']);
        $this->apply($unknown['id'], [0])->assertStatus(422)->assertJsonPath('code', 'plan_no_target');
    }
}
