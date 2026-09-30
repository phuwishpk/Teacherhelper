<?php

namespace Tests\Feature\Gradebook;

use App\Models\GradebookPublishedGrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §23.12 beyond the route matrix: a teacher's own course cannot
 * reach another teacher's classroom, and a student reads only their own
 * row of their own classroom's current publication.
 */
class GradebookAuthorizationTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld(2);
    }

    public function test_a_colleagues_classroom_is_never_reachable_through_the_teachers_own_course(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $own = $this->makeCourse($colleague);
        // Bound behind the API's back: the classroom still is not the colleague's.
        $own->classrooms()->attach($this->classroom->id);
        $this->asUser($colleague)->putJson("/api/v1/courses/{$own->id}/gradebook/categories", ['template' => 'collect_final'])->assertOk();
        $category = $own->gradebookCategories()->first();

        $this->asUser($colleague)->getJson("/api/v1/courses/{$own->id}/gradebook?classroom_id={$this->classroom->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($colleague)->postJson("/api/v1/courses/{$own->id}/gradebook/publish", ['classroom_id' => $this->classroom->id])
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($colleague)->get("/api/v1/courses/{$own->id}/gradebook/export?classroom_id={$this->classroom->id}")
            ->assertStatus(422);
        $this->asUser($colleague)->postJson("/api/v1/courses/{$own->id}/gradebook-items", [
            'classroom_ids' => [$this->classroom->id], 'category_id' => $category->id, 'name' => 'x', 'max_points' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors(['classroom_ids.0']);
        $this->asUser($colleague)->putJson("/api/v1/courses/{$own->id}/gradebook/special-grades", [
            'classroom_id' => $this->classroom->id, 'student_id' => $this->students[0]->id, 'special' => 'ms',
        ])->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);

        // The owner's course and item stay 404 for the colleague.
        $this->asUser($colleague)->getJson("/api/v1/courses/{$this->course->id}/gradebook/settings")->assertNotFound();
    }

    public function test_a_student_reads_only_their_own_row(): void
    {
        $cats = $this->useTemplate('collect_final');
        [$a, $b] = $this->students;
        foreach ($cats as $category) {
            $item = $this->item('รายการ '.$category->name, 10, $category);
            $this->putItemScores($item, [['student_id' => $a->id, 'score' => 9], ['student_id' => $b->id, 'score' => 3]])->assertOk();
        }
        $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook/publish", ['classroom_id' => $this->classroom->id])->assertCreated();

        $mine = $this->asUser($b)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertOk()->json('data');
        $this->assertEquals(30, $mine['total']);
        $this->assertSame(0, (int) $mine['grade']);
        $this->assertCount(2, GradebookPublishedGrade::query()->get());
        $json = (string) json_encode($this->asUser($b)->getJson('/api/v1/student/grades')->json());
        $this->assertStringNotContainsString('"total_rounded":90', $json);
        $this->assertStringNotContainsString($a->name, $json);

        // A student of another classroom of the same course has nothing published.
        $second = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->course->classrooms()->attach($second->id);
        $other = $this->enrollStudent($second, 1, 'ห้องอื่น')['student'];
        $this->asUser($other)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertNotFound();
        $this->asUser($other)->getJson('/api/v1/student/grades')->assertOk()->assertJsonPath('data', []);
    }
}
