<?php

namespace Tests\Feature\Gradebook;

use App\Models\GradebookCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** DESIGN §23.9, §23.11 GET /gradebook/overview: where each classroom of each own course stands in the grading flow. */
class GradebookOverviewTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld();
    }

    public function test_a_course_without_categories_is_not_configured(): void
    {
        $course = $this->overview()->assertOk()->json('data.courses.0');

        $this->assertSame([
            'id' => $this->course->id,
            'code' => 'ค15101',
            'name' => 'คณิตศาสตร์ 5',
            'grade_level' => 5,
            'semester' => 0,
            'academic_year' => 2569,
            'configured' => false,
            'category_count' => 0,
        ], array_diff_key($course, ['classrooms' => true]));
        $this->assertSame([[
            'id' => $this->classroom->id,
            'name' => 'ป.5/1',
            'student_count' => 3,
            'status' => 'not_configured',
            'empty_categories' => [],
            'published_at' => null,
            'stale' => false,
            'at_risk_ms_count' => 0,
            'special_counts' => ['ร' => 0, 'มส' => 0],
        ]], $course['classrooms']);
    }

    public function test_a_classroom_misses_scores_until_every_category_has_a_counted_item(): void
    {
        $cats = $this->useTemplate();
        $this->scoreAll($cats['การบ้าน']);
        $this->scoreAll($cats['จิตพิสัย']);

        $course = $this->overview()->json('data.courses.0');
        $this->assertTrue($course['configured']);
        $this->assertSame(4, $course['category_count']);
        $room = $course['classrooms'][0];
        $this->assertSame('missing_scores', $room['status']);
        $this->assertSame(['กลางภาค', 'ปลายภาค'], $room['empty_categories']);
        $this->assertSame($this->grid()['missing_categories'], $room['empty_categories']);
    }

    public function test_a_complete_classroom_is_ready_then_published_then_stale(): void
    {
        $cats = $this->useTemplate('collect_final');
        $itemId = $this->scoreAll($cats['คะแนนเก็บ']);
        $this->scoreAll($cats['ปลายภาค']);
        $this->assertSame('ready', $this->room()['status']);
        $this->assertSame([], $this->room()['empty_categories']);

        $publishedAt = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook/publish", ['classroom_id' => $this->classroom->id])
            ->assertCreated()->json('data.published_at');
        $room = $this->room();
        $this->assertSame('published', $room['status']);
        $this->assertSame($publishedAt, $room['published_at']);
        $this->assertFalse($room['stale']);

        $this->putItemScores($itemId, [['student_id' => $this->students[0]->id, 'score' => 2]])->assertOk();
        $room = $this->room();
        $this->assertSame('published_stale', $room['status']);
        $this->assertTrue($room['stale']);
        $this->assertSame($this->grid()['publication']['stale'], $room['stale']);

        // Withdrawn: back to ready.
        $this->asUser($this->teacher)->deleteJson("/api/v1/courses/{$this->course->id}/gradebook/publish?classroom_id={$this->classroom->id}")->assertNoContent();
        $this->assertSame('ready', $this->room()['status']);
        $this->assertNull($this->room()['published_at']);
    }

    public function test_it_counts_attendance_warnings_and_special_grades_as_the_grid_does(): void
    {
        $cats = $this->useTemplate('collect_final');
        [$a, $b, $c] = $this->students;
        $attendance = $this->item('เวลาเรียน', 10, $cats['คะแนนเก็บ'], true);
        $this->putItemScores($attendance, [
            ['student_id' => $a->id, 'score' => 10],
            ['student_id' => $b->id, 'score' => 5],
            ['student_id' => $c->id, 'score' => 7],
        ])->assertOk();
        foreach ([[$a, 'r'], [$b, 'ms'], [$c, 'ms']] as [$student, $special]) {
            $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/special-grades", [
                'classroom_id' => $this->classroom->id, 'student_id' => $student->id, 'special' => $special,
            ])->assertOk();
        }

        $room = $this->room();
        $grid = $this->grid();
        $this->assertSame(2, $room['at_risk_ms_count']);
        $this->assertSame(count(array_filter(array_column($grid['rows'], 'attendance_warning'))), $room['at_risk_ms_count']);
        $this->assertSame(['ร' => 1, 'มส' => 2], $room['special_counts']);
        $this->assertSame('missing_scores', $room['status']);
    }

    public function test_every_bound_classroom_of_every_own_course_is_listed_newest_year_first(): void
    {
        $second = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->enrollStudent($second, 1, 'นักเรียนห้องสอง');
        $this->course->classrooms()->attach($second->id);
        $older = $this->makeCourse($this->teacher, [$second], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5', 'academic_year' => 2568, 'semester' => 2]);
        $this->makeCourse($this->teacher, [], ['code' => 'ส15101', 'name' => 'สังคมศึกษา 5', 'academic_year' => 2568, 'semester' => 1]);

        $courses = $this->overview()->assertOk()->json('data.courses');

        $this->assertSame(['ค15101', 'ว15101', 'ส15101'], array_column($courses, 'code'));
        $this->assertSame(['ป.5/1', 'ป.5/2'], array_column($courses[0]['classrooms'], 'name'));
        $this->assertSame([3, 1], array_column($courses[0]['classrooms'], 'student_count'));
        $this->assertSame($older->id, $courses[1]['id']);
        $this->assertSame([$second->id], array_column($courses[1]['classrooms'], 'id'));
        $this->assertSame([], $courses[2]['classrooms']);
    }

    public function test_it_filters_by_academic_year_and_semester(): void
    {
        $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ว15101', 'academic_year' => 2568, 'semester' => 1]);
        $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ส15101', 'academic_year' => 2568, 'semester' => 2]);

        $this->assertSame(['ค15101'], array_column($this->overview('?academic_year=2569')->json('data.courses'), 'code'));
        $this->assertSame(['ส15101', 'ว15101'], array_column($this->overview('?academic_year=2568')->json('data.courses'), 'code'));
        $this->assertSame(['ว15101'], array_column($this->overview('?academic_year=2568&semester=1')->json('data.courses'), 'code'));
        $this->assertSame(['ค15101'], array_column($this->overview('?semester=0')->json('data.courses'), 'code'));
        $this->assertSame([], $this->overview('?academic_year=2570')->assertOk()->json('data.courses'));

        $this->overview('?semester=3')->assertStatus(422)->assertJsonValidationErrors(['semester']);
        $this->overview('?academic_year=69')->assertStatus(422)->assertJsonValidationErrors(['academic_year']);
    }

    public function test_another_teachers_courses_and_classrooms_never_appear(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirRoom = $this->makeClassroom($colleague, ['name' => 'ป.6/1']);
        $theirs = $this->makeCourse($colleague, [$theirRoom], ['code' => 'ค16101']);
        // A colleague's classroom bound to the teacher's course behind the API's back stays hidden.
        $this->course->classrooms()->attach($theirRoom->id);
        $stranger = $this->makeTeacher();
        $this->makeCourse($stranger, [$this->makeClassroom($stranger)], ['code' => 'ค16102']);

        $courses = $this->overview()->json('data.courses');
        $this->assertSame([$this->course->id], array_column($courses, 'id'));
        $this->assertSame([$this->classroom->id], array_column($courses[0]['classrooms'], 'id'));

        $mine = $this->asUser($colleague)->getJson('/api/v1/gradebook/overview')->assertOk()->json('data.courses');
        $this->assertSame([$theirs->id], array_column($mine, 'id'));
        $this->assertSame([$theirRoom->id], array_column($mine[0]['classrooms'], 'id'));

        $this->asUser($this->students[0])->getJson('/api/v1/gradebook/overview')->assertForbidden();
    }

    public function test_the_query_count_does_not_grow_with_courses_classrooms_or_students(): void
    {
        $cats = $this->useTemplate('collect_final');
        $this->scoreAll($cats['คะแนนเก็บ']);
        $one = $this->countQueries();

        for ($i = 2; $i <= 4; $i++) {
            $room = $this->makeClassroom($this->teacher, ['name' => "ป.5/{$i}"]);
            for ($n = 1; $n <= 3; $n++) {
                $this->enrollStudent($room, $n, "นักเรียน {$i}-{$n}");
            }
            $this->course->classrooms()->attach($room->id);
            $course = $this->makeCourse($this->teacher, [$room, $this->classroom], ['code' => "ค1510{$i}"]);
            $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/gradebook/categories", ['template' => 'collect_final'])->assertOk();
            $this->asUser($this->teacher)->postJson("/api/v1/courses/{$course->id}/gradebook-items", [
                'classroom_ids' => [$room->id, $this->classroom->id], 'category_id' => $course->gradebookCategories()->first()->id, 'name' => 'งาน', 'max_points' => 10,
            ])->assertCreated();
        }
        $many = $this->countQueries();

        $this->assertCount(4, $this->overview()->json('data.courses'));
        $this->assertSame($one, $many);
    }

    private function overview(string $query = ''): TestResponse
    {
        return $this->asUser($this->teacher)->getJson('/api/v1/gradebook/overview'.$query);
    }

    /** @return array<string, mixed> the world classroom's row of the world course */
    private function room(): array
    {
        $courses = $this->overview()->assertOk()->json('data.courses');
        $course = collect($courses)->firstWhere('id', $this->course->id);

        return collect($course['classrooms'])->firstWhere('id', $this->classroom->id);
    }

    private function scoreAll(GradebookCategory $category): int
    {
        $id = $this->item('รายการ '.$category->name, 10, $category);
        $this->putItemScores($id, array_map(fn ($s) => ['student_id' => $s->id, 'score' => 8], $this->students))->assertOk();

        return $id;
    }

    private function countQueries(): int
    {
        $request = $this->asUser($this->teacher);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request->getJson('/api/v1/gradebook/overview')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
