<?php

namespace App\Domain\Google;

use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Classrooms\ThaiNameSorter;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomGoogleIgnoredUser;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "ซิงก์รายชื่อ" (DESIGN §19.2, §24.10, POST /classrooms/{id}/google-roster/sync
 * and SyncClassroomRosterJob) brings a classroom in line with one of its
 * linked courses (one per teacher since build 4):
 *
 *   left       a matched account that is in none of the classroom's courses:
 *              left_course_at is set and the match cleared (google_email
 *              stays for the teacher to see). The student and their scores
 *              stay. When the roster of another course of the classroom
 *              cannot be read, nobody is marked left in that round.
 *   rematched  an unmatched account that is a student of this classroom
 *              without an account: the school-wide match (SchoolStudentMatcher
 *              passes 1-3: Google sign-in, the account in another classroom,
 *              the same e-mail), or else the same full name (RosterMatcher
 *              passes 1-2, unique on both sides) as a student never
 *              matched, e.g. in a room linked by hand (§18.7) before its
 *              roster was matched. left_course_at is cleared.
 *
 * The homeroom teacher's course also changes the roster:
 *
 *   enrolled   an account that is an existing student of the school (passes
 *              1-3) but not of this classroom: their one account is enrolled
 *              with the PIN and QR card they have (no pin_pending_at);
 *   added      any other account not in the ignore list: a new student.
 *
 * An account that passes 1-3 tie to a student who is already a member under
 * another account (a second Google account of the same student) is neither
 * added nor rematched: one membership holds one account, and moving the match
 * would flip it back and forth with the course that uses the other account.
 * It stays unmatched (logged) instead of becoming a duplicate student.
 *
 * Both get numbers after the highest, in ThaiNameSorter order, and are
 * matched at once. The PIN of a new student is in the answer, shown once.
 * The background sync ($background) has nobody to show it to: the student
 * is marked pin_pending_at, and the teacher issues the PINs later
 * (POST /classrooms/{id}/students/pending-pins). A name alone never enrols
 * or matches anyone outside the classroom; a new student whose name equals
 * an existing one shows up in "คู่ที่น่าจะซ้ำ".
 *
 * A subject teacher's course only matches (#65): an account that is not a
 * student of the classroom is reported in not_in_classroom for the homeroom
 * teacher to add.
 *
 * Names in the app are never overwritten. Import rows that are not scanned
 * yet follow the new matches (GoogleRoster::followRoster).
 */
final class GoogleRosterSync
{
    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly StudentEnroller $enroller,
        private readonly SchoolStudentMatcher $matcher,
    ) {}

    /**
     * The teacher's own course of the classroom ("ซิงก์รายชื่อ").
     *
     * @return array{added: list<array{student_id: int, student_number: int, name: string, pin: string}>, enrolled: list<array{student_id: int, student_number: int, name: string, pin: string|null}>, left: list<array{student_id: int, student_number: int, name: string}>, rematched: list<array{student_id: int, student_number: int, name: string}>, not_in_classroom: list<array{google_user_id: string, name: string, email: string|null}>}
     *
     * @throws ApiException Google errors, 422 classroom_not_linked
     */
    public function sync(User $teacher, Classroom $classroom, bool $background = false): array
    {
        $link = GoogleRoster::linkOf($classroom, $teacher->id);

        return $this->syncLink($teacher, $classroom, $link, $background);
    }

    /**
     * One link of the classroom with the given teacher's Google account.
     *
     * @return array<string, list<array<string, mixed>>> as sync()
     *
     * @throws ApiException Google errors of this link's course
     */
    public function syncLink(User $teacher, Classroom $classroom, ClassroomGoogleLink $link, bool $background = false): array
    {
        $accounts = $this->accounts->call($teacher, fn (GoogleApi $api) => $api->courseStudents($link->course_id), GoogleRoster::COURSE_GONE);

        return $this->apply($classroom, $link, $accounts, $background, $this->elsewhere($classroom, $link));
    }

    /**
     * Account ids of the classroom's other courses, read with their owners'
     * accounts; null when one of them cannot be read (nobody is marked left then).
     *
     * @param  array<int, list<array{google_user_id: string, name: string, email: string|null}>|null>  $known  rosters already read, by link id
     * @return array<string, true>|null
     */
    public function elsewhere(Classroom $classroom, ClassroomGoogleLink $link, array $known = []): ?array
    {
        $ids = [];
        foreach (ClassroomGoogleLink::query()->where('classroom_id', $classroom->id)->whereKeyNot($link->id)->get() as $other) {
            $roster = array_key_exists($other->id, $known) ? $known[$other->id] : $this->rosterOf($other);
            if ($roster === null) {
                return null;
            }
            foreach ($roster as $account) {
                $ids[$account['google_user_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * The roster of a link's course with its owner's account; null when it
     * cannot be read (logged).
     *
     * @return list<array{google_user_id: string, name: string, email: string|null}>|null
     */
    public function rosterOf(ClassroomGoogleLink $link): ?array
    {
        $owner = User::query()->find($link->owner_user_id);
        if ($owner === null) {
            return null;
        }
        try {
            return $this->accounts->call($owner, fn (GoogleApi $api) => $api->courseStudents($link->course_id), GoogleRoster::COURSE_GONE);
        } catch (ApiException $e) {
            Log::warning('google.roster_read_failed', ['classroom_id' => $link->classroom_id, 'link_id' => $link->id, 'code' => $e->errorCode]);

            return null;
        }
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts  the course roster from Google
     * @param  bool  $background  nobody sees the answer: new students are marked pin_pending_at
     * @param  array<string, true>|null  $elsewhere  accounts of the classroom's other courses (null: unknown)
     * @return array{added: list<array{student_id: int, student_number: int, name: string, pin: string}>, enrolled: list<array{student_id: int, student_number: int, name: string, pin: string|null}>, left: list<array{student_id: int, student_number: int, name: string}>, rematched: list<array{student_id: int, student_number: int, name: string}>, not_in_classroom: list<array{google_user_id: string, name: string, email: string|null}>}
     */
    public function apply(Classroom $classroom, ClassroomGoogleLink $link, array $accounts, bool $background = false, ?array $elsewhere = []): array
    {
        $result = DB::transaction(function () use ($classroom, $link, $accounts, $background, $elsewhere) {
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
            $homeroom = $link->isHomeroomLink($classroom);

            $byId = array_column($accounts, null, 'google_user_id');
            $ignored = array_flip(array_map('strval', ClassroomGoogleIgnoredUser::query()->where('classroom_id', $classroom->id)->pluck('google_user_id')->all()));
            $members = ClassroomStudent::query()
                ->join('users', 'users.id', '=', 'classroom_students.student_id')
                ->where('classroom_students.classroom_id', $classroom->id)
                ->get(['classroom_students.student_id', 'classroom_students.student_number', 'classroom_students.google_user_id', 'classroom_students.google_email', 'classroom_students.left_course_at', 'users.name']);

            $left = [];
            $rematched = [];
            $pairs = [];
            $matched = [];
            $free = []; // members without an account, by student id
            $neverMatched = []; // of those, the ones the name pass may take: not matched before and not gone

            foreach ($members as $member) {
                $googleId = $member->google_user_id;
                if ($googleId !== null && isset($byId[$googleId])) {
                    $matched[$googleId] = true;
                    if ($member->left_course_at !== null) {
                        $this->updateMember($classroom, $member->student_id, ['left_course_at' => null]);
                    }

                    continue;
                }
                if ($googleId !== null) {
                    if ($elsewhere === null || isset($elsewhere[$googleId])) {
                        continue; // in another course of the classroom (or that course cannot be read now)
                    }
                    $this->updateMember($classroom, $member->student_id, ['google_user_id' => null, 'left_course_at' => $member->left_course_at ?? now()]);
                    $left[] = self::row($member);
                    $pairs[$googleId] = null;
                } elseif ($member->left_course_at === null) {
                    $neverMatched[(int) $member->student_id] = true;
                }
                $free[(int) $member->student_id] = $member;
            }

            $unmatched = array_values(array_filter($accounts, fn (array $a) => ! isset($matched[$a['google_user_id']]) && ! isset($ignored[$a['google_user_id']])));
            $school = $this->matcher->match((int) $classroom->school_id, $unmatched, byName: false);

            // A student of this classroom without an account, found school-wide.
            foreach ($unmatched as $account) {
                $studentId = $school[$account['google_user_id']]['student_id'] ?? null;
                if ($studentId === null || ! isset($free[$studentId])) {
                    continue;
                }
                $this->match($classroom, $studentId, $account);
                $matched[$account['google_user_id']] = true;
                $pairs[$account['google_user_id']] = $studentId;
                $rematched[] = self::row($free[$studentId]);
                unset($free[$studentId]);
            }

            // The same full name as a student never matched (a room linked by hand). Who
            // left, earlier or in this round, only comes back by the passes above: a
            // namesake with another account is someone else.
            $unmatched = array_values(array_filter($unmatched, fn (array $a) => ! isset($matched[$a['google_user_id']])));
            $candidates = array_map(fn ($m) => ['id' => (int) $m->student_id, 'name' => (string) $m->name], array_values(array_intersect_key($free, $neverMatched)));
            foreach (RosterMatcher::suggest($unmatched, $candidates, false) as $googleId => $studentId) {
                $googleId = (string) $googleId;
                $this->match($classroom, $studentId, $byId[$googleId]);
                $matched[$googleId] = true;
                $pairs[$googleId] = $studentId;
                $rematched[] = self::row($free[$studentId]);
            }

            // A second account of a student who is already a member, matched to another
            // account (in this course, or in another course of the classroom, or unknown
            // because that course cannot be read now). One membership holds one account,
            // so it stays unmatched rather than becoming a duplicate student or taking the
            // match away from the account the other course uses.
            $memberIds = array_flip(array_map(fn ($m) => (int) $m->student_id, $members->all()));
            $rest = [];
            foreach ($unmatched as $account) {
                if (isset($matched[$account['google_user_id']])) {
                    continue;
                }
                $studentId = $school[$account['google_user_id']]['student_id'] ?? null;
                if ($studentId !== null && isset($memberIds[$studentId])) {
                    Log::info('google.roster_second_account', ['classroom_id' => $classroom->id, 'link_id' => $link->id, 'student_id' => $studentId]);

                    continue;
                }
                $rest[] = $account;
            }
            $added = [];
            $enrolled = [];
            $notInClassroom = [];
            if ($homeroom) {
                // Everyone else joins: existing students of the school keep their account.
                $existing = [];
                foreach ($rest as $account) {
                    $studentId = $school[$account['google_user_id']]['student_id'] ?? null;
                    if ($studentId !== null) {
                        $existing[$account['google_user_id']] = $studentId;
                    }
                }
                foreach ($this->add($classroom, $rest, $existing, $members->max('student_number') ?? 0, $background) as $row) {
                    $pairs[$row['google_user_id']] = $row['student_id'];
                    $public = array_diff_key($row, ['google_user_id' => true, 'existing' => true]);
                    if ($row['existing']) {
                        $enrolled[] = $public;
                    } else {
                        $added[] = $public;
                    }
                }
            } else {
                foreach ($rest as $account) {
                    $notInClassroom[] = ['google_user_id' => $account['google_user_id'], 'name' => GoogleRoster::studentName($account), 'email' => $account['email']];
                }
            }

            GoogleRoster::followRoster($classroom, $pairs);
            $link->forceFill(['roster_synced_at' => now()])->save();

            return [
                'added' => $added,
                'enrolled' => $enrolled,
                'left' => $left,
                'rematched' => $rematched,
                'not_in_classroom' => $notInClassroom,
            ];
        });

        Log::info('google.roster_synced', [
            'classroom_id' => $classroom->id,
            'link_id' => $link->id,
            'added' => count($result['added']),
            'enrolled' => count($result['enrolled']),
            'left' => count($result['left']),
            'rematched' => count($result['rematched']),
            'not_in_classroom' => count($result['not_in_classroom']),
        ]);

        return $result;
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts
     * @param  array<string, int>  $existing  google_user_id => the existing student to enrol
     * @return list<array{google_user_id: string, student_id: int, student_number: int, name: string, pin: string|null, existing: bool}>
     */
    private function add(Classroom $classroom, array $accounts, array $existing, int $highest, bool $background): array
    {
        if ($accounts === []) {
            return [];
        }
        $named = array_map(fn (array $a) => [...$a, 'sort_name' => GoogleRoster::studentName($a)], $accounts);
        $sorted = ThaiNameSorter::sort($named, 'sort_name', 'google_user_id');
        if ($highest + count($sorted) > ClassroomImporter::MAX_STUDENT_NUMBER) {
            throw ValidationException::withMessages(['students' => ['เพิ่มนักเรียนไม่ได้ เลขที่จะเกิน '.ClassroomImporter::MAX_STUDENT_NUMBER]]);
        }

        $added = [];
        $next = $highest + 1;
        foreach (array_chunk($sorted, ClassroomImporter::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $account) {
                $studentId = $existing[$account['google_user_id']] ?? null;
                $rows[] = $studentId !== null
                    ? ['student_id' => $studentId, 'student_number' => $next++]
                    : ['name' => $account['sort_name'], 'student_number' => $next++];
            }
            foreach ($this->enroller->enroll($classroom, $rows) as $i => $created) {
                $this->match($classroom, $created['student']->id, $chunk[$i]);
                if ($background && $created['pin'] !== null) {
                    $this->updateMember($classroom, $created['student']->id, ['pin_pending_at' => now()]);
                }
                $added[] = [
                    'google_user_id' => $chunk[$i]['google_user_id'],
                    'student_id' => $created['student']->id,
                    'student_number' => $created['student_number'],
                    'name' => $created['student']->name,
                    'username' => $created['student']->username,
                    'pin' => $created['pin'],
                    'existing' => $created['existing'],
                ];
            }
        }

        return $added;
    }

    /**
     * @param  array{google_user_id: string, name: string, email: string|null}  $account
     */
    private function match(Classroom $classroom, int $studentId, array $account): void
    {
        $this->updateMember($classroom, $studentId, [
            'google_user_id' => $account['google_user_id'],
            'google_email' => $account['email'],
            'left_course_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateMember(Classroom $classroom, int $studentId, array $values): void
    {
        ClassroomStudent::query()->where('classroom_id', $classroom->id)->where('student_id', $studentId)->update($values);
    }

    /**
     * @return array{student_id: int, student_number: int, name: string}
     */
    private static function row(object $member): array
    {
        return [
            'student_id' => (int) $member->student_id,
            'student_number' => (int) $member->student_number,
            'name' => (string) $member->name,
        ];
    }
}
