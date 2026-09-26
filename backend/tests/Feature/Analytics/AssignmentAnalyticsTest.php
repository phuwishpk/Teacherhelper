<?php

namespace Tests\Feature\Analytics;

use App\Models\Response;
use App\Models\Skill;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Review\ReviewFixtures;
use Tests\TestCase;

/**
 * GET /assignments/{id}/analytics (DESIGN §9.6, §14.3): p, r from the 27 %
 * groups once 20 students are published, most-missed order and the
 * skill × error type heatmap.
 */
class AssignmentAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private Skill $s1;

    private Skill $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(22);
        $subject = $this->assignment->subject_id;
        $this->s1 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน']);
        $this->s2 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/2', 'name' => 'ทศนิยม']);
        $this->q['q1']->skills()->attach([$this->s1->id, $this->s2->id]);
        $this->q['q3']->skills()->attach($this->s2->id);
    }

    /**
     * Student i (1..22): q1 = 2 for i <= 11 else 0, q2 = 1 (half), q3 = 2.5
     * (half), q4 = 1 for odd i else 0.5. Totals: odd i <= 11 -> 6.5 (top six),
     * even i > 11 -> 4.0 (bottom six) with the 27 % group size round(5.94) = 6.
     */
    private function publishAll(): void
    {
        foreach ($this->students as $index => $student) {
            $i = $index + 1;
            $answers = $this->answerSheet($student);
            $submission = $this->submission($student);
            $this->reviewAll($submission);
            $answers['q1']->forceFill(['final_score' => $i <= 11 ? 2 : 0, 'final_error_types' => $i <= 11 ? [] : ['concept', 'careless']])->save();
            $answers['q2']->forceFill(['final_score' => 1, 'final_error_types' => []])->save();
            $answers['q3']->forceFill(['final_score' => 2.5, 'final_error_types' => ['calculation']])->save();
            $answers['q4']->forceFill(['final_score' => $i % 2 === 1 ? 1 : 0.5, 'final_error_types' => []])->save();
        }
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")->assertOk();
        $this->assertSame(22, Submission::query()->where('status', 'published')->count());
    }

    public function test_difficulty_discrimination_most_missed_and_the_heatmap(): void
    {
        $this->publishAll();

        $res = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")->assertOk();
        $res->assertJsonPath('data.assignment_id', $this->assignment->id)
            ->assertJsonPath('data.published_count', 22)
            ->assertJsonPath('data.min_count_for_r', 20)
            ->assertJsonCount(4, 'data.items');

        $items = collect($res->json('data.items'))->keyBy('question_id');
        $q = fn (string $key) => $items[$this->q[$key]->id];
        $this->assertEquals([1, 'short', 2.0, 22, 0.5, 1.0], [$q('q1')['position'], $q('q1')['type'], $q('q1')['max_points'], $q('q1')['n'], $q('q1')['p'], $q('q1')['r']]);
        $this->assertEquals([0.5, 0.0], [$q('q2')['p'], $q('q2')['r']]);
        $this->assertEquals([0.5, 0.0], [$q('q3')['p'], $q('q3')['r']]);
        $this->assertEquals([0.75, 0.5], [$q('q4')['p'], $q('q4')['r']]);
        $this->assertSame('12.5 + 7.5 เท่ากับเท่าไร', $q('q1')['prompt_text']);

        $res->assertJsonPath('data.most_missed', [$this->q['q1']->id, $this->q['q2']->id, $this->q['q3']->id, $this->q['q4']->id]);

        $heat = collect($res->json('data.skill_error_counts'))->map(fn (array $c) => [$c['skill']['code'], $c['error_type'], $c['count']])->all();
        $this->assertSame([
            ['ค 1.1 ป.5/1', 'careless', 11],
            ['ค 1.1 ป.5/1', 'concept', 11],
            ['ค 1.1 ป.5/2', 'calculation', 22],
            ['ค 1.1 ป.5/2', 'careless', 11],
            ['ค 1.1 ป.5/2', 'concept', 11],
        ], $heat);
        $this->assertSame($this->s1->id, $res->json('data.skill_error_counts.0.skill.id'));
    }

    public function test_r_needs_twenty_published_students_and_unpublished_work_never_counts(): void
    {
        $this->publishAll();
        // Reopen three submissions (a rescan): 19 left.
        Submission::query()->orderByDesc('id')->limit(3)->update(['status' => Submission::STATUS_REVIEWED, 'published_at' => null]);

        $res = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")->assertOk();
        $res->assertJsonPath('data.published_count', 19);
        foreach ($res->json('data.items') as $item) {
            $this->assertNull($item['r']);
            $this->assertSame(19, $item['n']);
        }
        // Students 20, 21, 22 left: q4 mean over 1..19 = (10·1 + 9·0.5) / 19.
        $q4 = collect($res->json('data.items'))->firstWhere('question_id', $this->q['q4']->id);
        $this->assertSame(round(14.5 / 19, 3), $q4['p']);

        Submission::query()->update(['status' => Submission::STATUS_REVIEWED, 'published_at' => null]);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.published_count', 0)
            ->assertJsonPath('data.items.0.p', null)
            ->assertJsonPath('data.items.0.n', 0)
            ->assertJsonPath('data.most_missed', [])
            ->assertJsonPath('data.skill_error_counts', []);
    }

    public function test_only_the_classroom_teacher_reads_analytics(): void
    {
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")->assertNotFound();
        $this->asUser($this->students[0])->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")->assertForbidden();
        $this->asGuest()->getJson("/api/v1/assignments/{$this->assignment->id}/analytics")->assertUnauthorized();
        $this->assertSame(0, Response::query()->count());
    }
}
