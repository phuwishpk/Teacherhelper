<?php

namespace Tests\Feature\Practice;

use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PracticeGenerator;
use App\Domain\Practice\PracticeBank;
use App\Jobs\GeneratePracticeItemsJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\LearningResource;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The teacher side of the practice bank (DESIGN §9.6, §10.6, §14.1):
 * Gemini drafts through the queue, teacher-written items, edit / approve /
 * retire, review links.
 */
class PracticeBankTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Subject $math;

    private Skill $fractions;

    private Skill $otherSubjectSkill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->math = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $sci = Subject::factory()->create(['code' => 'ว', 'name' => 'วิทยาศาสตร์']);
        $this->fractions = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1 ป.4/2', 'name' => 'บวกลบเศษส่วน', 'grade_level' => 4]);
        $this->otherSubjectSkill = Skill::factory()->create(['subject_id' => $sci->id, 'code' => 'ว 1.1 ป.4/1', 'name' => 'สิ่งมีชีวิต', 'grade_level' => 4]);
        // The teacher teaches maths: an assignment in that subject in their classroom.
        Assignment::factory()->for_classroom($this->classroom)->create(['subject_id' => $this->math->id]);
    }

    private function runJob(GeneratePracticeItemsJob $job): void
    {
        $job->handle(app(PracticeGenerator::class), app(GeminiKeyResolver::class), app(PracticeBank::class));
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes = []): PracticeItem
    {
        return PracticeItem::create([
            'school_id' => $this->teacher->school_id,
            'skill_id' => $this->fractions->id,
            'answer_type' => 'numeric',
            'prompt_text' => '3/4 + 1/4 = ?',
            'options' => null,
            'answer_key' => ['accepted' => ['1', '4/4'], 'numeric' => ['value' => 1, 'abs_tol' => 0]],
            'explanation' => 'ตัวส่วนเท่ากัน บวกตัวเศษ 3 + 1 = 4 ได้ 4/4 = 1',
            'status' => 'draft',
            'source' => 'ai',
            ...$attributes,
        ]);
    }

    public function test_generate_queues_a_job_that_stores_gemini_drafts(): void
    {
        Queue::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->fractions->id}/practice-items/generate", ['count' => 4])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.count', 4);
        Queue::assertPushedOn('default', GeneratePracticeItemsJob::class, fn (GeneratePracticeItemsJob $job) => $job->skillId === $this->fractions->id && $job->count === 4 && $job->teacherId === $this->teacher->id);

        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->fractions->id}/practice-items/generate")->assertStatus(202)->assertJsonPath('data.count', 5);
        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->fractions->id}/practice-items/generate", ['count' => 21])->assertStatus(422)->assertJsonValidationErrors(['count']);
        $this->asUser($this->teacher)->postJson('/api/v1/skills/999999/practice-items/generate')->assertNotFound();

        $this->runJob(new GeneratePracticeItemsJob($this->fractions->id, (int) $this->teacher->school_id, $this->teacher->id, 4));

        $items = PracticeItem::query()->orderBy('id')->get();
        $this->assertCount(4, $items);
        $this->assertSame(['numeric', 'short', 'mcq', 'numeric'], $items->pluck('answer_type')->all());
        $this->assertTrue($items->every(fn (PracticeItem $i) => $i->status === 'draft' && $i->source === 'ai' && $i->school_id === $this->teacher->school_id && $i->skill_id === $this->fractions->id));
        $this->assertArrayHasKey('numeric', $items[0]->answer_key);
        $this->assertSame($items[0]->answer_key['accepted'][0], (string) (int) $items[0]->answer_key['numeric']['value']);
        $this->assertNull($items[0]->options);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($items[2]->options, 'key'));
        $this->assertSame('C', $items[2]->answer_key['correct'], 'the fake rotates the choices; the key follows the correct text');
        $this->assertNotSame('', $items[2]->explanation);

        $call = AiCall::query()->sole();
        $this->assertSame(['practice_gen', 'ok', 'server', $this->fractions->id], [$call->purpose, $call->status, $call->key_source, $call->skill_id]);

        // Only teachers of the school see them.
        $this->asUser($this->teacher)->getJson('/api/v1/practice-items?status=draft')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.skill.code', 'ค 1.1 ป.4/2');
        $this->asUser($this->makeTeacher())->getJson('/api/v1/practice-items')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_gemini_output_stores_nothing_and_a_missing_key_is_only_logged(): void
    {
        $bad = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 9.9', 'name' => 'ทักษะ [fake:practice-bad-key]']);
        $this->runJob(new GeneratePracticeItemsJob($bad->id, (int) $this->teacher->school_id, $this->teacher->id, 3));
        $this->assertSame(0, PracticeItem::query()->count());
        $this->assertSame(['invalid_output', 'invalid_output'], AiCall::query()->orderBy('id')->pluck('status')->all(), 'invalid output is retried once');

        // Without any key (teacher or server) the endpoint refuses up front, like the other AI endpoints.
        config(['services.gemini.api_key' => '']);
        Queue::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->fractions->id}/practice-items/generate", ['count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_missing');
        Queue::assertNothingPushed();

        // A key removed while the job waited in the queue: the job ends with a log line only.
        $this->runJob(new GeneratePracticeItemsJob($this->fractions->id, (int) $this->teacher->school_id, $this->teacher->id, 3));
        $this->assertSame(0, PracticeItem::query()->count());
        $this->assertSame(2, AiCall::query()->count(), 'no call without a key');
    }

    public function test_a_teacher_writes_an_item_and_the_bank_is_listed_with_filters(): void
    {
        $res = $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => $this->fractions->id,
            'answer_type' => 'mcq',
            'prompt_text' => 'ข้อใดเท่ากับ 1/2',
            'options' => ['2/4', '1/3', '3/4'],
            'answer_key' => ['correct' => 'a'],
            'explanation' => '2/4 ทอนได้ 1/2',
        ])->assertCreated();
        $res->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source', 'teacher')
            ->assertJsonPath('data.options', [['key' => 'A', 'text' => '2/4'], ['key' => 'B', 'text' => '1/3'], ['key' => 'C', 'text' => '3/4']])
            ->assertJsonPath('data.answer_key', ['correct' => 'A'])
            ->assertJsonPath('data.skill.id', $this->fractions->id);

        $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => $this->fractions->id, 'answer_type' => 'mcq', 'prompt_text' => 'x', 'options' => ['ก', 'ข'], 'answer_key' => ['correct' => 'C'], 'explanation' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors(['answer_key.correct']);
        $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => $this->fractions->id, 'answer_type' => 'short', 'prompt_text' => 'x', 'answer_key' => ['accepted' => ['ก']],
        ])->assertStatus(422)->assertJsonValidationErrors(['explanation']);
        $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => $this->fractions->id, 'answer_type' => 'numeric', 'prompt_text' => 'x', 'answer_key' => ['accepted' => ['สอง']], 'explanation' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors(['answer_key.numeric']);
        $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => 999999, 'answer_type' => 'short', 'prompt_text' => 'x', 'answer_key' => ['accepted' => ['ก']], 'explanation' => 'y',
        ])->assertStatus(422)->assertJsonValidationErrors(['skill_id']);

        // A numeric item may give only the value: accepted is derived from it.
        $this->asUser($this->teacher)->postJson('/api/v1/practice-items', [
            'skill_id' => $this->fractions->id, 'answer_type' => 'numeric', 'prompt_text' => '1/2 + 1/4', 'answer_key' => ['numeric' => ['value' => 0.75]], 'explanation' => 'y', 'status' => 'approved',
        ])->assertCreated()->assertJsonPath('data.answer_key', ['accepted' => ['0.75'], 'numeric' => ['value' => 0.75, 'abs_tol' => 0]])->assertJsonPath('data.status', 'approved');

        $this->item(['skill_id' => $this->otherSubjectSkill->id, 'status' => 'retired']);
        $this->asUser($this->teacher)->getJson('/api/v1/practice-items')->assertOk()->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'meta' => ['next_cursor']]);
        $this->asUser($this->teacher)->getJson("/api/v1/practice-items?skill={$this->fractions->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/practice-items?status=retired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.skill_id', $this->otherSubjectSkill->id);
        $this->asUser($this->teacher)->getJson('/api/v1/practice-items?status=bogus')->assertStatus(422);
    }

    public function test_teachers_edit_approve_and_retire_items_of_their_school(): void
    {
        $item = $this->item();
        $url = "/api/v1/practice-items/{$item->id}";

        $this->asUser($this->teacher)->patchJson($url, ['prompt_text' => '3/4 + 1/4 เท่ากับเท่าไร'])
            ->assertOk()
            ->assertJsonPath('data.prompt_text', '3/4 + 1/4 เท่ากับเท่าไร')
            ->assertJsonPath('data.answer_key.numeric.value', 1)
            ->assertJsonPath('data.status', 'draft');

        // A colleague who teaches no maths may edit but not approve.
        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->patchJson($url, ['explanation' => 'แก้คำอธิบาย'])->assertOk()->assertJsonPath('data.explanation', 'แก้คำอธิบาย');
        $this->asUser($colleague)->patchJson($url, ['status' => 'approved'])->assertForbidden()->assertJsonPath('code', 'subject_not_taught');
        $this->assertSame('draft', $item->refresh()->status);

        $this->asUser($this->teacher)->patchJson($url, ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $this->teacher->id);
        $this->assertNotNull($item->refresh()->approved_at);

        $this->asUser($this->teacher)->patchJson($url, ['status' => 'retired'])->assertOk()->assertJsonPath('data.status', 'retired');
        $this->assertSame($this->teacher->id, $item->refresh()->approved_by, 'retiring keeps who approved it');
        $this->asUser($this->teacher)->patchJson($url, ['status' => 'draft'])->assertOk()->assertJsonPath('data.approved_by', null);

        $this->asUser($this->teacher)->patchJson($url, ['answer_key' => ['accepted' => []]])->assertStatus(422);
        $this->asUser($this->teacher)->patchJson($url, ['status' => 'live'])->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Other schools: 404. Students: 403.
        $this->asUser($this->makeTeacher())->patchJson($url, ['status' => 'approved'])->assertNotFound();
        $student = $this->enrollStudent($this->classroom)['student'];
        $this->asUser($student)->patchJson($url, ['status' => 'approved'])->assertForbidden();
        $this->asUser($student)->getJson('/api/v1/practice-items')->assertForbidden();
    }

    public function test_the_content_of_an_approved_item_is_changed_only_by_a_subject_teacher(): void
    {
        $approvedAt = now()->subDay();
        $item = $this->item(['status' => 'approved', 'approved_by' => $this->teacher->id, 'approved_at' => $approvedAt]);
        $url = "/api/v1/practice-items/{$item->id}";
        $colleague = $this->makeTeacher($this->teacher->school);

        // A colleague refused approval cannot rewrite the approved item either: it stays as approved.
        $edits = [
            ['answer_key' => ['accepted' => ['2'], 'numeric' => ['value' => 2, 'abs_tol' => 0]]],
            ['prompt_text' => '1 + 1 = ?'],
            ['explanation' => 'คำอธิบายใหม่'],
            ['answer_type' => 'short', 'answer_key' => ['accepted' => ['หนึ่ง']]],
            ['status' => 'approved', 'explanation' => 'คำอธิบายใหม่'],
        ];
        foreach ($edits as $edit) {
            $this->asUser($colleague)->patchJson($url, $edit)->assertForbidden()->assertJsonPath('code', 'subject_not_taught');
        }
        $item->refresh();
        $this->assertSame(['approved', 'numeric', '3/4 + 1/4 = ?', 1, $this->teacher->id], [$item->status, $item->answer_type, $item->prompt_text, $item->answer_key['numeric']['value'], $item->approved_by]);
        $this->assertSame($approvedAt->timestamp, $item->approved_at->timestamp);

        // Sending the same content back (other key order, 1.0 for 1) is not an edit.
        $this->asUser($colleague)->patchJson($url, [
            'prompt_text' => '3/4 + 1/4 = ?',
            'answer_key' => ['numeric' => ['abs_tol' => 0, 'value' => 1.0], 'accepted' => ['1', '4/4']],
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $this->teacher->id);

        // Taking it out of the pool first is allowed; the edit then waits for a new approval. Retiring keeps the stamp.
        $this->asUser($colleague)->patchJson($url, ['status' => 'retired', 'explanation' => 'เลิกใช้'])->assertOk()->assertJsonPath('data.approved_by', $this->teacher->id);
        $this->asUser($colleague)->patchJson($url, ['status' => 'draft', 'explanation' => 'แก้โดยครูวิทย์'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approved_by', null)
            ->assertJsonPath('data.explanation', 'แก้โดยครูวิทย์');
        $this->asUser($colleague)->patchJson($url, ['explanation' => 'แก้อีกครั้งตอนเป็น draft'])->assertOk();
        $this->asUser($this->teacher)->patchJson($url, ['status' => 'approved'])->assertOk()->assertJsonPath('data.approved_by', $this->teacher->id);

        // A second maths teacher may change it and thereby re-approves it (approved_by/at become theirs).
        $maths2 = $this->makeTeacher($this->teacher->school);
        Assignment::factory()->for_classroom($this->makeClassroom($maths2))->create(['subject_id' => $this->math->id]);
        $this->travel(1)->hours();
        $this->asUser($maths2)->patchJson($url, ['prompt_text' => '3/4 + 1/4 เท่ากับเท่าไร'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.prompt_text', '3/4 + 1/4 เท่ากับเท่าไร')
            ->assertJsonPath('data.approved_by', $maths2->id);
        $this->assertSame(now()->timestamp, $item->refresh()->approved_at->timestamp);
    }

    public function test_review_links_are_managed_per_skill_and_school(): void
    {
        $skillUrl = "/api/v1/skills/{$this->fractions->id}/resources";
        $id = $this->asUser($this->teacher)->postJson($skillUrl, ['title' => 'คลิปเศษส่วน', 'url' => 'https://example.org/frac'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'คลิปเศษส่วน')
            ->assertJsonPath('data.skill_id', $this->fractions->id)
            ->json('data.id');
        $this->asUser($this->teacher)->postJson($skillUrl, ['title' => 'x', 'url' => 'ftp://example.org'])->assertStatus(422)->assertJsonValidationErrors(['url']);
        $this->asUser($this->teacher)->postJson($skillUrl, ['title' => '', 'url' => 'https://example.org'])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->asUser($this->teacher)->postJson('/api/v1/skills/999999/resources', ['title' => 'x', 'url' => 'https://example.org'])->assertNotFound();

        $this->asUser($this->teacher)->getJson($skillUrl)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->asUser($this->makeTeacher())->getJson($skillUrl)->assertOk()->assertJsonCount(0, 'data');

        $this->asUser($this->teacher)->patchJson("/api/v1/resources/{$id}", ['title' => 'คลิปใหม่'])->assertOk()->assertJsonPath('data.title', 'คลิปใหม่')->assertJsonPath('data.url', 'https://example.org/frac');
        $this->asUser($this->makeTeacher())->deleteJson("/api/v1/resources/{$id}")->assertNotFound();
        $this->asUser($this->teacher)->deleteJson("/api/v1/resources/{$id}")->assertNoContent();
        $this->assertSame(0, LearningResource::query()->count());
    }
}
