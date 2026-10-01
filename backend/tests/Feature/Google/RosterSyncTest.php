<?php

namespace Tests\Feature\Google;

use App\Jobs\FetchClassroomAttachmentsJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleIgnoredUser;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\StudentCredential;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "ซิงก์รายชื่อ" (DESIGN §19.2, §19.12): POST /classrooms/{id}/google-roster/sync
 * and SyncClassroomRosterJob.
 */
class RosterSyncTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    /** A sync that changes nothing (DESIGN §24.10 added enrolled and not_in_classroom). */
    private const NOTHING = ['added' => [], 'enrolled' => [], 'left' => [], 'rematched' => [], 'not_in_classroom' => []];

    private User $teacher;

    private Classroom $classroom;

    /** @var array<int, User> by student number */
    private array $students = [];

    /** @var list<array<string, mixed>> what courses.students.list answers (Http::fake keeps its first match, so tests change this instead) */
    private array $googleRoster = [];

    /** @var list<array<string, mixed>> what studentSubmissions.list answers */
    private array $turnedIn = [];

    private bool $courseGone = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->teacher = $this->makeTeacher();
        $this->connectGoogle($this->teacher);
        $this->classroom = $this->makeClassroom($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'คณิตศาสตร์', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        foreach ([1 => ['g-1', 'ด.ช. หนึ่ง ใจดี'], 2 => ['g-2', 'ด.ญ. สอง มีสุข'], 5 => ['g-5', 'ด.ช. ห้า รักเรียน']] as $n => [$googleId, $name]) {
            $this->students[$n] = $this->enrollStudent($this->classroom, $n, $name)['student'];
            $this->matchMember($n, $googleId);
        }
        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/students*' => fn () => $this->courseGone
                ? Http::response(self::googleError(404, 'NOT_FOUND', 'Requested entity was not found.'), 404)
                : Http::response(['students' => $this->googleRoster]),
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork/*' => fn () => Http::response(['studentSubmissions' => $this->turnedIn]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $students
     */
    private function roster(array $students): void
    {
        $this->googleRoster = $students;
    }

    private function matchMember(int $number, ?string $googleId, ?string $email = null): void
    {
        ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_number', $number)
            ->update(['google_user_id' => $googleId, 'google_email' => $email ?? ($googleId !== null ? "{$googleId}@student.example" : null)]);
    }

    private function member(int $number): ClassroomStudent
    {
        return ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_number', $number)->firstOrFail();
    }

    /** @return list<array<string, mixed>> the three current members as Google has them */
    private static function current(): array
    {
        return [
            self::courseStudent('g-1', 'หนึ่ง ใจดี', 'g-1@student.example'),
            self::courseStudent('g-2', 'สอง มีสุข', 'g-2@student.example'),
            self::courseStudent('g-5', 'ห้า รักเรียน', 'g-5@student.example'),
        ];
    }

    private function sync(): array
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-roster/sync")->assertOk()->json('data');
    }

    public function test_new_accounts_are_appended_after_the_highest_number_in_thai_order_and_matched(): void
    {
        $this->roster([
            ...self::current(),
            self::courseStudent('g-new-b', 'Bella Moon', 'bella@student.example'),
            self::courseStudent('g-new-a', 'ด.ญ. ขวัญใจ ใหม่', 'kwan@student.example'),
        ]);

        $data = $this->sync();

        $this->assertSame([[6, 'ด.ญ. ขวัญใจ ใหม่'], [7, 'Bella Moon']], array_map(fn (array $r) => [$r['student_number'], $r['name']], $data['added']));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $data['added'][0]['pin']);
        $this->assertArrayNotHasKey('google_user_id', $data['added'][0]);
        $this->assertSame([], $data['left']);
        $this->assertSame([], $data['rematched']);
        $this->assertSame('g-new-a', $this->member(6)->google_user_id);
        $this->assertSame('bella@student.example', $this->member(7)->google_email);
        $this->assertNotNull(ClassroomGoogleLink::query()->where('classroom_id', $this->classroom->id)->sole()->roster_synced_at);

        // Nothing new the second time.
        $this->assertSame(self::NOTHING, $this->sync());
    }

    public function test_an_account_that_left_is_unmatched_and_labelled_and_matched_back_by_email_when_it_returns(): void
    {
        $this->roster([self::current()[0], self::current()[2]]);

        $data = $this->sync();

        $this->assertSame([['student_id' => $this->students[2]->id, 'student_number' => 2, 'name' => 'ด.ญ. สอง มีสุข']], $data['left']);
        $this->assertSame([], $data['added']);
        $member = $this->member(2);
        $this->assertNull($member->google_user_id);
        $this->assertSame('g-2@student.example', $member->google_email, 'kept for the teacher to see');
        $this->assertNotNull($member->left_course_at);
        $this->assertNotNull(User::query()->find($this->students[2]->id), 'the student is never deleted');
        $roster = collect($this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()->json('data'))->keyBy('student_number');
        $this->assertNotNull($roster[2]['left_course_at']);
        $this->assertNull($roster[1]['left_course_at']);

        // Still gone: the time it left does not move.
        $leftAt = $member->left_course_at->toIso8601String();
        $this->travel(1)->days();
        $this->assertSame(self::NOTHING, $this->sync());
        $this->assertSame($leftAt, $this->member(2)->left_course_at->toIso8601String());

        // Back with the same account (Google shows the e-mail in another case).
        $this->roster([
            self::current()[0],
            self::courseStudent('g-2', 'สอง มีสุข', 'G-2@Student.Example'),
            self::current()[2],
        ]);
        $data = $this->sync();
        $this->assertSame([['student_id' => $this->students[2]->id, 'student_number' => 2, 'name' => 'ด.ญ. สอง มีสุข']], $data['rematched']);
        $this->assertSame([], $data['added']);
        $this->assertSame('g-2', $this->member(2)->google_user_id);
        $this->assertNull($this->member(2)->left_course_at);
    }

    public function test_a_new_account_with_the_e_mail_of_a_student_who_left_takes_their_place(): void
    {
        $this->matchMember(2, null, 'g-2@student.example');
        ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_number', 2)->update(['left_course_at' => now()]);
        $this->roster([self::current()[0], self::current()[2], self::courseStudent('g-2-new', 'สอง', 'g-2@student.example')]);

        $data = $this->sync();

        $this->assertSame([2], array_column($data['rematched'], 'student_number'));
        $this->assertSame([], $data['added']);
        $this->assertSame('g-2-new', $this->member(2)->google_user_id);
    }

    public function test_a_second_account_of_a_member_is_neither_added_nor_moves_the_match(): void
    {
        // Student 2 signs in with another Google account (pass 1), and that account
        // is in the course next to the one the membership is matched to.
        UserGoogleIdentity::create(['user_id' => $this->students[2]->id, 'google_sub' => 'g-2-other', 'email' => 'two@home.example', 'linked_via' => 'self', 'linked_at' => now()]);
        $this->roster([...self::current(), self::courseStudent('g-2-other', 'สอง มีสุข', 'two@home.example')]);
        $before = User::query()->where('role', 'student')->count();

        $this->assertSame(self::NOTHING, $this->sync());

        $this->assertSame($before, User::query()->where('role', 'student')->count(), 'no duplicate student');
        $this->assertSame('g-2', $this->member(2)->google_user_id, 'the match stays');
        $this->assertSame(3, ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->count());
    }

    public function test_names_in_the_app_are_never_overwritten(): void
    {
        $this->roster([
            self::courseStudent('g-1', 'Nueng Jaidee (new name in Google)', 'g-1@student.example'),
            ...array_slice(self::current(), 1),
        ]);

        $this->sync();

        $this->assertSame('ด.ช. หนึ่ง ใจดี', $this->students[1]->fresh()->name);
        $this->assertSame('g-1', $this->member(1)->google_user_id);
    }

    public function test_accounts_on_the_ignore_list_are_never_added(): void
    {
        ClassroomGoogleIgnoredUser::query()->insert(['classroom_id' => $this->classroom->id, 'google_user_id' => 'g-parent', 'name' => 'ผู้ปกครอง', 'created_at' => now()]);
        $this->roster([...self::current(), self::courseStudent('g-parent', 'ผู้ปกครอง', 'parent@example.com')]);

        $this->assertSame(self::NOTHING, $this->sync());
        $this->assertDatabaseMissing('classroom_students', ['google_user_id' => 'g-parent']);
    }

    public function test_a_room_linked_by_hand_matches_exact_names_instead_of_adding_duplicates(): void
    {
        foreach ([1, 2, 5] as $n) {
            $this->matchMember($n, null);
        }
        $this->roster([
            self::courseStudent('g-a', 'หนึ่ง ใจดี', null),           // same full name (title ignored)
            self::courseStudent('g-b', 'มีสุข สอง', null),            // same words in another order
            self::courseStudent('g-c', 'ห้า คนละคน', null),           // only the first name: too loose to apply unasked
        ]);

        $data = $this->sync();

        $this->assertEqualsCanonicalizing([1, 2], array_column($data['rematched'], 'student_number'));
        $this->assertSame([[6, 'ห้า คนละคน']], array_map(fn (array $r) => [$r['student_number'], $r['name']], $data['added']));
        $this->assertSame('g-a', $this->member(1)->google_user_id);
        $this->assertSame('g-b', $this->member(2)->google_user_id);
        $this->assertNull($this->member(5)->google_user_id);
    }

    public function test_a_namesake_of_a_student_who_left_is_someone_new(): void
    {
        // No. 2 left in an earlier round (their e-mail stays); no. 5 leaves in this one.
        $this->matchMember(2, null, 'g-2@student.example');
        ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_number', 2)->update(['left_course_at' => now()->subDay()]);
        $this->roster([
            self::current()[0],
            self::courseStudent('g-other-2', 'สอง มีสุข', 'other-2@student.example'),
            self::courseStudent('g-other-5', 'ห้า รักเรียน', 'other-5@student.example'),
        ]);

        $data = $this->sync();

        $this->assertSame([], $data['rematched'], 'who left only comes back by e-mail');
        $this->assertSame([5], array_column($data['left'], 'student_number'));
        $this->assertEqualsCanonicalizing([6, 7], array_column($data['added'], 'student_number'));
        $this->assertNull($this->member(2)->google_user_id);
        $this->assertNull($this->member(5)->google_user_id);
    }

    public function test_students_the_background_sync_adds_wait_for_their_pins(): void
    {
        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null), self::courseStudent('g-new-2', 'อีก คน', null)]);

        SyncClassroomRosterJob::dispatchSync($this->classroom->id);

        $roster = collect($this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/roster")->assertOk()->json('data'))->keyBy('student_number');
        $this->assertSame([false, true, true], [$roster[1]['pin_pending'], $roster[6]['pin_pending'], $roster[7]['pin_pending']]);
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertOk()->assertJsonPath('data.pins_pending', 2);

        // The button in the room issues their PINs, shown once.
        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->postJson("/api/v1/classrooms/{$this->classroom->id}/students/pending-pins")->assertNotFound();
        $pins = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students/pending-pins")->assertOk()->json('data');
        $this->assertSame([6, 7], array_column($pins, 'student_number'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pins[0]['pin']);
        $this->assertTrue(Hash::check($pins[0]['pin'], StudentCredential::query()->findOrFail($pins[0]['student_id'])->pin_hash));
        $this->assertSame([], $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students/pending-pins")->assertOk()->json('data'));
        $this->asUser($this->teacher)->getJson('/api/v1/teacher/attention')->assertJsonPath('data.pins_pending', 0);

        // The button sync shows the PINs itself: nothing waits.
        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null), self::courseStudent('g-new-2', 'อีก คน', null), self::courseStudent('g-3', 'สาม', null)]);
        $this->sync();
        $this->assertNull($this->member(8)->pin_pending_at);
    }

    public function test_a_single_pin_reset_settles_a_pending_student(): void
    {
        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null)]);
        SyncClassroomRosterJob::dispatchSync($this->classroom->id);
        $this->assertNotNull($this->member(6)->pin_pending_at);

        $this->asUser($this->teacher)->postJson("/api/v1/students/{$this->member(6)->student_id}/pin")->assertOk();

        $this->assertNull($this->member(6)->pin_pending_at);
    }

    public function test_submissions_not_scanned_yet_follow_the_new_matches(): void
    {
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY]);
        $row = fn (string $id, string $user, ?int $student, string $state) => ClassroomSubmissionImport::create([
            'assignment_id' => $assignment->id, 'google_submission_id' => $id, 'google_user_id' => $user, 'student_id' => $student,
            'state' => $state, 'attachments' => [], 'google_update_time' => '2026-09-20T02:15:00.000Z',
        ]);
        $newcomer = $row('sub-new', 'g-new', null, ClassroomSubmissionImport::STATE_NEW);
        $leaver = $row('sub-left', 'g-2', $this->students[2]->id, ClassroomSubmissionImport::STATE_NEW);
        $scanned = $row('sub-scanned', 'g-5', $this->students[5]->id, ClassroomSubmissionImport::STATE_IMPORTED);
        $this->roster([self::current()[0], self::courseStudent('g-new', 'ใหม่ มาเรียน', null)]);

        $data = $this->sync();

        $this->assertSame($data['added'][0]['student_id'], $newcomer->fresh()->student_id);
        $this->assertNull($leaver->fresh()->student_id);
        $this->assertSame($this->students[5]->id, $scanned->fresh()->student_id, 'a scanned row keeps its student');
    }

    public function test_sync_needs_a_linked_room_of_the_teacher(): void
    {
        $this->roster(self::current());
        $unlinked = $this->makeClassroom($this->teacher);
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$unlinked->id}/google-roster/sync")
            ->assertStatus(422)
            ->assertJsonPath('code', 'classroom_not_linked');

        $colleague = $this->makeTeacher($this->teacher->school);
        $this->asUser($colleague)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-roster/sync")->assertNotFound();

        $this->courseGone = true;
        $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/google-roster/sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'google_not_found');
        $this->assertNull($this->member(1)->left_course_at, 'nothing changes when Google fails');
    }

    public function test_the_job_uses_the_account_of_the_teacher_who_linked_and_ends_quietly_on_google_errors(): void
    {
        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null)]);

        SyncClassroomRosterJob::dispatchSync($this->classroom->id);
        $this->assertSame('g-new', $this->member(6)->google_user_id);

        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null), self::courseStudent('g-other', 'อีก คน', null)]);
        GoogleAccount::query()->whereKey($this->teacher->id)->update(['last_error' => GoogleAccount::ERROR_INVALID_GRANT]);
        $calls = count($this->sentTo('/students'));
        SyncClassroomRosterJob::dispatchSync($this->classroom->id);
        SyncClassroomRosterJob::dispatchSync(999999);
        $this->assertCount($calls, $this->sentTo('/students'), 'no Google call while the account needs reconnecting');
        $this->assertDatabaseCount('classroom_students', 4);

        GoogleAccount::query()->whereKey($this->teacher->id)->update(['last_error' => null]);
        $this->courseGone = true;
        SyncClassroomRosterJob::dispatchSync($this->classroom->id);
        $this->assertDatabaseCount('classroom_students', 4);
    }

    public function test_handing_in_from_an_unknown_account_syncs_the_roster_once_per_round(): void
    {
        Queue::fake([FetchClassroomAttachmentsJob::class]); // the downloads are FetchClassroomAttachmentsTest's
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY]);
        AssignmentGoogleLink::create(['assignment_id' => $assignment->id, 'course_work_id' => self::COURSE_WORK_ID, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        $this->roster([...self::current(), self::courseStudent('g-new', 'ใหม่ มาเรียน', null)]);
        $this->turnedIn = [
            self::turnedIn('sub-1', 'g-1', [['f-1', 'a.jpg']]),
            self::turnedIn('sub-new', 'g-new', [['f-2', 'b.jpg']]),
        ];

        $rows = collect($this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}/google-submissions")->assertOk()->json('data'))->keyBy('google_submission_id');

        // The queue runs inline in tests: the newcomer is a student already.
        $this->assertSame(6, $rows['sub-new']['student']['student_number']);
        $this->assertCount(1, $this->sentTo('/students'));

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}/google-submissions")->assertOk();
        $this->assertCount(1, $this->sentTo('/students'), 'known accounts only: no second sync');

        ClassroomStudent::query()->where('google_user_id', 'g-new')->update(['google_user_id' => null]);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}/google-submissions")->assertOk();
        $this->assertCount(1, $this->sentTo('/students'), 'at most once per classroom per 5 minutes');

        $this->travel(6)->minutes();
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$assignment->id}/google-submissions")->assertOk();
        $this->assertCount(2, $this->sentTo('/students'));
    }
}
