<?php

namespace Tests\Feature\Google;

use App\Domain\Google\SchoolStudentMatcher;
use App\Models\Classroom;
use App\Models\ClassroomStudent;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SchoolStudentMatcher (DESIGN §24.10, §24.16 build 4): which existing
 * student of the school each Classroom account is, passes 1-4 in order, and
 * an account that finds two students in a certain pass gets no match.
 */
class SchoolStudentMatcherTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $roomA;

    private Classroom $roomB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->roomA = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->roomB = $this->makeClassroom($this->makeTeacher($this->teacher->school), ['name' => 'ป.5/2']);
    }

    /**
     * @param  array<int, array{0: string, 1: string|null}>  $accounts  [google_user_id, email]
     * @return array<string, array{student_id: int, matched_by: string}>
     */
    private function match(array $accounts, bool $byName = true, ?string $name = null): array
    {
        return app(SchoolStudentMatcher::class)->match(
            (int) $this->teacher->school_id,
            array_map(fn (array $a) => ['google_user_id' => $a[0], 'name' => $a[2] ?? $name ?? 'ไม่ตรงกับใคร '.$a[0], 'email' => $a[1]], $accounts),
            $byName,
        );
    }

    private function student(Classroom $room, int $number, string $name, ?string $googleUserId = null, ?string $email = null): User
    {
        $student = $this->enrollStudent($room, $number, $name)['student'];
        ClassroomStudent::query()->where('classroom_id', $room->id)->where('student_id', $student->id)
            ->update(['google_user_id' => $googleUserId, 'google_email' => $email]);

        return $student;
    }

    public function test_each_pass_finds_the_one_student_strongest_first(): void
    {
        $signedIn = $this->student($this->roomA, 1, 'ด.ช. เอ ใจดี');
        UserGoogleIdentity::create(['user_id' => $signedIn->id, 'google_sub' => 'g-sub', 'email' => 'a@school.example', 'linked_via' => 'self', 'linked_at' => now()]);
        $matched = $this->student($this->roomB, 1, 'ด.ญ. บี มีสุข', 'g-room');
        $byEmail = $this->student($this->roomA, 2, 'ด.ช. ซี รักเรียน', null, 'Cee@Student.Example');
        $byName = $this->student($this->roomB, 2, 'ด.ญ. ดี ขยัน');

        $matches = $this->match([
            ['g-sub', null],
            ['g-room', null],
            ['g-mail', 'cee@student.example'],
            ['g-name', null, 'ดี ขยัน'],
        ]);

        $this->assertSame([
            'g-sub' => ['student_id' => $signedIn->id, 'matched_by' => 'google'],
            'g-room' => ['student_id' => $matched->id, 'matched_by' => 'classroom_user'],
            'g-mail' => ['student_id' => $byEmail->id, 'matched_by' => 'email'],
            'g-name' => ['student_id' => $byName->id, 'matched_by' => 'name'],
        ], $matches);

        // The syncs never match by name.
        $this->assertArrayNotHasKey('g-name', $this->match([['g-name', null, 'ดี ขยัน']], byName: false));
    }

    public function test_two_students_in_a_certain_pass_mean_no_match_at_all(): void
    {
        // One Google account matched to two accounts of one child (duplicates to merge first).
        $this->student($this->roomA, 1, 'ด.ช. ซ้ำ หนึ่ง', 'g-dup');
        $this->student($this->roomB, 1, 'ด.ช. ซ้ำ หนึ่ง', 'g-dup');
        $this->student($this->roomA, 2, 'ด.ญ. อีเมล ซ้ำ', null, 'same@student.example');
        $this->student($this->roomB, 2, 'ด.ญ. อีเมล ซ้ำ', null, 'same@student.example');

        $this->assertSame([], $this->match([['g-dup', null, 'ซ้ำ หนึ่ง'], ['g-x', 'same@student.example', 'อีเมล ซ้ำ']]));
    }

    public function test_a_name_must_be_unique_in_the_school_and_in_the_roster(): void
    {
        $this->student($this->roomA, 1, 'ด.ช. ชื่อ ซ้ำกัน');
        $this->student($this->roomB, 1, 'ด.ช. ชื่อ ซ้ำกัน');
        $once = $this->student($this->roomA, 2, 'ด.ญ. คนเดียว ในโรงเรียน');

        $this->assertSame([], $this->match([['g-1', null, 'ชื่อ ซ้ำกัน']]), 'two students carry the name');
        $this->assertSame([], $this->match([['g-1', null, 'คนเดียว ในโรงเรียน'], ['g-2', null, 'คนเดียว ในโรงเรียน']]), 'two accounts carry the name');
        $this->assertSame(['g-1' => ['student_id' => $once->id, 'matched_by' => 'name']], $this->match([['g-1', null, 'ในโรงเรียน คนเดียว']]), 'words in another order');
    }

    public function test_only_active_unmerged_students_of_the_school_are_candidates(): void
    {
        $disabled = $this->student($this->roomA, 1, 'ด.ช. ปิด บัญชี', 'g-off');
        $disabled->forceFill(['status' => User::STATUS_DISABLED])->save();
        $kept = $this->student($this->roomA, 2, 'ด.ช. เก็บ ไว้');
        $merged = $this->student($this->roomB, 1, 'ด.ช. ถูก รวม', 'g-merged');
        $merged->forceFill(['status' => User::STATUS_DISABLED, 'merged_into_id' => $kept->id])->save();
        $elsewhere = $this->makeClassroom($this->makeTeacher());
        $this->student($elsewhere, 1, 'ด.ช. ต่าง โรงเรียน', 'g-other-school');

        $this->assertSame([], $this->match([['g-off', null], ['g-merged', null], ['g-other-school', null, 'ต่าง โรงเรียน']]));
    }

    public function test_a_student_is_matched_to_one_account_the_stronger_pass_winning(): void
    {
        $student = $this->student($this->roomA, 1, 'ด.ช. หนึ่ง เดียว', 'g-old', 'one@student.example');

        // g-new carries the e-mail, g-old is the matched account: the account match wins.
        $this->assertSame(
            ['g-old' => ['student_id' => $student->id, 'matched_by' => 'classroom_user']],
            $this->match([['g-new', 'one@student.example', 'หนึ่ง เดียว'], ['g-old', null, 'อื่น']]),
        );
    }
}
