<?php

namespace Tests\Feature\Gradebook;

use App\Models\Assignment;
use App\Models\GradebookEntry;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DESIGN §23.3 / §23.4: the classroom grid, typed scores, "ยกเว้น" and "ให้เต็มทั้งห้อง". */
class GradebookGridTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld();
    }

    public function test_an_unconfigured_course_shows_the_roster_without_values(): void
    {
        $grid = $this->grid();

        $this->assertFalse($grid['configured']);
        $this->assertSame([], $grid['categories']);
        $this->assertSame([1, 2, 3], array_column($grid['rows'], 'student_number'));
        $this->assertArrayNotHasKey('total', $grid['rows'][0]);
        $this->assertNull($grid['publication']);
    }

    public function test_the_worked_example_through_the_api(): void
    {
        $cats = $this->useTemplate();
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['categories' => array_map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'weight' => $c->weight, 'drop_lowest' => $c->name === 'การบ้าน' ? 1 : 0, 'is_homework_default' => $c->is_homework_default,
        ], array_values($cats))])->assertOk();
        [$s12, $s2] = $this->students;

        $hw1 = $this->appAssignment('การบ้าน 1', 10, $cats['การบ้าน']);
        $hw2 = $this->appAssignment('การบ้าน 2', 10, $cats['การบ้าน']);
        $hw3 = $this->appAssignment('การบ้าน 3', 20, $cats['การบ้าน']);
        $this->appAssignment('การบ้าน 4', 10, $cats['การบ้าน']);
        $hw5 = $this->appAssignment('การบ้าน 5', 10, $cats['การบ้าน']);
        $practice = $this->appAssignment('งานฝึก', 10, $cats['การบ้าน'], ['excluded_from_grade' => true]);
        $mid = $this->appAssignment('สอบกลางภาค', 40, $cats['กลางภาค'], ['kind' => Assignment::KIND_EXAM, 'grading_method' => Assignment::GRADING_APP]);
        $final = $this->manualExam('สอบปลายภาค', 50, $cats['ปลายภาค']);
        $dress = $this->item('การแต่งกาย', 10, $cats['จิตพิสัย']);
        $attendance = $this->item('การเข้าเรียน', 20, $cats['จิตพิสัย'], true);

        $this->published($hw1, $s12, 8);
        // effectiveTotal(): the override from Classroom counts (§19.3).
        $this->published($hw2, $s12, 9, 5);
        $this->published($hw3, $s12, 18);
        $this->published($practice, $s12, 2);
        $this->published($mid, $s12, 26);
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$hw5->id}/gradebook-scores", ['scores' => [['student_id' => $s12->id, 'excused' => true]]])->assertOk();
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$final->id}/gradebook-scores", ['scores' => [['student_id' => $s12->id, 'score' => 33]]])->assertOk();
        $this->putItemScores($dress, [['student_id' => $s12->id, 'score' => 10]])->assertOk();
        $this->putItemScores($attendance, [['student_id' => $s12->id, 'score' => 18]])->assertOk();

        $grid = $this->grid();
        $row = $this->rowOf($grid, $s12);

        $this->assertTrue($grid['configured']);
        $this->assertTrue($grid['complete']);
        $this->assertSame(100, (int) $grid['counted_weight']);
        $this->assertEquals(22, $row['categories'][$cats['การบ้าน']->id]['points']);
        $this->assertEquals(19.8, $row['categories'][$cats['ปลายภาค']->id]['points']);
        $this->assertEquals(73.8, $row['total']);
        $this->assertSame(74, $row['total_rounded']);
        $this->assertEquals(3, $row['grade']);
        $this->assertFalse($row['attendance_warning']);
        $this->assertSame('excused', $row['cells']["a{$hw5->id}"]['state']);
        $this->assertSame('missing', $row['cells']['a'.($hw3->id + 1)]['state']);
        $this->assertTrue($row['cells']['a'.($hw3->id + 1)]['dropped']);
        $this->assertEquals(50, $row['cells']["a{$hw2->id}"]['percent']);
        $this->assertSame('not_counted', $row['cells']["a{$practice->id}"]['state']);

        // Columns by category order, then date; manual exam and items editable.
        $columns = collect($grid['columns'])->keyBy('key');
        $this->assertSame('manual_exam', $columns["a{$final->id}"]['type']);
        $this->assertTrue($columns["a{$final->id}"]['editable']);
        $this->assertFalse($columns["a{$hw1->id}"]['editable']);
        $this->assertTrue($columns["i{$attendance}"]['is_attendance']);
        $this->assertEquals(40, $columns["a{$mid->id}"]['full_marks']);
        $this->assertSame([$cats['การบ้าน']->id, $cats['กลางภาค']->id, $cats['ปลายภาค']->id, $cats['จิตพิสัย']->id], array_values(array_unique(array_column($grid['columns'], 'category_id'))));

        // Student 2 handed in nothing: every app homework past due is 0.
        $this->assertSame('missing', $this->rowOf($grid, $s2)['cells']["a{$hw1->id}"]['state']);
        $this->assertSame($hw1->submissions()->first()->id, $row['cells']["a{$hw1->id}"]['submission_id']);
    }

    public function test_the_classroom_is_in_progress_until_every_category_counts(): void
    {
        $cats = $this->useTemplate('collect_final');
        $hw = $this->appAssignment('การบ้าน', 10, $cats['คะแนนเก็บ']);
        $this->published($hw, $this->students[0], 7);
        $this->manualExam('สอบปลายภาค', 50, $cats['ปลายภาค'], now()->addWeek()->toIso8601String());

        $grid = $this->grid();
        $row = $this->rowOf($grid, $this->students[0]);

        $this->assertFalse($grid['complete']);
        $this->assertSame(['ปลายภาค'], $grid['missing_categories']);
        $this->assertEquals(70, $grid['counted_weight']);
        $this->assertEquals(70, $row['total']);
        $this->assertNull($row['total_rounded']);
        $this->assertNull($row['grade']);
        $this->assertTrue($row['in_progress']);
        $this->assertFalse(collect($grid['categories'])->firstWhere('name', 'ปลายภาค')['has_items']);
    }

    public function test_item_scores_are_typed_cleared_and_excused(): void
    {
        $cats = $this->useTemplate();
        $item = $this->item('ความตั้งใจ', 10, $cats['จิตพิสัย']);
        [$a, $b, $c] = $this->students;

        $entries = $this->putItemScores($item, [
            ['student_id' => $a->id, 'score' => 7.5],
            ['student_id' => $b->id, 'excused' => true],
            ['student_id' => $c->id, 'score' => 10],
        ])->assertOk()->json('data.entries');
        $this->assertCount(3, $entries);

        // Clearing a score deletes the row; un-excusing too.
        $this->putItemScores($item, [['student_id' => $c->id, 'score' => null], ['student_id' => $b->id, 'excused' => false]])->assertOk();
        $this->assertSame([$a->id], GradebookEntry::query()->where('gradebook_item_id', $item)->pluck('student_id')->all());

        $row = $this->rowOf($this->grid(), $c);
        $this->assertSame('missing', $row['cells']["i{$item}"]['state']);
        $this->assertEquals(0, $row['cells']["i{$item}"]['percent']);
    }

    public function test_bad_scores_are_refused_with_their_position(): void
    {
        $cats = $this->useTemplate();
        $item = $this->item('ความตั้งใจ', 10, $cats['จิตพิสัย']);
        $stranger = $this->enrollStudent($this->makeClassroom($this->teacher), 1)['student'];

        $this->putItemScores($item, [['student_id' => $this->students[0]->id, 'score' => 5], ['student_id' => $this->students[1]->id, 'score' => 10.5]])
            ->assertStatus(422)->assertJsonValidationErrors(['scores.1.score']);
        $this->putItemScores($item, [['student_id' => $this->students[0]->id, 'score' => -1]])->assertStatus(422)->assertJsonValidationErrors(['scores.0.score']);
        $this->putItemScores($item, [['student_id' => $this->students[0]->id, 'score' => 1.234]])->assertStatus(422)->assertJsonValidationErrors(['scores.0.score']);
        $this->putItemScores($item, [['student_id' => $this->students[0]->id, 'score' => 'สิบ']])->assertStatus(422)->assertJsonValidationErrors(['scores.0.score']);
        $this->putItemScores($item, [['student_id' => $stranger->id, 'score' => 5]])->assertStatus(422)->assertJsonValidationErrors(['scores.0.student_id']);
        $this->putItemScores($item, array_fill(0, 101, ['student_id' => 1]))->assertStatus(422);
        $this->assertSame(0, GradebookEntry::query()->count());
    }

    public function test_fill_full_leaves_typed_and_excused_cells_alone(): void
    {
        $cats = $this->useTemplate();
        $item = $this->item('การแต่งกาย', 10, $cats['จิตพิสัย']);
        [$a, $b, $c] = $this->students;
        $this->putItemScores($item, [['student_id' => $a->id, 'score' => 6], ['student_id' => $b->id, 'excused' => true]])->assertOk();

        $this->asUser($this->teacher)->postJson("/api/v1/gradebook-items/{$item}/fill-full")->assertOk()->assertJsonPath('data.filled', 1);

        $scores = GradebookEntry::query()->where('gradebook_item_id', $item)->get()->keyBy('student_id');
        $this->assertEquals(6, $scores[$a->id]->score);
        $this->assertNull($scores[$b->id]->score);
        $this->assertTrue($scores[$b->id]->excused);
        $this->assertEquals(10, $scores[$c->id]->score);
        $this->asUser($this->teacher)->postJson("/api/v1/gradebook-items/{$item}/fill-full")->assertOk()->assertJsonPath('data.filled', 0);
    }

    public function test_manual_exams_take_scores_and_app_graded_work_only_excused(): void
    {
        $cats = $this->useTemplate();
        $final = $this->manualExam('สอบปลายภาค', 50, $cats['ปลายภาค']);
        $homework = $this->appAssignment('การบ้าน', 10, $cats['การบ้าน']);
        $student = $this->students[0];

        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$final->id}/gradebook-scores", ['scores' => [['student_id' => $student->id, 'score' => 51]]])
            ->assertStatus(422)->assertJsonValidationErrors(['scores.0.score']);
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$final->id}/gradebook-scores", ['scores' => [['student_id' => $student->id, 'score' => 49.5]]])
            ->assertOk()->assertJsonPath('data.entries.0.score', 49.5);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$final->id}/gradebook-scores/fill-full")->assertOk()->assertJsonPath('data.filled', 2);

        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$homework->id}/gradebook-scores", ['scores' => [['student_id' => $student->id, 'score' => 5]]])
            ->assertStatus(422)->assertJsonPath('code', 'score_from_app');
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$homework->id}/gradebook-scores", ['scores' => [['student_id' => $student->id, 'score' => null]]])
            ->assertStatus(422)->assertJsonPath('code', 'score_from_app');
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$homework->id}/gradebook-scores/fill-full")
            ->assertStatus(422)->assertJsonPath('code', 'score_from_app');
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$homework->id}/gradebook-scores", ['scores' => [['student_id' => $student->id, 'excused' => true]]])
            ->assertOk()->assertJsonPath('data.entries.0.excused', true);
    }

    public function test_full_marks_must_be_positive_and_cannot_drop_below_a_typed_score(): void
    {
        $cats = $this->useTemplate();
        $url = "/api/v1/courses/{$this->course->id}/gradebook-items";
        $body = ['classroom_ids' => [$this->classroom->id], 'category_id' => $cats['จิตพิสัย']->id, 'name' => 'ความตั้งใจ'];
        $this->asUser($this->teacher)->postJson($url, $body + ['max_points' => 0])->assertStatus(422)->assertJsonValidationErrors(['max_points']);
        $this->asUser($this->teacher)->postJson($url, $body + ['max_points' => -5])->assertStatus(422)->assertJsonValidationErrors(['max_points']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบ', 'kind' => 'exam',
            'grading_method' => 'manual', 'manual_full_marks' => 0, 'due_at' => now()->toIso8601String(), 'gradebook_category_id' => $cats['ปลายภาค']->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['manual_full_marks']);

        $item = $this->item('ความตั้งใจ', 10, $cats['จิตพิสัย']);
        $this->putItemScores($item, [['student_id' => $this->students[0]->id, 'score' => 8]])->assertOk();
        $this->asUser($this->teacher)->patchJson("/api/v1/gradebook-items/{$item}", ['max_points' => 7])->assertStatus(422)->assertJsonValidationErrors(['max_points']);
        $this->asUser($this->teacher)->patchJson("/api/v1/gradebook-items/{$item}", ['max_points' => 0])->assertStatus(422)->assertJsonValidationErrors(['max_points']);
        $this->asUser($this->teacher)->patchJson("/api/v1/gradebook-items/{$item}", ['max_points' => 8, 'name' => 'ความตั้งใจเรียน', 'is_attendance' => true])
            ->assertOk()->assertJsonPath('data.name', 'ความตั้งใจเรียน')->assertJsonPath('data.is_attendance', true);
        $this->asUser($this->teacher)->deleteJson("/api/v1/gradebook-items/{$item}")->assertNoContent();
        $this->assertSame(0, GradebookEntry::query()->count());
    }

    public function test_items_are_created_for_several_classrooms_of_the_course(): void
    {
        $cats = $this->useTemplate();
        $second = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->course->classrooms()->attach($second->id);
        $unbound = $this->makeClassroom($this->teacher, ['name' => 'ป.6/1']);
        $url = "/api/v1/courses/{$this->course->id}/gradebook-items";
        $body = ['category_id' => $cats['จิตพิสัย']->id, 'name' => 'การแต่งกาย', 'max_points' => 10];

        $items = $this->asUser($this->teacher)->postJson($url, $body + ['classroom_ids' => [$this->classroom->id, $second->id]])->assertCreated()->json('data');
        $this->assertSame([$this->classroom->id, $second->id], array_column($items, 'classroom_id'));

        $this->asUser($this->teacher)->postJson($url, $body + ['classroom_ids' => [$unbound->id]])->assertStatus(422)->assertJsonValidationErrors(['classroom_ids.0']);
        $otherCourse = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ว15101']);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$otherCourse->id}/gradebook/categories", ['template' => 'collect_final'])->assertOk();
        $foreignCategory = $otherCourse->gradebookCategories()->first();
        $this->asUser($this->teacher)->postJson($url, ['category_id' => $foreignCategory->id, 'classroom_ids' => [$this->classroom->id]] + $body)
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    }

    public function test_app_graded_work_with_zero_full_marks_is_not_counted(): void
    {
        $cats = $this->useTemplate('collect_final');
        $mirror = $this->appAssignment('งาน mirror', 0, $cats['คะแนนเก็บ']);
        $this->published($mirror, $this->students[0], 3);

        $grid = $this->grid();
        $column = collect($grid['columns'])->firstWhere('key', "a{$mirror->id}");

        $this->assertFalse($column['counted']);
        $this->assertEquals(0, $column['full_marks']);
        $this->assertSame('not_counted', $this->rowOf($grid, $this->students[0])['cells']["a{$mirror->id}"]['state']);
        $this->assertNull($this->rowOf($grid, $this->students[0])['total']);
    }

    public function test_special_grades_and_the_attendance_warning_show_on_the_row(): void
    {
        $cats = $this->useTemplate('collect_final');
        $item = $this->item('การเข้าเรียน', 20, $cats['คะแนนเก็บ'], true);
        [$a, $b] = $this->students;
        $this->putItemScores($item, [['student_id' => $a->id, 'score' => 15], ['student_id' => $b->id, 'score' => 20]])->assertOk();

        $url = "/api/v1/courses/{$this->course->id}/gradebook/special-grades";
        $this->asUser($this->teacher)->putJson($url, ['classroom_id' => $this->classroom->id, 'student_id' => $a->id, 'special' => 'ms', 'note' => 'ขาดเรียนเกิน'])
            ->assertOk()->assertJsonPath('data.special', 'ms');
        $this->asUser($this->teacher)->putJson($url, ['classroom_id' => $this->classroom->id, 'student_id' => $b->id, 'special' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['special']);
        $stranger = $this->enrollStudent($this->makeClassroom($this->teacher), 1)['student'];
        $this->asUser($this->teacher)->putJson($url, ['classroom_id' => $this->classroom->id, 'student_id' => $stranger->id, 'special' => 'r'])
            ->assertStatus(422)->assertJsonValidationErrors(['student_id']);

        $grid = $this->grid();
        $row = $this->rowOf($grid, $a);
        $this->assertTrue($row['attendance_warning']);
        $this->assertSame('ms', $row['special']);
        $this->assertSame('ขาดเรียนเกิน', $row['special_note']);
        $this->assertFalse($this->rowOf($grid, $b)['attendance_warning']);

        $this->asUser($this->teacher)->putJson($url, ['classroom_id' => $this->classroom->id, 'student_id' => $a->id, 'special' => null])
            ->assertOk()->assertJsonPath('data.special', null);
        $this->assertNull($this->rowOf($this->grid(), $a)['special']);
    }

    public function test_a_student_who_left_the_course_is_flagged(): void
    {
        $this->useTemplate();
        DB::table('classroom_students')->where('student_id', $this->students[2]->id)->update(['left_course_at' => now()]);

        $this->assertTrue($this->rowOf($this->grid(), $this->students[2])['left_course']);
        $this->assertFalse($this->rowOf($this->grid(), $this->students[0])['left_course']);
    }

    public function test_a_pending_submission_waits_and_an_unbound_classroom_is_refused(): void
    {
        $cats = $this->useTemplate();
        $hw = $this->appAssignment('การบ้าน', 10, $cats['การบ้าน']);
        Submission::create(['assignment_id' => $hw->id, 'student_id' => $this->students[0]->id, 'status' => Submission::STATUS_NEEDS_REVIEW]);

        $this->assertSame('pending', $this->rowOf($this->grid(), $this->students[0])['cells']["a{$hw->id}"]['state']);

        $unbound = $this->makeClassroom($this->teacher);
        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/gradebook?classroom_id={$unbound->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/gradebook")
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
    }
}
