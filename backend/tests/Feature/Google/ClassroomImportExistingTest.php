<?php

namespace Tests\Feature\Google;

use App\Jobs\SyncClassroomRosterJob;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\StudentCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Importing a Classroom course whose students already have accounts
 * (DESIGN §24.10, §24.16 build 4): the preview's matches and suggested
 * classroom (70%), "สร้างห้องใหม่" with existing accounts, and "ผูกกับห้องที่มีอยู่"
 * for the homeroom teacher (at once) or anyone else (a course request linked
 * on approval).
 */
class ClassroomImportExistingTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const SUBJECT_COURSE = '612000000002';

    private User $homeroom;

    private User $subjectTeacher;

    private Classroom $room;

    /** @var array<string, list<array<string, mixed>>> course id => courses.students.list */
    private array $rosters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->homeroom = $this->makeTeacher(null, ['name' => 'ครูประจำชั้น']);
        $this->subjectTeacher = $this->makeTeacher($this->homeroom->school, ['name' => 'ครูวิทย์']);
        $this->connectGoogle($this->homeroom);
        $this->connectGoogle($this->subjectTeacher);
        $this->room = $this->makeClassroom($this->homeroom, ['name' => 'ป.5/1', 'academic_year' => 2569]);
        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/students*' => fn () => Http::response(['students' => $this->rosters[self::COURSE_ID] ?? []]),
            'classroom.googleapis.com/v1/courses/'.self::SUBJECT_COURSE.'/students*' => fn () => Http::response(['students' => $this->rosters[self::SUBJECT_COURSE] ?? []]),
            'classroom.googleapis.com/v1/courses?*' => Http::response(['courses' => [
                ['id' => self::COURSE_ID, 'name' => 'คณิตศาสตร์', 'section' => 'ป.5/1', 'courseState' => 'ACTIVE'],
                ['id' => self::SUBJECT_COURSE, 'name' => 'วิทยาศาสตร์', 'section' => 'ป.5/1', 'courseState' => 'ACTIVE'],
            ]]),
        ]);
    }

    /**
     * Students 1..$count of $room, matched to Google accounts g-1..g-$count.
     *
     * @return array<int, User> by number
     */
    private function matchedStudents(Classroom $room, int $count, int $from = 1): array
    {
        $students = [];
        for ($n = $from; $n < $from + $count; $n++) {
            $students[$n] = $this->enrollStudent($room, $n, "ด.ช. นักเรียน{$n} ใจดี")['student'];
            ClassroomStudent::query()->where('classroom_id', $room->id)->where('student_id', $students[$n]->id)
                ->update(['google_user_id' => "g-{$n}", 'google_email' => "s{$n}@student.example"]);
        }

        return $students;
    }

    /** @return list<array<string, mixed>> accounts g-1..g-$count */
    private static function accounts(int $count, string $course = self::COURSE_ID): array
    {
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['courseId' => $course, 'userId' => "g-{$n}", 'profile' => ['id' => "g-{$n}", 'name' => ['fullName' => "นักเรียน{$n} ใจดี"], 'emailAddress' => "s{$n}@student.example"]];
        }

        return $rows;
    }

    private function preview(User $teacher, string $course = self::COURSE_ID): array
    {
        return $this->asUser($teacher)->getJson("/api/v1/google/courses/{$course}/import-preview")->assertOk()->json('data');
    }

    public function test_the_preview_matches_existing_students_and_suggests_the_classroom_holding_70_percent(): void
    {
        $students = $this->matchedStudents($this->room, 7);
        $this->rosters[self::COURSE_ID] = [
            ...self::accounts(7),
            self::courseStudent('g-new-1', 'ใหม่ หนึ่ง'), self::courseStudent('g-new-2', 'ใหม่ สอง'), self::courseStudent('g-new-3', 'ใหม่ สาม'),
        ];

        $data = $this->preview($this->homeroom);

        $rows = collect($data['students'])->keyBy('google_user_id');
        $this->assertSame([
            'student_id' => $students[1]->id,
            'name' => $students[1]->name,
            'matched_by' => 'classroom_user',
            'classes' => [['id' => $this->room->id, 'name' => 'ป.5/1', 'academic_year' => 2569, 'student_number' => 1, 'closed' => false]],
        ], $rows['g-1']['match']);
        $this->assertNull($rows['g-new-1']['match']);
        $this->assertSame([
            'id' => $this->room->id,
            'name' => 'ป.5/1',
            'academic_year' => 2569,
            'homeroom_teacher' => ['id' => $this->homeroom->id, 'name' => 'ครูประจำชั้น'],
            'coverage' => 0.7,
            'matched' => 7,
            'owned_by_me' => true,
        ], $data['suggested_classroom']);

        // The same classroom is another teacher's for the subject teacher.
        $this->assertFalse($this->preview($this->subjectTeacher)['suggested_classroom']['owned_by_me']);
    }

    public function test_69_percent_is_not_enough(): void
    {
        $this->matchedStudents($this->room, 9);
        $this->rosters[self::COURSE_ID] = [...self::accounts(9), ...array_map(fn (int $n) => self::courseStudent("g-x{$n}", "อื่น คนที่{$n}"), range(1, 4))];

        $data = $this->preview($this->homeroom);

        $this->assertSame(9, collect($data['students'])->whereNotNull('match')->count());
        $this->assertNull($data['suggested_classroom'], '9 of 13 = 69%');
    }

    public function test_a_tie_goes_to_the_newer_year_then_the_lower_id_and_closed_classrooms_do_not_count(): void
    {
        $older = $this->makeClassroom($this->homeroom, ['name' => 'ป.4/1', 'academic_year' => 2568]);
        $students = $this->matchedStudents($older, 4);
        $newer = $this->makeClassroom($this->homeroom, ['name' => 'ป.5/3', 'academic_year' => 2569]);
        $twin = $this->makeClassroom($this->homeroom, ['name' => 'ป.5/4', 'academic_year' => 2569]);
        $closed = $this->makeClassroom($this->homeroom, ['name' => 'ป.6/1', 'academic_year' => 2570, 'closed_at' => now()]);
        foreach ([$newer, $twin, $closed] as $room) {
            foreach ($students as $n => $student) {
                $room->students()->attach($student->id, ['student_number' => $n]);
            }
        }
        $this->rosters[self::COURSE_ID] = [...self::accounts(4), self::courseStudent('g-new', 'ใหม่ คนเดียว')];

        $suggested = $this->preview($this->homeroom)['suggested_classroom'];

        $this->assertSame($newer->id, $suggested['id']);
        $this->assertSame(0.8, $suggested['coverage']);
    }

    public function test_a_new_classroom_enrols_matched_rows_with_their_one_account(): void
    {
        $students = $this->matchedStudents($this->room, 2);
        $byEmail = $this->enrollStudent($this->room, 3, 'ด.ญ. มาทีหลัง สมใจ')['student'];
        ClassroomStudent::query()->where('student_id', $byEmail->id)->update(['google_email' => 'late@student.example']);
        $pinHash = StudentCredential::query()->find($students[1]->id)->pin_hash;
        $this->rosters[self::COURSE_ID] = [...self::accounts(2), self::courseStudent('g-new', 'ใหม่ เอี่ยม'), self::courseStudent('g-late', 'มาทีหลัง', 'late@student.example')];
        $users = User::query()->count();

        $res = $this->asUser($this->homeroom)->postJson('/api/v1/classrooms/import-google', [
            'course_id' => self::COURSE_ID, 'name' => 'ม.1/1', 'grade_level' => 7, 'academic_year' => 2570,
            'students' => [
                ['google_user_id' => 'g-1', 'student_number' => 1, 'student_id' => $students[1]->id],
                ['google_user_id' => 'g-2', 'student_number' => 2, 'student_id' => $students[2]->id],
                ['google_user_id' => 'g-new', 'student_number' => 3, 'student_id' => null],
            ],
            'removed' => [],
        ])->assertCreated();

        $rows = collect($res->json('data.students'))->keyBy('student_number');
        $this->assertSame([$students[1]->id, null, true], [$rows[1]['student_id'], $rows[1]['pin'], $rows[1]['existing']]);
        $this->assertFalse($rows[3]['existing']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $rows[3]['pin']);
        // The account that joined after the preview is the student who had its e-mail.
        $this->assertSame([$byEmail->id, true], [$rows[4]['student_id'], $rows[4]['existing']]);
        $this->assertSame($users + 1, User::query()->count(), 'one new account only');
        $this->assertSame($pinHash, StudentCredential::query()->find($students[1]->id)->pin_hash, 'the PIN stays');

        $new = Classroom::query()->findOrFail($res->json('data.classroom.id'));
        $this->assertSame('g-1', ClassroomStudent::query()->where('classroom_id', $new->id)->where('student_id', $students[1]->id)->value('google_user_id'));
        $this->assertSame('g-late', ClassroomStudent::query()->where('classroom_id', $new->id)->where('student_id', $byEmail->id)->value('google_user_id'));
    }

    public function test_a_new_classroom_refuses_a_student_of_another_school(): void
    {
        $stranger = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];
        $this->rosters[self::COURSE_ID] = self::accounts(1);
        $classrooms = Classroom::query()->count();

        $this->asUser($this->homeroom)->postJson('/api/v1/classrooms/import-google', [
            'course_id' => self::COURSE_ID, 'name' => 'ม.1/1', 'grade_level' => 7, 'academic_year' => 2570,
            'students' => [['google_user_id' => 'g-1', 'student_number' => 1, 'student_id' => $stranger->id]],
        ])->assertStatus(422)->assertJsonPath('code', 'student_not_in_school')->assertJsonStructure(['errors' => ['students.0.student_id']]);

        $this->assertSame($classrooms, Classroom::query()->count(), 'no classroom was created');
        $this->assertSame(0, ClassroomGoogleLink::query()->count());
    }

    public function test_the_homeroom_teacher_links_the_course_to_the_existing_classroom_at_once(): void
    {
        $students = $this->matchedStudents($this->room, 2);
        // A student matched without an account here, one of another classroom, and a new one.
        $free = $this->enrollStudent($this->room, 3, 'ด.ญ. ยังไม่จับคู่ ดีใจ')['student'];
        $other = $this->makeClassroom($this->makeTeacher($this->homeroom->school));
        $elsewhere = $this->enrollStudent($other, 1, 'ด.ช. ห้องอื่น มาใหม่')['student'];
        ClassroomStudent::query()->where('student_id', $elsewhere->id)->update(['google_user_id' => 'g-else']);
        $course = $this->makeCourse($this->homeroom);
        $this->rosters[self::COURSE_ID] = [
            ...self::accounts(2),
            self::courseStudent('g-free', 'ยังไม่จับคู่ ดีใจ'),
            self::courseStudent('g-else', 'ห้องอื่น มาใหม่'),
            self::courseStudent('g-brand-new', 'อนันต์ คนใหม่'),
        ];
        $users = User::query()->count();

        $res = $this->asUser($this->homeroom)->postJson('/api/v1/google/courses/'.self::COURSE_ID.'/link-existing', [
            'classroom_id' => $this->room->id, 'app_course_id' => $course->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'linked')
            ->assertJsonPath('data.google_link.course_id', self::COURSE_ID)
            ->assertJsonPath('data.google_link.app_course_id', $course->id)
            ->assertJsonPath('data.classroom.google_link.course_id', self::COURSE_ID)
            ->assertJsonPath('data.roster_error', null);

        $roster = $res->json('data.roster');
        $this->assertSame([$free->id], array_column($roster['rematched'], 'student_id'));
        $this->assertSame([[$elsewhere->id, 4, null]], array_map(fn ($r) => [$r['student_id'], $r['student_number'], $r['pin']], $roster['enrolled']));
        $this->assertSame([['อนันต์ คนใหม่', 5]], array_map(fn ($r) => [$r['name'], $r['student_number']], $roster['added']));
        $this->assertSame([], $roster['not_in_classroom']);
        $this->assertSame($users + 1, User::query()->count());
        $this->assertTrue($this->room->courses()->whereKey($course->id)->exists(), 'the app course is bound');
        $this->assertNull(ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_id', $elsewhere->id)->value('pin_pending_at'));
        $this->assertSame('g-else', ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_id', $elsewhere->id)->value('google_user_id'));
        $this->assertSame('g-1', ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_id', $students[1]->id)->value('google_user_id'));
    }

    public function test_another_teacher_gets_a_course_request_that_links_the_course_when_approved(): void
    {
        $this->matchedStudents($this->room, 3);
        ClassroomGoogleLink::create(['classroom_id' => $this->room->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิตศาสตร์', 'owner_user_id' => $this->homeroom->id, 'linked_at' => now()]);
        $this->rosters[self::COURSE_ID] = self::accounts(3);
        $this->rosters[self::SUBJECT_COURSE] = [...self::accounts(2, self::SUBJECT_COURSE), self::courseStudent('g-outsider', 'คนนอก ห้อง')];
        $science = $this->makeCourse($this->subjectTeacher, [], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์ 5']);
        // Teacher S's matches are cleared so the approval's sync has something to do.
        ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_number', 2)->update(['google_user_id' => null]);

        $this->asUser($this->subjectTeacher)->postJson('/api/v1/google/courses/'.self::SUBJECT_COURSE.'/link-existing', ['classroom_id' => $this->room->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['app_course_id']]);
        $requestId = $this->asUser($this->subjectTeacher)->postJson('/api/v1/google/courses/'.self::SUBJECT_COURSE.'/link-existing', [
            'classroom_id' => $this->room->id, 'app_course_id' => $science->id,
        ])->assertStatus(202)->assertJsonPath('data.status', 'requested')->json('data.request_id');

        $request = ClassroomCourseRequest::query()->findOrFail($requestId);
        $this->assertSame([ClassroomCourseRequest::ORIGIN_CLASSROOM_IMPORT, 'pending', self::SUBJECT_COURSE, 'วิทยาศาสตร์'], [$request->origin, $request->status, $request->google_course_id, $request->google_course_name]);
        $this->assertNull(ClassroomGoogleLink::of($this->room->id, $this->subjectTeacher->id));
        $this->asUser($this->subjectTeacher)->postJson('/api/v1/google/courses/'.self::SUBJECT_COURSE.'/link-existing', [
            'classroom_id' => $this->room->id, 'app_course_id' => $science->id,
        ])->assertStatus(409)->assertJsonPath('code', 'request_pending');
        $this->asUser($this->homeroom)->getJson('/api/v1/course-requests?box=incoming')->assertOk()
            ->assertJsonPath('data.0.origin', 'classroom_import')->assertJsonPath('data.0.google_course_name', 'วิทยาศาสตร์');

        $users = User::query()->count();
        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$requestId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $link = ClassroomGoogleLink::of($this->room->id, $this->subjectTeacher->id);
        $this->assertNotNull($link);
        $this->assertSame([self::SUBJECT_COURSE, $science->id], [$link->course_id, $link->app_course_id]);
        $this->assertTrue($this->room->courses()->whereKey($science->id)->exists());
        // The queued sync (sync driver) matched student 2 again and added nobody.
        $this->assertNotNull($link->fresh()->roster_synced_at);
        $this->assertSame('g-2', ClassroomStudent::query()->where('classroom_id', $this->room->id)->where('student_number', 2)->value('google_user_id'));
        $this->assertSame($users, User::query()->count());
        $this->assertSame(3, $this->room->students()->count());
    }

    public function test_approving_after_the_course_was_linked_elsewhere_still_binds_the_course(): void
    {
        Queue::fake([SyncClassroomRosterJob::class]);
        $science = $this->makeCourse($this->subjectTeacher, [], ['code' => 'ว15101']);
        $requestId = $this->asUser($this->subjectTeacher)->postJson('/api/v1/google/courses/'.self::SUBJECT_COURSE.'/link-existing', [
            'classroom_id' => $this->room->id, 'app_course_id' => $science->id,
        ])->assertStatus(202)->json('data.request_id');
        $elsewhere = $this->makeClassroom($this->subjectTeacher);
        ClassroomGoogleLink::create(['classroom_id' => $elsewhere->id, 'course_id' => self::SUBJECT_COURSE, 'course_name' => 'x', 'owner_user_id' => $this->subjectTeacher->id, 'linked_at' => now()]);

        $this->asUser($this->homeroom)->postJson("/api/v1/course-requests/{$requestId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertTrue($this->room->courses()->whereKey($science->id)->exists());
        $this->assertNull(ClassroomGoogleLink::of($this->room->id, $this->subjectTeacher->id));
        Queue::assertNotPushed(SyncClassroomRosterJob::class);
    }

    public function test_a_subject_teacher_already_teaching_the_classroom_links_at_once_and_only_matches(): void
    {
        $this->matchedStudents($this->room, 2);
        $science = $this->makeCourse($this->subjectTeacher, [$this->room], ['code' => 'ว15101']);
        $this->rosters[self::SUBJECT_COURSE] = [...self::accounts(2, self::SUBJECT_COURSE), self::courseStudent('g-outsider', 'คนนอก ห้อง', 'out@student.example')];
        $users = User::query()->count();

        $res = $this->asUser($this->subjectTeacher)->postJson('/api/v1/google/courses/'.self::SUBJECT_COURSE.'/link-existing', [
            'classroom_id' => $this->room->id, 'app_course_id' => $science->id,
        ])->assertOk()->assertJsonPath('data.status', 'linked')->assertJsonPath('data.classroom.my_role', 'subject');

        $this->assertSame([], $res->json('data.roster.added'));
        $this->assertSame([['google_user_id' => 'g-outsider', 'name' => 'คนนอก ห้อง', 'email' => 'out@student.example']], $res->json('data.roster.not_in_classroom'));
        $this->assertSame($users, User::query()->count());
        $this->assertSame(0, ClassroomCourseRequest::query()->count());
    }

    public function test_link_existing_refusals(): void
    {
        $course = $this->makeCourse($this->homeroom);
        $url = '/api/v1/google/courses/'.self::COURSE_ID.'/link-existing';

        // Another school's classroom does not exist for this teacher.
        $foreign = $this->makeClassroom($this->makeTeacher());
        $this->asUser($this->homeroom)->postJson($url, ['classroom_id' => $foreign->id])->assertNotFound();

        $closed = $this->makeClassroom($this->homeroom, ['closed_at' => now()]);
        $this->asUser($this->homeroom)->postJson($url, ['classroom_id' => $closed->id])->assertStatus(409)->assertJsonPath('code', 'classroom_closed');

        // A course the teacher does not teach on Google.
        $this->asUser($this->homeroom)->postJson('/api/v1/google/courses/not-mine/link-existing', ['classroom_id' => $this->room->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['course_id']]);

        // An app course of somebody else.
        $theirs = $this->makeCourse($this->subjectTeacher);
        $this->asUser($this->homeroom)->postJson($url, ['classroom_id' => $this->room->id, 'app_course_id' => $theirs->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['app_course_id']]);

        ClassroomGoogleLink::create(['classroom_id' => $this->makeClassroom($this->homeroom)->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->homeroom->id, 'linked_at' => now()]);
        $this->asUser($this->homeroom)->postJson($url, ['classroom_id' => $this->room->id, 'app_course_id' => $course->id])
            ->assertStatus(409)->assertJsonPath('code', 'course_already_linked');
        $this->assertFalse($this->room->courses()->whereKey($course->id)->exists(), 'nothing was bound');
    }
}
