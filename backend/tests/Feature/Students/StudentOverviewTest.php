<?php

namespace Tests\Feature\Students;

use App\Domain\Classrooms\StudentEnroller;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\StudentAnalysis;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The student's combined view (DESIGN §24.11, build 5): one account in two
 * classrooms sees the work, results, grades and charts of both, grouped by
 * course with a classroom label; the closed classroom is read-only, older
 * work without a course has its own group, and nothing of a classmate shows.
 */
class StudentOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $classmate;

    /** Open, 2569: the homeroom teacher's math course and a subject teacher's science course. */
    private Classroom $open;

    /** Closed ("ห้องเก่า"), 2568: a Thai course and older work without a course. */
    private Classroom $closed;

    private Course $math;

    private Course $science;

    private Course $thai;

    private Subject $art;

    private Assignment $mathTodo;

    private Assignment $scienceDone;

    private Assignment $closedTodo;

    private Submission $mathResult;

    private Submission $legacyResult;

    protected function setUp(): void
    {
        parent::setUp();
        $homeroom = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น']);
        $school = $homeroom->school;
        $subjectTeacher = $this->makeTeacher($school, ['name' => 'ครูวิทย์']);
        $oldHomeroom = $this->makeTeacher($school, ['name' => 'ครูปีก่อน']);

        $this->closed = $this->makeClassroom($oldHomeroom, ['name' => 'ป.4/1', 'grade_level' => 4, 'academic_year' => 2568]);
        $this->open = $this->makeClassroom($homeroom, ['name' => 'ป.5/1', 'grade_level' => 5, 'academic_year' => 2569]);
        $this->student = $this->enrollStudent($this->closed, 3, 'เด็กหญิง ใจดี มีสุข')['student'];
        app(StudentEnroller::class)->enroll($this->open, [['student_id' => $this->student->id, 'student_number' => 7]]);
        $this->classmate = $this->enrollStudent($this->open, 1, 'เด็กชาย เพื่อน ร่วมห้อง')['student'];

        $science = Subject::query()->create(['code' => 'ว', 'name' => 'วิทยาศาสตร์']);
        $thaiSubject = Subject::query()->create(['code' => 'ท', 'name' => 'ภาษาไทย']);
        $this->art = Subject::query()->create(['code' => 'ศ', 'name' => 'ศิลปะ']);
        $this->math = $this->makeCourse($homeroom, [$this->open], ['code' => 'ค15101', 'name' => 'คณิตศาสตร์ 5']);
        $this->science = $this->makeCourse($subjectTeacher, [$this->open], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5', 'subject_id' => $science->id]);
        $this->thai = $this->makeCourse($oldHomeroom, [$this->closed], ['code' => 'ท14101', 'name' => 'ภาษาไทย 4', 'subject_id' => $thaiSubject->id, 'academic_year' => 2568]);

        $this->mathTodo = $this->assignment($this->open, $this->math, ['title' => 'เศษส่วน', 'due_at' => now()->addDay()]);
        $mathDone = $this->assignment($this->open, $this->math, ['title' => 'ทศนิยม']);
        $this->scienceDone = $this->assignment($this->open, $this->science, ['title' => 'พืช', 'created_by' => $subjectTeacher->id]);
        // Past due and refusing late work: nothing left to do.
        $this->assignment($this->open, $this->math, ['title' => 'เลยกำหนด', 'due_at' => now()->subDay(), 'accept_late' => false]);
        $this->assignment($this->open, $this->math, ['title' => 'ร่าง', 'status' => Assignment::STATUS_DRAFT]);
        $this->closedTodo = $this->assignment($this->closed, $this->thai, ['title' => 'เรียงความ']);
        $legacy = $this->assignment($this->closed, null, ['title' => 'วาดภาพ', 'subject_id' => $this->art->id]);

        $this->mathResult = $this->published($mathDone, $this->student, now()->subHour());
        Submission::create(['assignment_id' => $this->scienceDone->id, 'student_id' => $this->student->id, 'status' => Submission::STATUS_REVIEWED]);
        $this->legacyResult = $this->published($legacy, $this->student, now()->subYear());
        // A classmate's work never counts for the student.
        $this->published($this->mathTodo, $this->classmate, now());

        $this->closeClassroom($this->closed, $oldHomeroom);
    }

    public function test_the_overview_groups_every_classroom_by_course_with_labels(): void
    {
        $this->grade($this->thai, $this->closed, $this->student, 3.5);
        $this->grade($this->math, $this->open, $this->classmate, 4);

        $data = $this->asUser($this->student)->getJson('/api/v1/student/overview')->assertOk()->json('data');

        $this->assertSame([
            ['id' => $this->open->id, 'name' => 'ป.5/1', 'academic_year' => 2569, 'closed' => false],
            ['id' => $this->closed->id, 'name' => 'ป.4/1', 'academic_year' => 2568, 'closed' => true],
        ], $data['classrooms']);

        $groups = $data['groups'];
        $this->assertSame(['ค15101', 'ว15101', 'ท14101', null], array_map(fn ($g) => $g['course']['code'] ?? null, $groups), 'open classrooms first, then by course code; work without a course last');
        $this->assertSame(['course', 'subject', 'classroom', 'teacher_name', 'todo_count', 'results_count', 'latest_published_at', 'grade'], array_keys($groups[0]));

        [$math, $science, $thai, $legacy] = $groups;
        $this->assertSame(['id' => $this->math->id, 'code' => 'ค15101', 'name' => 'คณิตศาสตร์ 5'], $math['course']);
        $this->assertSame('คณิตศาสตร์', $math['subject']['name']);
        $this->assertSame($this->open->id, $math['classroom']['id']);
        $this->assertSame('ครูประจำชั้น', $math['teacher_name']);
        $this->assertSame([1, 1], [$math['todo_count'], $math['results_count']], 'the classmate\'s result is not the student\'s');
        $this->assertSame($this->mathResult->published_at->toIso8601String(), $math['latest_published_at']);
        $this->assertNull($math['grade'], 'the classmate\'s grade is not the student\'s');

        $this->assertSame('ครูวิทย์', $science['teacher_name']);
        $this->assertSame([0, 0, null], [$science['todo_count'], $science['results_count'], $science['latest_published_at']], 'handed in, not yet published');

        $this->assertTrue($thai['classroom']['closed']);
        $this->assertSame(0, $thai['todo_count'], 'a closed classroom takes no hand-in');
        $this->assertSame(['grade' => 3.5, 'special' => null], $thai['grade']);
        $this->assertSame('ครูปีก่อน', $thai['teacher_name']);

        $this->assertNull($legacy['course']);
        $this->assertSame(['id' => $this->art->id, 'code' => 'ศ', 'name' => 'ศิลปะ'], $legacy['subject']);
        $this->assertSame($this->closed->id, $legacy['classroom']['id']);
        $this->assertSame('ครูปีก่อน', $legacy['teacher_name'], 'older work belongs to the homeroom teacher');
        $this->assertSame(1, $legacy['results_count']);

        // The classmate sees their own classroom only, with their own counts.
        $theirs = $this->asUser($this->classmate)->getJson('/api/v1/student/overview')->assertOk()->json('data');
        $this->assertSame([$this->open->id], array_column($theirs['classrooms'], 'id'));
        $this->assertSame(['ค15101', 'ว15101'], array_column(array_column($theirs['groups'], 'course'), 'code'));
        $this->assertSame([1, 1], [$theirs['groups'][0]['todo_count'], $theirs['groups'][0]['results_count']], 'their own: ทศนิยม to do, เศษส่วน published');
        $this->assertEquals(['grade' => 4, 'special' => null], $theirs['groups'][0]['grade']);
    }

    public function test_a_student_without_classrooms_has_an_empty_overview(): void
    {
        $alone = User::factory()->student($this->student->school)->create();

        $this->asUser($alone)->getJson('/api/v1/student/overview')->assertOk()->assertExactJson(['data' => ['classrooms' => [], 'groups' => []]]);
    }

    public function test_assignments_carry_course_and_classroom_and_the_closed_classroom_is_read_only(): void
    {
        $rows = collect($this->asUser($this->student)->getJson('/api/v1/student/assignments')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows->has($this->closedTodo->id), 'the closed classroom stays readable');
        $this->assertSame(['id' => $this->closed->id, 'name' => 'ป.4/1', 'academic_year' => 2568, 'closed' => true], $rows[$this->closedTodo->id]['classroom']);
        $this->assertSame(['id' => $this->thai->id, 'code' => 'ท14101', 'name' => 'ภาษาไทย 4'], $rows[$this->closedTodo->id]['course']);
        $this->assertFalse($rows[$this->closedTodo->id]['can_submit']);
        $this->assertTrue($rows[$this->mathTodo->id]['can_submit']);
        $this->assertSame('submitted', $rows[$this->scienceDone->id]['status']);
        $this->asUser($this->student)->postJson("/api/v1/student/assignments/{$this->closedTodo->id}/submission")
            ->assertStatus(409)->assertJsonPath('code', 'classroom_closed');

        $this->assertSame([$this->legacyResult->assignment_id, $this->closedTodo->id], $this->ids("/api/v1/student/assignments?classroom_id={$this->closed->id}"));
        $this->assertSame([$this->scienceDone->id], $this->ids("/api/v1/student/assignments?course_id={$this->science->id}"));
        $this->assertSame([], $this->ids("/api/v1/student/assignments?course_id={$this->science->id}&classroom_id={$this->closed->id}"));
        // Another classroom's id matches nothing rather than revealing it.
        $elsewhere = $this->makeClassroom($this->makeTeacher($this->student->school));
        $this->assertSame([], $this->ids("/api/v1/student/assignments?classroom_id={$elsewhere->id}"));
        $this->asUser($this->student)->getJson('/api/v1/student/assignments?course_id=abc')
            ->assertStatus(422)->assertJsonValidationErrors(['course_id']);
    }

    public function test_results_carry_course_and_classroom_and_filter(): void
    {
        $rows = $this->asUser($this->student)->getJson('/api/v1/student/results')->assertOk()->json('data');
        $this->assertSame([$this->mathResult->id, $this->legacyResult->id], array_column($rows, 'id'));
        $this->assertSame(['id' => $this->math->id, 'code' => 'ค15101', 'name' => 'คณิตศาสตร์ 5'], $rows[0]['course']);
        $this->assertSame(['id' => $this->open->id, 'name' => 'ป.5/1', 'academic_year' => 2569, 'closed' => false], $rows[0]['classroom']);
        $this->assertNull($rows[1]['course']);
        $this->assertTrue($rows[1]['classroom']['closed']);

        $this->assertSame([$this->legacyResult->id], $this->ids("/api/v1/student/results?classroom_id={$this->closed->id}"));
        $this->assertSame([$this->mathResult->id], $this->ids("/api/v1/student/results?course_id={$this->math->id}"));
        $this->asUser($this->student)->getJson("/api/v1/student/results/{$this->legacyResult->id}")
            ->assertOk()->assertJsonPath('data.classroom.closed', true)->assertJsonPath('data.course', null);
    }

    public function test_grades_and_courses_cover_every_classroom(): void
    {
        $this->grade($this->thai, $this->closed, $this->student, 3.5);
        $this->grade($this->math, $this->open, $this->student, 2);

        $grades = $this->asUser($this->student)->getJson('/api/v1/student/grades')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['ค15101', 'ท14101'], array_column(array_column($grades, 'course'), 'code'));
        $thai = collect($grades)->firstWhere('course.code', 'ท14101');
        $this->assertSame(['id' => $this->closed->id, 'name' => 'ป.4/1', 'academic_year' => 2568, 'closed' => true], $thai['classroom']);
        $this->assertSame($this->closed->id, $thai['classroom_id']);
        $this->assertSame(['ท14101'], array_column(array_column($this->asUser($this->student)->getJson("/api/v1/student/grades?classroom_id={$this->closed->id}")->json('data'), 'course'), 'code'));
        $this->asUser($this->student)->getJson("/api/v1/student/courses/{$this->thai->id}/grade")
            ->assertOk()->assertJsonPath('data.classroom.closed', true)->assertJsonPath('data.grade', 3.5);
        $this->asUser($this->student)->getJson("/api/v1/student/courses/{$this->thai->id}/grade?classroom_id={$this->open->id}")->assertNotFound();

        $courses = collect($this->asUser($this->student)->getJson('/api/v1/student/courses')->assertOk()->json('data'))->keyBy('code');
        $this->assertEqualsCanonicalizing(['ค15101', 'ว15101', 'ท14101'], $courses->keys()->all());
        $this->assertSame(['id' => $this->closed->id, 'name' => 'ป.4/1', 'academic_year' => 2568, 'closed' => true], $courses['ท14101']['classroom']);
        $this->assertSame([$this->closed->id], array_column($courses['ท14101']['classrooms'], 'id'));
        $this->assertSame([$this->open->id], $courses['ค15101']['classroom_ids']);
        $this->assertSame(['ท14101'], array_column($this->asUser($this->student)->getJson("/api/v1/student/courses?classroom_id={$this->closed->id}")->json('data'), 'code'));
        $this->assertSame(['ว15101'], array_column($this->asUser($this->student)->getJson("/api/v1/student/courses?course_id={$this->science->id}")->json('data'), 'code'));
        // The roll-up of a course of the closed classroom still reads.
        $this->asUser($this->student)->getJson("/api/v1/student/courses/{$this->thai->id}/mastery-summary")->assertOk();
    }

    public function test_mastery_runs_on_across_classrooms_and_filters_by_course_or_classroom(): void
    {
        $math = Skill::factory()->create(['subject_id' => $this->math->subject_id, 'code' => 'ค 1.1 ป.5/1']);
        $thai = Skill::factory()->create(['subject_id' => $this->thai->subject_id, 'code' => 'ท 1.1 ป.4/1']);
        $art = Skill::factory()->create(['subject_id' => $this->art->id, 'code' => 'ศ 1.1 ป.4/1']);
        $this->math->indicators()->attach($math->id);
        $this->thai->indicators()->attach($thai->id);
        // Older work without a course assesses the art indicator through its question.
        $legacyQuestion = $this->legacyResult->assignment->questions()->create(['position' => 1, 'type' => 'short_answer', 'max_points' => 1, 'prompt_text' => 'วาด']);
        $legacyQuestion->skills()->attach($art->id);
        foreach ([[$math, 0.9], [$thai, 0.4], [$art, 0.6]] as [$skill, $value]) {
            Mastery::create(['student_id' => $this->student->id, 'skill_id' => $skill->id, 'value' => $value, 'n_obs' => 3]);
        }
        Mastery::create(['student_id' => $this->classmate->id, 'skill_id' => $math->id, 'value' => 0.1, 'n_obs' => 3]);

        $skills = fn (string $query) => array_column($this->asUser($this->student)->getJson('/api/v1/student/mastery'.$query)->assertOk()->json('data'), 'skill_id');
        $this->assertSame([$thai->id, $art->id, $math->id], $skills(''), 'one account: every classroom, weakest first');
        $this->assertSame([$math->id], $skills("?course_id={$this->math->id}"));
        $this->assertSame([$thai->id, $art->id], $skills("?classroom_id={$this->closed->id}"));
        $this->assertSame([$thai->id], $skills("?course_id={$this->thai->id}&classroom_id={$this->closed->id}"));
        $this->assertSame([], $skills("?course_id={$this->thai->id}&classroom_id={$this->open->id}"));
        $this->assertSame([$thai->id], $this->asUser($this->student)->getJson("/api/v1/student/mastery?course_id={$this->thai->id}")->json('meta.weaknesses'));
        // A course of a classroom the student is not in matches nothing.
        $foreign = $this->makeCourse($this->makeTeacher($this->student->school), [], ['code' => 'ค99999']);
        $foreign->indicators()->attach($math->id);
        $this->assertSame([], $skills("?course_id={$foreign->id}"));
        $this->assertSame([$math->id], array_column($this->asUser($this->classmate)->getJson('/api/v1/student/mastery')->json('data'), 'skill_id'));
    }

    public function test_analyses_list_every_classroom_and_the_single_one_is_the_open_classroom(): void
    {
        foreach ([[$this->closed, 'ปีก่อนเก่งมาก'], [$this->open, 'ปีนี้ดีขึ้น']] as [$room, $text]) {
            StudentAnalysis::create([
                'student_id' => $this->student->id, 'classroom_id' => $room->id,
                'computed_input_hash' => str_repeat('c', 64), 'strengths' => [], 'areas' => [],
                'status' => StudentAnalysis::STATUS_DRAFTED, 'teacher_text' => 'ครู', 'student_text' => $text,
            ])->forceFill(['shared_student_text' => $text, 'shared_at' => now()])->save();
        }

        $all = $this->asUser($this->student)->getJson('/api/v1/student/analyses')->assertOk()->json('data');
        $this->assertSame(['ปีนี้ดีขึ้น', 'ปีก่อนเก่งมาก'], array_column($all, 'text'));
        $this->assertSame(['id' => $this->closed->id, 'name' => 'ป.4/1', 'academic_year' => 2568, 'closed' => true], $all[1]['classroom']);
        $this->assertSame(['ปีก่อนเก่งมาก'], array_column($this->asUser($this->student)->getJson("/api/v1/student/analyses?classroom_id={$this->closed->id}")->json('data'), 'text'));

        $one = $this->asUser($this->student)->getJson('/api/v1/student/analysis')->assertOk()->json('data');
        $this->assertSame(['ปีนี้ดีขึ้น'], array_column($one, 'text'));

        // Without an open classroom's text the newest closed one shows.
        StudentAnalysis::query()->where('classroom_id', $this->open->id)->delete();
        $this->assertSame(['ปีก่อนเก่งมาก'], array_column($this->asUser($this->student)->getJson('/api/v1/student/analysis')->json('data'), 'text'));
        $this->asUser($this->classmate)->getJson('/api/v1/student/analyses')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignment(Classroom $classroom, ?Course $course, array $attributes = []): Assignment
    {
        return Assignment::factory()->for_classroom($classroom)->create($attributes + [
            'status' => Assignment::STATUS_READY,
            'course_id' => $course?->id,
            'subject_id' => $course?->subject_id ?? $this->art->id,
        ]);
    }

    private function published(Assignment $assignment, User $student, mixed $at): Submission
    {
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'status' => Submission::STATUS_PUBLISHED]);
        $submission->forceFill(['published_at' => $at, 'total_score' => 1])->save();

        return $submission->refresh();
    }

    private function grade(Course $course, Classroom $classroom, User $student, float $grade): void
    {
        $publication = GradebookPublication::create(['course_id' => $course->id, 'classroom_id' => $classroom->id, 'categories' => [], 'cutoffs' => [], 'published_by' => $course->created_by, 'published_at' => now()]);
        GradebookPublishedGrade::create(['publication_id' => $publication->id, 'student_id' => $student->id, 'breakdown' => [], 'total' => 70, 'total_rounded' => 70, 'grade' => $grade]);
    }

    private function closeClassroom(Classroom $classroom, User $by): void
    {
        $classroom->forceFill(['closed_at' => now(), 'closed_by' => $by->id])->save();
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_column($this->asUser($this->student)->getJson($url)->assertOk()->json('data'), 'id');
    }
}
