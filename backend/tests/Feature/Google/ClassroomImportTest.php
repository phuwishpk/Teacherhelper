<?php

namespace Tests\Feature\Google;

use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "นำเข้าจาก Google Classroom" (DESIGN §19.2, §19.9, §19.12):
 * GET /google/courses/{course_id}/import-preview and POST /classrooms/import-google.
 */
class ClassroomImportTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->captureLogs();
        $this->teacher = $this->makeTeacher();
        $this->connectGoogle($this->teacher);
    }

    /** @return list<array<string, mixed>> */
    private static function roster(): array
    {
        return [
            self::courseStudent('g-adam', 'Mr. Adam West', 'adam@student.example'),
            self::courseStudent('g-kesorn', 'ด.ญ. เกษร ดีมาก', 'kesorn@student.example'),
            self::courseStudent('g-kamol', 'กมล ใจดี', 'kamol@student.example'),
            self::courseStudent('g-parent', 'คุณแม่ ของกมล', 'parent@example.com'),
            self::courseStudent('g-noname', '', 'noname@student.example'),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'course_id' => self::COURSE_ID,
            'name' => 'คณิต ม.1/2',
            'grade_level' => 7,
            'academic_year' => 2569,
            'students' => [
                ['google_user_id' => 'g-kamol', 'student_number' => 1],
                ['google_user_id' => 'g-kesorn', 'student_number' => 2],
                ['google_user_id' => 'g-adam', 'student_number' => 5],
                ['google_user_id' => 'g-noname', 'student_number' => 6],
            ],
            'removed' => ['g-parent'],
            ...$overrides,
        ];
    }

    public function test_the_preview_proposes_a_name_grade_year_and_numbers_in_thai_order(): void
    {
        // 18:00 UTC on 31 Dec is already 1 Jan in Bangkok: the Buddhist year follows Bangkok.
        Carbon::setTestNow('2026-12-31 18:00:00');
        $this->fakeCourseRoster(self::roster());

        $res = $this->asUser($this->teacher)->getJson('/api/v1/google/courses/'.self::COURSE_ID.'/import-preview')
            ->assertOk()
            ->assertJsonPath('data.course_id', self::COURSE_ID)
            ->assertJsonPath('data.name', 'คณิตศาสตร์')
            ->assertJsonPath('data.section', 'ม.1/2')
            ->assertJsonPath('data.suggested_name', 'คณิตศาสตร์ ม.1/2')
            ->assertJsonPath('data.grade_level_guess', 7)
            ->assertJsonPath('data.academic_year', 2570);

        $this->assertSame([
            ['google_user_id' => 'g-kamol', 'name' => 'กมล ใจดี', 'email' => 'kamol@student.example', 'proposed_number' => 1],
            ['google_user_id' => 'g-kesorn', 'name' => 'ด.ญ. เกษร ดีมาก', 'email' => 'kesorn@student.example', 'proposed_number' => 2],
            ['google_user_id' => 'g-parent', 'name' => 'คุณแม่ ของกมล', 'email' => 'parent@example.com', 'proposed_number' => 3],
            ['google_user_id' => 'g-adam', 'name' => 'Mr. Adam West', 'email' => 'adam@student.example', 'proposed_number' => 4],
            ['google_user_id' => 'g-noname', 'name' => 'noname', 'email' => 'noname@student.example', 'proposed_number' => 5],
        ], $res->json('data.students'));
        $this->assertStringContainsString('teacherId=me', $this->sentTo('/v1/courses?')[0]->url());
        $this->assertNoSecretIn($res->getContent(), 'the preview');
        $this->assertNoSecretInLogs();
    }

    public function test_the_preview_leaves_the_grade_empty_when_the_name_has_none(): void
    {
        $this->fakeCourseRoster([], [['id' => self::COURSE_ID, 'name' => 'ชุมนุมหุ่นยนต์']]);

        $this->asUser($this->teacher)->getJson('/api/v1/google/courses/'.self::COURSE_ID.'/import-preview')
            ->assertOk()
            ->assertJsonPath('data.suggested_name', 'ชุมนุมหุ่นยนต์')
            ->assertJsonPath('data.section', null)
            ->assertJsonPath('data.grade_level_guess', null)
            ->assertJsonPath('data.students', []);
    }

    public function test_a_course_the_teacher_does_not_teach_or_one_already_linked_cannot_be_previewed(): void
    {
        $this->fakeCourseRoster(self::roster());

        $this->asUser($this->teacher)->getJson('/api/v1/google/courses/not-mine/import-preview')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['course_id']]);

        $other = $this->makeTeacher($this->teacher->school);
        ClassroomGoogleLink::create(['classroom_id' => $this->makeClassroom($other)->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $other->id, 'linked_at' => now()]);
        $this->asUser($this->teacher)->getJson('/api/v1/google/courses/'.self::COURSE_ID.'/import-preview')
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_already_linked');
    }

    public function test_the_course_list_marks_linked_courses_with_their_classroom(): void
    {
        $mine = $this->makeClassroom($this->teacher, ['name' => 'ห้องของฉัน']);
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirs = $this->makeClassroom($colleague, ['name' => 'ห้องลับของเพื่อนครู']);
        ClassroomGoogleLink::create(['classroom_id' => $mine->id, 'course_id' => 'c-mine', 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        ClassroomGoogleLink::create(['classroom_id' => $theirs->id, 'course_id' => 'c-shared', 'course_name' => 'x', 'owner_user_id' => $colleague->id, 'linked_at' => now()]);
        $this->fakeCourseRoster([], [['id' => 'c-free', 'name' => 'ว่าง'], ['id' => 'c-mine', 'name' => 'ของฉัน'], ['id' => 'c-shared', 'name' => 'สอนร่วม']]);

        $rows = collect($this->asUser($this->teacher)->getJson('/api/v1/google/courses')->assertOk()->json('data'))->keyBy('course_id');

        $this->assertNull($rows['c-free']['linked_classroom']);
        $this->assertSame(['id' => $mine->id, 'name' => 'ห้องของฉัน'], $rows['c-mine']['linked_classroom']);
        $this->assertSame($mine->id, $rows['c-mine']['linked_classroom_id']);
        $this->assertSame(['id' => null, 'name' => 'ห้องเรียนของครูท่านอื่น'], $rows['c-shared']['linked_classroom']);
        $this->assertNull($rows['c-shared']['linked_classroom_id']);
    }

    public function test_the_import_creates_the_room_students_link_matches_and_ignore_list_in_one_step(): void
    {
        $this->fakeCourseRoster(self::roster());

        $res = $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.classroom.name', 'คณิต ม.1/2')
            ->assertJsonPath('data.classroom.grade_level', 7)
            ->assertJsonPath('data.classroom.academic_year', 2569)
            ->assertJsonPath('data.classroom.students_count', 4)
            ->assertJsonPath('data.classroom.google_link.course_id', self::COURSE_ID)
            ->assertJsonPath('data.classroom.google_link.course_name', 'คณิตศาสตร์');

        $classroom = Classroom::query()->findOrFail($res->json('data.classroom.id'));
        $this->assertSame($this->teacher->id, $classroom->teacher_id);
        $this->assertSame($this->teacher->school_id, $classroom->school_id);
        $this->assertSame(6, strlen($classroom->class_code));

        $students = $res->json('data.students');
        $this->assertSame([1, 2, 5, 6], array_column($students, 'student_number'));
        $this->assertSame(['กมล ใจดี', 'ด.ญ. เกษร ดีมาก', 'Mr. Adam West', 'noname'], array_column($students, 'name'));
        foreach ($students as $row) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $row['pin']);
        }

        $members = ClassroomStudent::query()->where('classroom_id', $classroom->id)->orderBy('student_number')->get();
        $this->assertSame(['g-kamol', 'g-kesorn', 'g-adam', 'g-noname'], $members->pluck('google_user_id')->all());
        $this->assertSame('kamol@student.example', $members[0]->google_email);
        $this->assertNull($members[0]->left_course_at);
        $this->assertSame(User::ROLE_STUDENT, User::query()->find($students[0]['student_id'])->role);

        $link = ClassroomGoogleLink::query()->findOrFail($classroom->id);
        $this->assertSame($this->teacher->id, $link->owner_user_id);
        $this->assertNotNull($link->roster_synced_at);
        $this->assertDatabaseHas('classroom_google_ignored_users', ['classroom_id' => $classroom->id, 'google_user_id' => 'g-parent', 'name' => 'คุณแม่ ของกมล']);

        // The PIN is shown once; the roster never repeats it.
        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$classroom->id}/roster")
            ->assertOk()
            ->assertJsonMissingPath('data.0.pin')
            ->assertJsonPath('data.0.left_course_at', null);

        // The removed account stays out of the next roster sync.
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$classroom->id}/google-roster/sync")
            ->assertOk()
            ->assertExactJson(['data' => ['added' => [], 'left' => [], 'rematched' => []]]);
        $this->assertNoSecretIn($res->getContent(), 'the import answer');
        $this->assertNoSecretInLogs();
    }

    public function test_names_come_from_google_not_from_the_client(): void
    {
        $this->fakeCourseRoster(self::roster());
        $body = $this->body();
        $body['students'][0]['name'] = 'ชื่อที่แอปแต่งเอง';

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $body)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['students.0']]);
        $this->assertDatabaseCount('classrooms', 0);
    }

    public function test_duplicate_numbers_and_an_account_both_kept_and_removed_are_rejected(): void
    {
        $this->fakeCourseRoster(self::roster());
        $body = $this->body();
        $body['students'][1]['student_number'] = 1;

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['students.1.student_number']]);

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body(['removed' => ['g-parent', 'g-adam']]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['students.2.google_user_id']]);

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body(['grade_level' => null]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['grade_level']]);

        $this->assertDatabaseCount('classrooms', 0);
        $this->assertDatabaseCount('classroom_google_links', 0);
    }

    public function test_a_course_already_linked_is_409_before_calling_google(): void
    {
        $this->fakeCourseRoster(self::roster());
        ClassroomGoogleLink::create(['classroom_id' => $this->makeClassroom($this->teacher)->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_already_linked');
        $this->assertCount(0, $this->sentTo('classroom.googleapis.com'));
        $this->assertDatabaseCount('classrooms', 1);
    }

    public function test_a_second_import_of_the_same_course_waits_for_the_first_and_then_is_409(): void
    {
        $this->fakeCourseRoster(self::roster());
        config(['eduvision.classroom_sync.link_lock_wait_seconds' => 0]);

        // Another request holds the course: this one gives up instead of deadlocking.
        $other = Cache::lock('google-course-link:'.self::COURSE_ID, 60);
        $this->assertTrue($other->get());
        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_link_busy');
        $this->assertDatabaseCount('classrooms', 0);
        $other->release();

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body())->assertCreated();
        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'course_already_linked');
        $this->assertDatabaseCount('classrooms', 1);
    }

    public function test_accounts_that_joined_after_the_preview_are_appended_and_those_that_left_are_skipped(): void
    {
        $roster = self::roster();
        $roster[] = self::courseStudent('g-late-b', 'ขวัญ มาช้า', null);
        $roster[] = self::courseStudent('g-late-a', 'กานดา มาช้า', null);
        $this->fakeCourseRoster($roster);
        $body = $this->body();
        $body['students'][] = ['google_user_id' => 'g-gone', 'student_number' => 3];

        $res = $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $body)->assertCreated();

        $this->assertSame(
            [[1, 'กมล ใจดี'], [2, 'ด.ญ. เกษร ดีมาก'], [5, 'Mr. Adam West'], [6, 'noname'], [7, 'กานดา มาช้า'], [8, 'ขวัญ มาช้า']],
            array_map(fn (array $s) => [$s['student_number'], $s['name']], $res->json('data.students')),
        );
        $this->assertDatabaseMissing('classroom_students', ['google_user_id' => 'g-gone']);
        $this->assertDatabaseMissing('classroom_students', ['google_user_id' => 'g-parent']);
    }

    public function test_more_than_100_students_are_enrolled_in_chunks_and_a_failure_rolls_everything_back(): void
    {
        $roster = [];
        $students = [];
        for ($i = 1; $i <= 150; $i++) {
            $roster[] = self::courseStudent("g-{$i}", sprintf('Student %03d', $i), "s{$i}@student.example");
            $students[] = ['google_user_id' => "g-{$i}", 'student_number' => $i];
        }
        $this->fakeCourseRoster($roster);
        $usersBefore = User::query()->count();

        // The second chunk fails: nothing of the first one may stay.
        $calls = 0;
        $failOnCall = 2;
        $this->app->bind(StudentEnroller::class, function ($app) use (&$calls, &$failOnCall) {
            return new class($app->make(CredentialIssuer::class), $calls, $failOnCall) extends StudentEnroller
            {
                public function __construct(CredentialIssuer $issuer, private int &$calls, private int &$failOnCall)
                {
                    parent::__construct($issuer);
                }

                public function enroll(Classroom $classroom, array $rows): array
                {
                    if (++$this->calls === $this->failOnCall) {
                        throw StudentEnroller::numbersTaken([101]);
                    }

                    return parent::enroll($classroom, $rows);
                }
            };
        });

        $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body(['students' => $students, 'removed' => []]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_number_taken');
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('classrooms', 0);
        $this->assertDatabaseCount('classroom_students', 0);
        $this->assertDatabaseCount('classroom_google_links', 0);
        $this->assertSame($usersBefore, User::query()->count());

        $calls = 0;
        $failOnCall = 0;
        $res = $this->asUser($this->teacher)->postJson('/api/v1/classrooms/import-google', $this->body(['students' => $students, 'removed' => []]))
            ->assertCreated()
            ->assertJsonCount(150, 'data.students')
            ->assertJsonPath('data.classroom.students_count', 150);
        $this->assertSame(2, $calls, '100 + 50');
        $this->assertSame(range(1, 150), array_column($res->json('data.students'), 'student_number'));
        $this->assertSame(150, ClassroomStudent::query()->whereNotNull('google_user_id')->count());
    }

    public function test_only_a_connected_teacher_can_import(): void
    {
        $this->fakeCourseRoster(self::roster());
        $other = $this->makeTeacher();

        $this->asUser($other)->getJson('/api/v1/google/courses/'.self::COURSE_ID.'/import-preview')
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_not_connected');
        $this->asUser($other)->postJson('/api/v1/classrooms/import-google', $this->body())
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_not_connected');
        $this->assertDatabaseCount('classrooms', 0);
    }
}
