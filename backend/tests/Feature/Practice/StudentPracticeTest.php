<?php

namespace Tests\Feature\Practice;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Practice\PracticeAttempts;
use App\Models\Classroom;
use App\Models\LearningResource;
use App\Models\Mastery;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * DESIGN §9.7, §14.1: recommendations for weak skills (mastery < 0.75,
 * weakest first, up to 3 approved items not tried in 7 days, review links)
 * and attempts graded at once with AnswerMatcher, feeding mastery.
 */
class StudentPracticeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private User $student;

    /** @var array<string, Skill> */
    private array $skills = [];

    /** @var array<string, PracticeItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->student = $this->enrollStudent($this->classroom)['student'];
        $subject = Subject::factory()->create();
        foreach (['a' => 'ค 1.1 ป.4/1', 'b' => 'ค 1.1 ป.4/2', 'c' => 'ค 1.1 ป.4/3'] as $key => $code) {
            $this->skills[$key] = Skill::factory()->create(['subject_id' => $subject->id, 'code' => $code, 'grade_level' => 4]);
        }
        $this->mastery('a', 0.3, 2);
        $this->mastery('b', 0.6, 1);
        $this->mastery('c', 0.9, 3);

        $this->items['a1'] = $this->item('a', ['prompt_text' => '5 + 7 = ?', 'answer_key' => ['accepted' => ['12'], 'numeric' => ['value' => 12, 'abs_tol' => 0]]]);
        $this->items['a2'] = $this->item('a', ['answer_type' => 'short', 'prompt_text' => 'เมืองหลวงของไทย', 'answer_key' => ['accepted' => ['กรุงเทพมหานคร', 'กรุงเทพฯ']]]);
        $this->items['a3'] = $this->item('a', ['answer_type' => 'mcq', 'prompt_text' => 'ข้อใดเท่ากับ 1/2', 'options' => [['key' => 'A', 'text' => '1/3'], ['key' => 'B', 'text' => '2/4']], 'answer_key' => ['correct' => 'B']]);
        $this->items['a4'] = $this->item('a', ['prompt_text' => '9 - 4 = ?', 'answer_key' => ['accepted' => ['5'], 'numeric' => ['value' => 5, 'abs_tol' => 0]]]);
        $this->items['a_draft'] = $this->item('a', ['status' => 'draft']);
        $this->items['a_other_school'] = $this->item('a', ['school_id' => $this->makeSchool()->id]);
        $this->items['b1'] = $this->item('b');
        $this->items['c1'] = $this->item('c');
        LearningResource::create(['school_id' => $this->teacher->school_id, 'skill_id' => $this->skills['a']->id, 'title' => 'คลิปบวกเลข', 'url' => 'https://example.org/add', 'added_by' => $this->teacher->id]);
    }

    /** n homework observations of the same ratio, so the EWMA equals that ratio. */
    private function mastery(string $skill, float $value, int $n): void
    {
        foreach (range(1, $n) as $i) {
            SkillObservation::create([
                'student_id' => $this->student->id,
                'skill_id' => $this->skills[$skill]->id,
                'source' => 'homework',
                'score_ratio' => $value,
                'observed_at' => now()->subDays($n - $i + 1),
            ]);
        }
        app(MasteryCalculator::class)->recompute($this->student->id, $this->skills[$skill]->id);
        $this->assertSame($value, (float) Mastery::query()->where('student_id', $this->student->id)->where('skill_id', $this->skills[$skill]->id)->value('value'));
    }

    /** @param array<string, mixed> $attributes */
    private function item(string $skill, array $attributes = []): PracticeItem
    {
        return PracticeItem::create([
            'school_id' => $this->teacher->school_id,
            'skill_id' => $this->skills[$skill]->id,
            'answer_type' => 'numeric',
            'prompt_text' => '3 + 4 = ?',
            'options' => null,
            'answer_key' => ['accepted' => ['7'], 'numeric' => ['value' => 7, 'abs_tol' => 0]],
            'explanation' => 'นับต่อจาก 3 ไปอีก 4 ได้ 7',
            'status' => 'approved',
            'source' => 'ai',
            ...$attributes,
        ]);
    }

    public function test_recommendations_follow_the_rules_of_the_design(): void
    {
        $res = $this->asUser($this->student)->getJson('/api/v1/student/practice')->assertOk();

        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.skill.code', 'ค 1.1 ป.4/1')
            ->assertJsonPath('data.0.mastery.value', 0.3)
            ->assertJsonPath('data.0.mastery.n_obs', 2)
            ->assertJsonPath('data.0.mastery.level', 'not_yet')
            ->assertJsonPath('data.1.skill_id', $this->skills['b']->id)
            ->assertJsonPath('data.1.mastery.level', 'too_little')
            ->assertJsonCount(3, 'data.0.items')
            ->assertJsonPath('data.0.items.*.id', [$this->items['a1']->id, $this->items['a2']->id, $this->items['a3']->id])
            ->assertJsonPath('data.0.items.2.options', [['key' => 'A', 'text' => '1/3'], ['key' => 'B', 'text' => '2/4']])
            ->assertJsonPath('data.0.items.0.options', null)
            ->assertJsonCount(1, 'data.0.resources')
            ->assertJsonPath('data.0.resources.0.url', 'https://example.org/add')
            ->assertJsonCount(1, 'data.1.items')
            ->assertJsonCount(0, 'data.1.resources')
            ->assertJsonPath('meta.weak_below', 0.75);

        $body = json_encode($res->json());
        foreach (['answer_key', 'explanation', 'accepted', 'correct'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "{$secret} must not reach students");
        }

        // GET /student/mastery uses the same order (by value): the weaknesses are the skills practised first.
        $ids = fn (array $keys) => array_map(fn (string $k) => $this->skills[$k]->id, $keys);
        $this->asUser($this->student)->getJson('/api/v1/student/mastery')
            ->assertOk()
            ->assertJsonPath('data.*.skill_id', $ids(['a', 'b', 'c']))
            ->assertJsonPath('data.1.level', 'too_little')
            ->assertJsonPath('meta.weaknesses', $ids(['a', 'b', 'c']));

        // A student without weak skills gets nothing; teachers cannot use the endpoint.
        $strong = $this->enrollStudent($this->classroom, 2)['student'];
        $this->asUser($strong)->getJson('/api/v1/student/practice')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/student/practice')->assertForbidden();
    }

    public function test_attempts_are_graded_at_once_and_move_mastery(): void
    {
        $url = fn (string $item) => "/api/v1/student/practice/{$this->items[$item]->id}/attempts";

        // Thai digits are fine; a right answer gets no explanation. Mastery: 0.15·1 + 0.85·0.3 = 0.405.
        $this->asUser($this->student)->postJson($url('a1'), ['answer' => ' ๑๒ '])
            ->assertCreated()
            ->assertJsonPath('data.score_ratio', 1)
            ->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.explanation', null)
            ->assertJsonPath('data.mastery.skill.id', $this->skills['a']->id)
            ->assertJsonPath('data.mastery.value', 0.405)
            ->assertJsonPath('data.mastery.n_obs', 3);
        $observation = SkillObservation::query()->where('source', 'practice')->sole();
        $this->assertSame(['practice', $this->skills['a']->id, 1.0], [$observation->source, $observation->skill_id, $observation->score_ratio]);
        $this->assertNotNull($observation->practice_attempt_id);

        // Same item again within 7 days: refused, and it is no longer recommended.
        $this->asUser($this->student)->postJson($url('a1'), ['answer' => '12'])->assertStatus(409)->assertJsonPath('code', 'practice_already_attempted');
        $this->asUser($this->student)->getJson('/api/v1/student/practice')
            ->assertJsonPath('data.0.items.*.id', [$this->items['a2']->id, $this->items['a3']->id, $this->items['a4']->id]);

        // A near miss on a numeric item (rel_err 0.05 -> 0.6·0.5 = 0.3) scores partially
        // and shows the explanation. Mastery: 0.15·0.3 + 0.85·0.405 = 0.389.
        $this->asUser($this->student)->postJson($url('a4'), ['answer' => '5.25'])
            ->assertCreated()
            ->assertJsonPath('data.score_ratio', 0.3)
            ->assertJsonPath('data.correct', false)
            ->assertJsonPath('data.explanation', 'นับต่อจาก 3 ไปอีก 4 ได้ 7')
            ->assertJsonPath('data.mastery.value', 0.389);

        // Short answers use the flexible text rule; mcq takes the option key or its text.
        $this->asUser($this->student)->postJson($url('a2'), ['answer' => 'กรุงเทพฯ '])->assertCreated()->assertJsonPath('data.score_ratio', 1);
        $this->asUser($this->student)->postJson($url('a3'), ['answer' => 'b'])->assertCreated()->assertJsonPath('data.score_ratio', 1);
        $this->travel(8)->days();
        // Past the 7-day window every item is recommended again (first three by id) and may be retried.
        $this->asUser($this->student)->getJson('/api/v1/student/practice')
            ->assertJsonPath('data.0.items.*.id', [$this->items['a1']->id, $this->items['a2']->id, $this->items['a3']->id]);
        $this->asUser($this->student)->postJson($url('a3'), ['answer' => '2/4'])->assertCreated()->assertJsonPath('data.score_ratio', 1);
        $this->asUser($this->student)->postJson($url('a1'), ['answer' => 'สิบสอง'])->assertCreated()->assertJsonPath('data.score_ratio', 0)->assertJsonPath('data.correct', false);

        $this->assertSame(6, SkillObservation::query()->where('source', 'practice')->count());
        $mastery = Mastery::query()->where('student_id', $this->student->id)->where('skill_id', $this->skills['a']->id)->sole();
        $this->assertSame(8, $mastery->n_obs);
    }

    public function test_two_attempts_on_one_item_at_the_same_moment_count_once(): void
    {
        $item = $this->items['a1'];
        $url = "/api/v1/student/practice/{$item->id}/attempts";
        $held = Cache::lock(PracticeAttempts::lockKey($this->student->id, $item->id), 30);
        $this->assertTrue($held->get());

        // Another request of the same student holds the lock past the wait: refused like a repeat, nothing written.
        $this->asUser($this->student)->postJson($url, ['answer' => '12'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'practice_already_attempted');
        $this->assertSame(0, PracticeAttempt::query()->count());
        $this->assertSame(0, SkillObservation::query()->where('source', 'practice')->count());

        // Once it is released the attempt goes through and releases the lock again.
        $held->release();
        $this->asUser($this->student)->postJson($url, ['answer' => '12'])->assertCreated();
        $free = Cache::lock(PracticeAttempts::lockKey($this->student->id, $item->id), 1);
        $this->assertTrue($free->get());
        $free->release();
        $this->assertSame(1, PracticeAttempt::query()->count());
    }

    public function test_only_approved_items_of_the_students_school_can_be_attempted(): void
    {
        foreach (['a_draft', 'a_other_school'] as $key) {
            $this->asUser($this->student)->postJson("/api/v1/student/practice/{$this->items[$key]->id}/attempts", ['answer' => '7'])->assertNotFound();
        }
        $this->asUser($this->student)->postJson("/api/v1/student/practice/{$this->items['b1']->id}/attempts", ['answer' => ''])->assertStatus(422)->assertJsonValidationErrors(['answer']);
        $this->asUser($this->teacher)->postJson("/api/v1/student/practice/{$this->items['b1']->id}/attempts", ['answer' => '7'])->assertForbidden();
        $this->asGuest()->postJson("/api/v1/student/practice/{$this->items['b1']->id}/attempts", ['answer' => '7'])->assertUnauthorized();
        $this->assertSame(0, SkillObservation::query()->where('source', 'practice')->count());
    }
}
