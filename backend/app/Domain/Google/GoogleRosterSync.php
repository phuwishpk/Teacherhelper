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
 * "ซิงก์รายชื่อ" (DESIGN §19.2, POST /classrooms/{id}/google-roster/sync and
 * SyncClassroomRosterJob) brings a linked classroom in line with the course:
 *
 *   left       a matched account no longer in the course: left_course_at is
 *              set and the match cleared (google_email stays for the
 *              teacher to see). The student and their scores stay.
 *   rematched  an unmatched account that is an existing student: the same
 *              e-mail as a student who left (they came back), or else the
 *              same full name (RosterMatcher passes 1-2, unique on both
 *              sides) as a student never matched, e.g. in a room linked by
 *              hand (§18.7) before its roster was matched. left_course_at is
 *              cleared.
 *   added      any other account not in the ignore list: a new student after
 *              the highest number, in ThaiNameSorter order, matched at once.
 *              Their PIN is in the answer, shown once.
 *
 * Names in the app are never overwritten. Import rows that are not scanned
 * yet follow the new matches (GoogleRoster::followRoster).
 */
final class GoogleRosterSync
{
    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly StudentEnroller $enroller,
    ) {}

    /**
     * @return array{added: list<array{student_id: int, student_number: int, name: string, pin: string}>, left: list<array{student_id: int, student_number: int, name: string}>, rematched: list<array{student_id: int, student_number: int, name: string}>}
     *
     * @throws ApiException Google errors, 422 classroom_not_linked
     */
    public function sync(User $teacher, Classroom $classroom): array
    {
        $link = GoogleRoster::linkOf($classroom);
        $accounts = $this->accounts->call($teacher, fn (GoogleApi $api) => $api->courseStudents($link->course_id), GoogleRoster::COURSE_GONE);

        return $this->apply($classroom, $link, $accounts);
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts  the course roster from Google
     * @return array{added: list<array{student_id: int, student_number: int, name: string, pin: string}>, left: list<array{student_id: int, student_number: int, name: string}>, rematched: list<array{student_id: int, student_number: int, name: string}>}
     */
    public function apply(Classroom $classroom, ClassroomGoogleLink $link, array $accounts): array
    {
        $result = DB::transaction(function () use ($classroom, $link, $accounts) {
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

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
                    $this->updateMember($classroom, $member->student_id, ['google_user_id' => null, 'left_course_at' => $member->left_course_at ?? now()]);
                    $left[] = self::row($member);
                    $pairs[$googleId] = null;
                }
                $free[(int) $member->student_id] = $member;
            }

            $unmatched = array_values(array_filter($accounts, fn (array $a) => ! isset($matched[$a['google_user_id']]) && ! isset($ignored[$a['google_user_id']])));

            // Back in the course with the same account e-mail.
            $byEmail = [];
            foreach ($unmatched as $account) {
                if ($account['email'] !== null) {
                    $byEmail[mb_strtolower($account['email'])][] = $account;
                }
            }
            foreach ($free as $studentId => $member) {
                $email = $member->google_email !== null ? mb_strtolower($member->google_email) : null;
                if ($email === null || count($byEmail[$email] ?? []) !== 1) {
                    continue;
                }
                $account = $byEmail[$email][0];
                if (isset($matched[$account['google_user_id']])) {
                    continue;
                }
                $this->match($classroom, $studentId, $account);
                $matched[$account['google_user_id']] = true;
                $pairs[$account['google_user_id']] = $studentId;
                $rematched[] = self::row($member);
                unset($free[$studentId]);
            }

            // The same full name as a student without an account.
            $unmatched = array_values(array_filter($unmatched, fn (array $a) => ! isset($matched[$a['google_user_id']])));
            $candidates = array_map(fn ($m) => ['id' => (int) $m->student_id, 'name' => (string) $m->name], array_values($free));
            foreach (RosterMatcher::suggest($unmatched, $candidates, false) as $googleId => $studentId) {
                $googleId = (string) $googleId;
                $this->match($classroom, $studentId, $byId[$googleId]);
                $matched[$googleId] = true;
                $pairs[$googleId] = $studentId;
                $rematched[] = self::row($free[$studentId]);
            }

            // Everyone else is new.
            $new = array_values(array_filter($unmatched, fn (array $a) => ! isset($matched[$a['google_user_id']])));
            $added = $this->add($classroom, $new, $members->max('student_number') ?? 0);
            foreach ($added as $row) {
                $pairs[$row['google_user_id']] = $row['student_id'];
            }

            GoogleRoster::followRoster($classroom, $pairs);
            $link->forceFill(['roster_synced_at' => now()])->save();

            return [
                'added' => array_map(fn (array $r) => array_diff_key($r, ['google_user_id' => true]), $added),
                'left' => $left,
                'rematched' => $rematched,
            ];
        });

        Log::info('google.roster_synced', [
            'classroom_id' => $classroom->id,
            'added' => count($result['added']),
            'left' => count($result['left']),
            'rematched' => count($result['rematched']),
        ]);

        return $result;
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts
     * @return list<array{google_user_id: string, student_id: int, student_number: int, name: string, pin: string}>
     */
    private function add(Classroom $classroom, array $accounts, int $highest): array
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
                $rows[] = ['name' => $account['sort_name'], 'student_number' => $next++];
            }
            foreach ($this->enroller->enroll($classroom, $rows) as $i => $created) {
                $this->match($classroom, $created['student']->id, $chunk[$i]);
                $added[] = [
                    'google_user_id' => $chunk[$i]['google_user_id'],
                    'student_id' => $created['student']->id,
                    'student_number' => $created['student_number'],
                    'name' => $created['student']->name,
                    'pin' => $created['pin'],
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
