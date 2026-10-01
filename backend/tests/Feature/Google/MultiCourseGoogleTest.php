<?php

namespace Tests\Feature\Google;

use App\Domain\Google\CourseWorkImporter;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Several Google Classroom courses per classroom, one per teacher (DESIGN
 * §24.3 D, §24.10, §24.16 build 4): the homeroom teacher's course changes
 * the roster, a subject teacher's only matches, a student is "ไม่อยู่ใน
 * Classroom แล้ว" only when gone from every course, and work goes through
 * the course of the teacher who manages it.
 */
class MultiCourseGoogleTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const SUBJECT_COURSE = '612000000002';

    private User $homeroom;

    private User $subjectTeacher;

    private Classroom $room;

    private Course $homeCourse;

    private Course $science;

    /** @var array<int, User> by student number */
    private array $students = [];

    /** @var array<string, list<array<string, mixed>>|null> course id => roster (null: Google answers 503) */
    private array $rosters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->homeroom = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น']);
        $this->subjectTeacher = $this->makeTeacher($this->homeroom->school, ['name' => 'ครูวิทย์']);
        $this->connectGoogle($this->homeroom);
        $this->connectGoogle($this->subjectTeacher);
        $this->room = $this->makeClassroom($this->homeroom, ['name' => 'ป.5/1']);
        $this->homeCourse = $this->makeCourse($this->homeroom, [$this->room], ['code' => 'ค15101']);
        $this->science = $this->makeCourse($this->subjectTeacher, [$this->room], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5']);
        foreach ([1 => 'ด.ช. หนึ่ง ใจดี', 2 => 'ด.ญ. สอง มีสุข', 3 => 'ด.ช. สาม รักเรียน'] as $n => $name) {
            $this->students[$n] = $this->enrollStudent($this->room, $n, $name)['student'];
            ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_number', $n)
                ->update(['google_user_id' => "g-{$n}", 'google_email' => "s{$n}@student.example"]);
        }
        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/students*' => fn () => $this->answer(self::COURSE_ID),
            'classroom.googleapis.com/v1/courses/'.self::SUBJECT_COURSE.'/students*' => fn () => $this->answer(self::SUBJECT_COURSE),
            'classroom.googleapis.com/v1/courses?*' => Http::response(['courses' => [
                ['id' => self::COURSE_ID, 'name' => 'คณิตศาสตร์', 'courseState' => 'ACTIVE'],
                ['id' => self::SUBJECT_COURSE, 'name' => 'วิทยาศาสตร์', 'courseState' => 'ACTIVE'],
            ]]),
        ]);
    }

    private function answer(string $course): mixed
    {
        $roster = array_key_exists($course, $this->rosters) ? $this->rosters[$course] : [];

        return $roster === null
            ? Http::response(self::googleError(503, 'UNAVAILABLE', 'The service is currently unavailable.'), 503)
            : Http::response(['students' => $roster]);
    }

    /** @return list<array<string, mixed>> */
    private static function accounts(int ...$numbers): array
    {
        return array_map(fn (int $n) => self::courseStudent("g-{$n}", "นักเรียน {$n}", "s{$n}@student.example"), $numbers);
    }

    private function linkBoth(): void
    {
        ClassroomGoogleLink::create(['classroom_id' => $this->room->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิตศาสตร์', 'owner_user_id' => $this->homeroom->id, 'app_course_id' => $this->homeCourse->id, 'linked_at' => now()]);
        ClassroomGoogleLink::create(['classroom_id' => $this->room->id, 'course_id' => self::SUBJECT_COURSE, 'course_name' => 'วิทยาศาสตร์', 'owner_user_id' => $this->subjectTeacher->id, 'app_course_id' => $this->science->id, 'linked_at' => now()]);
    }

    private function member(int $number): ClassroomStudent
    {
        return ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_number', $number)->firstOrFail();
    }

    public function test_each_teacher_links_their_own_course_to_the_same_classroom(): void
    {
        $this->asUser($this->homeroom)->postJson("/api/v1/classrooms/{$this->room->id}/google-link", ['course_id' => self::COURSE_ID])
            ->assertCreated()->assertJsonPath('data.app_course_id', $this->homeCourse->id);
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/classrooms/{$this->room->id}/google-link", ['course_id' => self::COURSE_ID])
            ->assertStatus(409)->assertJsonPath('code', 'course_already_linked');
        $this->asUser($this->subjectTeacher)->postJson("/api/v1/classrooms/{$this->room->id}/google-link", ['course_id' => self::SUBJECT_COURSE])
            ->assertCreated()
            ->assertJsonPath('data.owner_user_id', $this->subjectTeacher->id)
            ->assertJsonPath('data.app_course_id', $this->science->id);

        // google_link is the viewer's own course; google_links lists the homeroom teacher every course.
        $this->asUser($this->homeroom)->getJson("/api/v1/classrooms/{$this->room->id}")->assertOk()
            ->assertJsonPath('data.google_link.course_id', self::COURSE_ID)
            ->assertJsonCount(2, 'data.google_links')
            ->assertJsonPath('data.google_links.1.owner.name', 'ครูวิทย์')
            ->assertJsonPath('data.google_links.1.mine', false);
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->room->id}")->assertOk()
            ->assertJsonPath('data.google_link.course_id', self::SUBJECT_COURSE)
            ->assertJsonCount(1, 'data.google_links');
        $courses = collect($this->asUser($this->subjectTeacher)->getJson('/api/v1/google/courses')->assertOk()->json('data'))->keyBy('course_id');
        $this->assertSame(['id' => $this->room->id, 'name' => 'ป.5/1'], $courses[self::SUBJECT_COURSE]['linked_classroom']);

        // Unlinking takes only the caller's course.
        $this->asUser($this->subjectTeacher)->deleteJson("/api/v1/classrooms/{$this->room->id}/google-link")->assertNoContent();
        $this->assertSame([$this->homeroom->id], ClassroomGoogleLink::query()->where('classroom_id', $this->room->id)->pluck('owner_user_id')->all());
    }

    public function test_a_subject_teacher_with_several_courses_names_the_app_course(): void
    {
        $physics = $this->makeCourse($this->subjectTeacher, [$this->room], ['code' => 'ว15102']);
        $url = "/api/v1/classrooms/{$this->room->id}/google-link";

        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => self::SUBJECT_COURSE])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['app_course_id']]);
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => self::SUBJECT_COURSE, 'app_course_id' => $this->homeCourse->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['app_course_id']]);
        $this->asUser($this->subjectTeacher)->postJson($url, ['course_id' => self::SUBJECT_COURSE, 'app_course_id' => $physics->id])
            ->assertCreated()->assertJsonPath('data.app_course_id', $physics->id);
    }

    public function test_a_subject_teachers_sync_only_matches_and_reports_who_is_not_in_the_classroom(): void
    {
        $this->linkBoth();
        $this->rosters[self::COURSE_ID] = self::accounts(1, 2, 3);
        // Student 2 is not in the science course; student 3 lost their match; an outsider is.
        ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_number', 3)->update(['google_user_id' => null]);
        $this->rosters[self::SUBJECT_COURSE] = [...self::accounts(1, 3), self::courseStudent('g-out', 'คนนอก ห้อง', 'out@student.example')];
        $users = User::query()->count();

        $data = $this->asUser($this->subjectTeacher)->postJson("/api/v1/classrooms/{$this->room->id}/google-roster/sync")->assertOk()->json('data');

        $this->assertSame([], $data['added']);
        $this->assertSame([], $data['enrolled']);
        $this->assertSame([], $data['left'], 'student 2 is still in the homeroom course');
        $this->assertSame([$this->students[3]->id], array_column($data['rematched'], 'student_id'));
        $this->assertSame([['google_user_id' => 'g-out', 'name' => 'คนนอก ห้อง', 'email' => 'out@student.example']], $data['not_in_classroom']);
        $this->assertSame($users, User::query()->count());
        $this->assertSame('g-2', $this->member(2)->google_user_id);
        $this->assertNull($this->member(2)->left_course_at);

        // Matching by hand stays the homeroom teacher's; reading the course roster does not.
        $this->asUser($this->subjectTeacher)->getJson("/api/v1/classrooms/{$this->room->id}/google-roster")->assertOk()
            ->assertJsonPath('meta.course_id', self::SUBJECT_COURSE);
        $this->asUser($this->subjectTeacher)->putJson("/api/v1/classrooms/{$this->room->id}/google-roster", ['matches' => []])
            ->assertForbidden()->assertJsonPath('code', 'not_homeroom_teacher');
    }

    public function test_a_student_has_left_only_when_gone_from_every_course(): void
    {
        $this->linkBoth();
        $this->rosters[self::COURSE_ID] = self::accounts(1, 3);
        $this->rosters[self::SUBJECT_COURSE] = self::accounts(1, 2);

        // Homeroom course: 2 is gone from it but still in the science course.
        $data = $this->asUser($this->homeroom)->postJson("/api/v1/classrooms/{$this->room->id}/google-roster/sync")->assertOk()->json('data');
        $this->assertSame([], $data['left']);

        // The science course cannot be read: nobody is marked left in this round.
        $this->rosters[self::SUBJECT_COURSE] = null;
        $this->rosters[self::COURSE_ID] = self::accounts(1);
        $data = $this->asUser($this->homeroom)->postJson("/api/v1/classrooms/{$this->room->id}/google-roster/sync")->assertOk()->json('data');
        $this->assertSame([], $data['left']);
        $this->assertSame('g-3', $this->member(3)->google_user_id);

        // Both courses read: 3 is in neither.
        $this->rosters[self::SUBJECT_COURSE] = self::accounts(1, 2);
        $data = $this->asUser($this->homeroom)->postJson("/api/v1/classrooms/{$this->room->id}/google-roster/sync")->assertOk()->json('data');
        $this->assertSame([$this->students[3]->id], array_column($data['left'], 'student_id'));
        $this->assertNull($this->member(3)->google_user_id);
        $this->assertNotNull($this->member(3)->left_course_at);
        $this->assertNull($this->member(2)->left_course_at);
    }

    public function test_the_background_sync_enrols_an_existing_student_and_waits_with_the_pin_of_a_new_one(): void
    {
        $this->linkBoth();
        $other = $this->makeClassroom($this->makeTeacher($this->homeroom->school));
        $existing = $this->enrollStudent($other, 1, 'ด.ญ. ย้าย ห้อง')['student'];
        ClassroomStudent::query()->where('student_id', $existing->id)->update(['google_email' => 'moved@student.example']);
        $this->rosters[self::COURSE_ID] = [...self::accounts(1, 2, 3), self::courseStudent('g-moved', 'ย้าย ห้อง', 'moved@student.example'), self::courseStudent('g-new', 'อรุณ ใหม่')];
        // The science course has someone the homeroom course does not: never added from there.
        $this->rosters[self::SUBJECT_COURSE] = [...self::accounts(1), self::courseStudent('g-science-only', 'วิทย์ อย่างเดียว')];
        $users = User::query()->count();

        SyncClassroomRosterJob::dispatchSync($this->room->id);

        $this->assertSame($users + 1, User::query()->count());
        $moved = ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_id', $existing->id)->firstOrFail();
        $this->assertSame(['g-moved', 4, null], [$moved->google_user_id, $moved->student_number, $moved->pin_pending_at]);
        $new = ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('google_user_id', 'g-new')->firstOrFail();
        $this->assertSame(5, $new->student_number);
        $this->assertNotNull($new->pin_pending_at);
        $this->assertFalse(ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('google_user_id', 'g-science-only')->exists());
        $this->assertSame(2, ClassroomGoogleLink::query()->whereNotNull('roster_synced_at')->count());
    }

    public function test_work_goes_through_the_course_of_the_teacher_who_manages_it(): void
    {
        $this->linkBoth();
        $scienceWork = Assignment::factory()->for_classroom($this->room)->create(['course_id' => $this->science->id, 'created_by' => $this->subjectTeacher->id]);
        $homeWork = Assignment::factory()->for_classroom($this->room)->create(['course_id' => $this->homeCourse->id]);
        $olderWork = Assignment::factory()->for_classroom($this->room)->create(['course_id' => null]);

        $this->assertSame(self::SUBJECT_COURSE, ClassroomGoogleLink::forAssignment($scienceWork)?->course_id);
        $this->assertSame(self::COURSE_ID, ClassroomGoogleLink::forAssignment($homeWork)?->course_id);
        $this->assertSame(self::COURSE_ID, ClassroomGoogleLink::forAssignment($olderWork)?->course_id);

        // Mirrors of Classroom-website work take the link's app course, never another teacher's.
        $links = ClassroomGoogleLink::query()->where('classroom_id', $this->room->id)->orderBy('id')->get();
        $this->assertSame($this->homeCourse->id, CourseWorkImporter::courseOf($links[0])?->id);
        $this->assertSame($this->science->id, CourseWorkImporter::courseOf($links[1])?->id);
        $links[0]->forceFill(['app_course_id' => null])->save();
        $this->assertSame($this->homeCourse->id, CourseWorkImporter::courseOf($links[0])?->id, "the owner's only course, though the classroom has two");
    }
}
