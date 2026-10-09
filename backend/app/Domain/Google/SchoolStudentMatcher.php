<?php

namespace App\Domain\Google;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which existing student of the school each Google Classroom account is
 * (DESIGN §24.10), so an import or a roster sync enrols the student's one
 * account instead of creating a second one. Passes, strongest first:
 *
 *   1. google       the account signs in to Krucheck (user_google_identities.google_sub);
 *   2. classroom_user  a classroom of the school has it matched (classroom_students.google_user_id);
 *   3. email        a classroom of the school saw the same e-mail (classroom_students.google_email, any case);
 *   4. name         the same full name after NameNormalizer (passes 1-2 of RosterMatcher).
 *
 * Passes 1-3 count only when they find exactly one student: two students
 * mean duplicate accounts the teacher should merge first, and the account
 * then gets no match at all (not even by name). Pass 4 is a suggestion: it
 * needs the name to be unique among the school's students and among the
 * accounts. A student is matched to one account at most; the stronger pass
 * (then the earlier account) wins. Only active, unmerged students of the
 * school are candidates. The student code is not in Classroom, so it plays
 * no part here.
 */
final class SchoolStudentMatcher
{
    public const BY_GOOGLE = 'google';

    public const BY_CLASSROOM_USER = 'classroom_user';

    public const BY_EMAIL = 'email';

    public const BY_NAME = 'name';

    /** Passes that link an account to a student without asking (all but the name). */
    public const CERTAIN = [self::BY_GOOGLE, self::BY_CLASSROOM_USER, self::BY_EMAIL];

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts
     * @param  bool  $byName  run pass 4 (the preview does; syncs do not)
     * @return array<string, array{student_id: int, matched_by: string}> google_user_id => match
     */
    public function match(int $schoolId, array $accounts, bool $byName = true): array
    {
        if ($accounts === []) {
            return [];
        }
        $matches = [];
        $taken = [];
        $ambiguous = [];
        $assign = function (string $googleUserId, int $studentId, string $by) use (&$matches, &$taken): void {
            if (isset($matches[$googleUserId]) || isset($taken[$studentId])) {
                return;
            }
            $matches[$googleUserId] = ['student_id' => $studentId, 'matched_by' => $by];
            $taken[$studentId] = true;
        };
        $ids = array_values(array_unique(array_map(fn (array $a) => (string) $a['google_user_id'], $accounts)));

        // 1. A linked Google sign-in account (google_sub is unique).
        $identities = DB::table('user_google_identities')
            ->join('users', 'users.id', '=', 'user_google_identities.user_id')
            ->whereIn('user_google_identities.google_sub', $ids)
            ->where(fn (Builder $q) => self::activeStudents($q, $schoolId))
            ->pluck('users.id', 'user_google_identities.google_sub');
        foreach ($accounts as $account) {
            $id = $identities[$account['google_user_id']] ?? null;
            if ($id !== null) {
                $assign($account['google_user_id'], (int) $id, self::BY_GOOGLE);
            }
        }

        // 2. The account matched in any classroom of the school.
        $byUser = $this->groups(DB::table('classroom_students')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
            ->join('users', 'users.id', '=', 'classroom_students.student_id')
            ->where('classrooms.school_id', $schoolId)
            ->whereIn('classroom_students.google_user_id', $ids)
            ->where(fn (Builder $q) => self::activeStudents($q, $schoolId))
            ->get(['classroom_students.google_user_id as k', 'users.id as student_id']));
        foreach ($accounts as $account) {
            $students = $byUser[$account['google_user_id']] ?? [];
            if (isset($matches[$account['google_user_id']]) || $students === []) {
                continue;
            }
            if (count($students) > 1) {
                $ambiguous[$account['google_user_id']] = true;

                continue;
            }
            $assign($account['google_user_id'], $students[0], self::BY_CLASSROOM_USER);
        }

        // 3. The same e-mail, when only one account of the roster carries it.
        $emailCount = [];
        foreach ($accounts as $account) {
            $email = self::email($account);
            if ($email !== null) {
                $emailCount[$email] = ($emailCount[$email] ?? 0) + 1;
            }
        }
        $emails = array_keys(array_filter($emailCount, fn (int $n) => $n === 1));
        $byEmail = $emails === [] ? [] : $this->groups(DB::table('classroom_students')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
            ->join('users', 'users.id', '=', 'classroom_students.student_id')
            ->where('classrooms.school_id', $schoolId)
            ->whereIn(DB::raw('LOWER(classroom_students.google_email)'), $emails)
            ->where(fn (Builder $q) => self::activeStudents($q, $schoolId))
            ->get([DB::raw('LOWER(classroom_students.google_email) as k'), 'users.id as student_id']));
        foreach ($accounts as $account) {
            $email = self::email($account);
            if ($email === null || isset($matches[$account['google_user_id']]) || isset($ambiguous[$account['google_user_id']])
                || ($emailCount[$email] ?? 0) !== 1 || ($byEmail[$email] ?? []) === []) {
                continue;
            }
            if (count($byEmail[$email]) > 1) {
                $ambiguous[$account['google_user_id']] = true;

                continue;
            }
            $assign($account['google_user_id'], $byEmail[$email][0], self::BY_EMAIL);
        }

        if (! $byName) {
            return $matches;
        }

        // 4. A full name unique in the school and in the roster.
        $left = array_values(array_filter($accounts, fn (array $a) => ! isset($matches[$a['google_user_id']]) && ! isset($ambiguous[$a['google_user_id']])));
        if ($left === []) {
            return $matches;
        }
        $students = DB::table('users')->where(fn (Builder $q) => self::activeStudents($q, $schoolId))->get(['users.id', 'users.name']);
        foreach ([[NameNormalizer::class, 'key'], [NameNormalizer::class, 'unorderedKey']] as $keyOf) {
            $studentsByKey = [];
            foreach ($students as $student) {
                $key = $keyOf((string) $student->name);
                if ($key !== '') {
                    $studentsByKey[$key][] = (int) $student->id;
                }
            }
            $accountsByKey = [];
            foreach ($left as $account) {
                if (! isset($matches[$account['google_user_id']])) {
                    $key = $keyOf(GoogleRoster::studentName($account));
                    if ($key !== '') {
                        $accountsByKey[$key][] = $account['google_user_id'];
                    }
                }
            }
            foreach ($accountsByKey as $key => $googleUserIds) {
                $ids = $studentsByKey[$key] ?? [];
                if (count($googleUserIds) === 1 && count($ids) === 1) {
                    $assign((string) $googleUserIds[0], $ids[0], self::BY_NAME);
                }
            }
        }

        return $matches;
    }

    /**
     * @param  iterable<object{k: string, student_id: int|string}>  $rows
     * @return array<string, list<int>> key => distinct student ids
     */
    private function groups(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row->k][(int) $row->student_id] = true;
        }

        return array_map(fn (array $ids) => array_keys($ids), $groups);
    }

    /**
     * @param  array{email: string|null}  $account
     */
    private static function email(array $account): ?string
    {
        $email = $account['email'] === null ? '' : mb_strtolower(trim($account['email']));

        return $email === '' ? null : $email;
    }

    /** Active, unmerged students of the school (columns of `users`). */
    private static function activeStudents(Builder $query, int $schoolId): void
    {
        $query->where('users.school_id', $schoolId)
            ->where('users.role', User::ROLE_STUDENT)
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereNull('users.merged_into_id');
    }
}
