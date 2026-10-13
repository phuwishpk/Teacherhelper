<?php

namespace Tests\Feature\Api;

use App\Jobs\RenderWorksheetsJob;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Gradebook\GradebookWorld;
use Tests\TestCase;

/**
 * DESIGN §30.13: homework for chosen students. The others neither see it
 * nor are counted for it, in the gradebook or on the worksheets.
 */
class AssignmentAudienceTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld(3);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function create(array $body = []): TestResponse
    {
        return $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body + [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'งานซ่อมเสริม',
        ]);
    }

    /** The to-do count of the overview, whatever the student's groups. */
    private static function todo(array $json): int
    {
        $groups = $json['groups'] ?? $json['data']['groups'] ?? [];

        return array_sum(array_column($groups, 'todo_count'));
    }

    private function ready(int $id): Assignment
    {
        Question::factory()->create(['assignment_id' => $id, 'max_points' => 10]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$id}/layout")->assertCreated();

        return Assignment::query()->findOrFail($id);
    }

    public function test_the_chosen_students_must_be_on_the_roster_and_exams_go_to_everyone(): void
    {
        [$s1, $s2, $s3] = $this->students;
        $this->create(['audience' => 'students'])->assertStatus(422)->assertJsonValidationErrors(['student_ids']);
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher, ['name' => 'ป.6/1']), 1, 'คนนอก')['student'];
        $this->create(['audience' => 'students', 'student_ids' => [$s1->id, $outsider->id]])->assertStatus(422)->assertJsonValidationErrors(['student_ids']);
        $this->create(['audience' => 'students', 'student_ids' => [$s1->id], 'kind' => 'exam', 'due_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['audience']);

        $id = $this->create(['audience' => 'students', 'student_ids' => [$s2->id, $s1->id]])->assertCreated()
            ->assertJsonPath('data.audience', 'students')
            ->assertJsonPath('data.student_ids', [$s1->id, $s2->id])
            ->json('data.id');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['student_ids' => [$s3->id]])->assertOk()->assertJsonPath('data.student_ids', [$s3->id]);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['audience' => 'all'])->assertOk()->assertJsonPath('data.audience', 'all')->assertJsonPath('data.student_ids', []);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments?classroom_id={$this->classroom->id}")->assertOk()->assertJsonPath('data.0.audience', 'all');
    }

    public function test_a_student_the_work_is_not_for_neither_sees_nor_hands_it_in(): void
    {
        [$given, $other] = $this->students;
        $assignment = $this->ready($this->create(['audience' => 'students', 'student_ids' => [$given->id]])->json('data.id'));

        $this->asUser($given)->getJson('/api/v1/student/assignments')->assertOk()->assertJsonPath('data.0.id', $assignment->id);
        $this->assertSame(1, self::todo($this->asUser($given)->getJson('/api/v1/student/overview')->assertOk()->json()));
        $this->asUser($given)->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonPath('data.0.assignment.id', $assignment->id);

        $this->asUser($other)->getJson('/api/v1/student/assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, self::todo($this->asUser($other)->getJson('/api/v1/student/overview')->assertOk()->json()));
        $this->asUser($other)->getJson('/api/v1/student/class-feed')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($other)->post("/api/v1/student/assignments/{$assignment->id}/submission", [], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_the_gradebook_leaves_the_others_out_and_the_worksheets_print_only_for_them(): void
    {
        [$given, $other] = $this->students;
        $cats = $this->useTemplate();
        $hw = $this->appAssignment('งานซ่อมเสริม', 10, $cats['การบ้าน'], ['due_at' => now()->subDay()]);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$hw->id}", ['audience' => 'students', 'student_ids' => [$given->id]])->assertOk();
        $this->published($hw, $given, 6);

        $grid = $this->grid();
        $this->assertSame('scored', $this->rowOf($grid, $given)['cells']["a{$hw->id}"]['state']);
        $cell = $this->rowOf($grid, $other)['cells']["a{$hw->id}"];
        $this->assertSame('not_assigned', $cell['state']);
        $this->assertNull($cell['percent']);
        $this->assertNull($cell['score']);
        // Not a 0 in the formula: the other student's homework category has nothing yet.
        $this->assertNull($this->rowOf($grid, $other)['categories'][(string) $cats['การบ้าน']->id]['percent'] ?? null);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$hw->id}/layout")->assertSuccessful();
        Queue::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$hw->id}/worksheets")->assertStatus(202);
        Queue::assertPushed(RenderWorksheetsJob::class, fn (RenderWorksheetsJob $job) => $job->studentIds === [$given->id]);
    }
}
