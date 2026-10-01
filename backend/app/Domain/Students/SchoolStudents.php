<?php

namespace App\Domain\Students;

use App\Domain\Google\NameNormalizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The students of a school as one list (DESIGN §24.4): the search behind
 * "เลือกนักเรียนที่มีอยู่" and the merge picker, the pairs that look like one
 * child with two accounts, and the payload both answer with: id, name,
 * student code, has_google and the classrooms the student is in. Never an
 * email, a PIN or a result (§24.14).
 */
final class SchoolStudents
{
    public const MIN_QUERY = 2;

    public const MAX_RESULTS = 20;

    public const MAX_PAIRS = 100;

    /**
     * Active, unmerged students of the school whose name (titles, spacing and
     * punctuation ignored, NameNormalizer) contains every word of the query, or whose
     * student code starts with it. At most MAX_RESULTS, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function search(int $schoolId, string $query): array
    {
        $code = StudentCode::normalize($query);
        $tokens = NameNormalizer::tokens($query);

        $candidates = self::activeStudents($schoolId)
            ->where(function ($q) use ($tokens, $code) {
                if ($tokens !== []) {
                    $q->where(function ($names) use ($tokens) {
                        foreach ($tokens as $token) {
                            $names->where('name', 'like', '%'.addcslashes($token, '%_\\').'%');
                        }
                    });
                }
                if ($code !== null) {
                    $q->orWhere('student_code', 'like', addcslashes($code, '%_\\').'%');
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS * 10)
            ->get(['id', 'name', 'student_code']);

        $matches = $candidates->filter(function (User $s) use ($tokens, $code) {
            $key = NameNormalizer::key($s->name);
            $byName = $tokens !== [] && collect($tokens)->every(fn (string $t) => str_contains($key, $t));

            return $byName || ($code !== null && $s->student_code !== null && str_starts_with($s->student_code, $code));
        })->take(self::MAX_RESULTS)->values();

        return $this->payloads($matches);
    }

    /**
     * Pairs of active accounts of the school that look like one child
     * (DESIGN §24.4): the same Google Classroom account, the same Google
     * email (any case), or the same full name after normalising (passes 1-2
     * of RosterMatcher). Only a suggestion: nothing is merged.
     *
     * @param  list<int>|null  $involving  only pairs with at least one of these students (null: all)
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>, reasons: list<string>}>
     */
    public function duplicateCandidates(int $schoolId, ?array $involving = null): array
    {
        $students = self::activeStudents($schoolId)->get(['id', 'name', 'student_code'])->keyBy('id');
        $links = DB::table('classroom_students')
            ->whereIn('student_id', $students->keys())
            ->where(fn ($q) => $q->whereNotNull('google_user_id')->orWhereNotNull('google_email'))
            ->get(['student_id', 'google_user_id', 'google_email']);

        /** @var array<string, list<int>> $groups "reason|key" => student ids */
        $groups = [];
        foreach ($links as $link) {
            if ($link->google_user_id !== null) {
                $groups['google_user|'.$link->google_user_id][] = (int) $link->student_id;
            }
            if ($link->google_email !== null && trim((string) $link->google_email) !== '') {
                $groups['email|'.mb_strtolower(trim((string) $link->google_email))][] = (int) $link->student_id;
            }
        }
        foreach ($students as $student) {
            $key = NameNormalizer::key($student->name);
            if ($key !== '') {
                $groups['name|'.$key][] = $student->id;
            }
            $unordered = NameNormalizer::unorderedKey($student->name);
            if ($unordered !== '' && $unordered !== $key) {
                $groups['name_unordered|'.$unordered][] = $student->id;
            }
        }

        /** @var array<string, array{0: int, 1: int, reasons: array<string, true>}> $pairs */
        $pairs = [];
        foreach ($groups as $group => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $reason = explode('|', $group, 2)[0] === 'name_unordered' ? 'name' : explode('|', $group, 2)[0];
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    if ($involving !== null && ! in_array($ids[$i], $involving, true) && ! in_array($ids[$j], $involving, true)) {
                        continue;
                    }
                    $pairKey = $ids[$i].'-'.$ids[$j];
                    $pairs[$pairKey] ??= [$ids[$i], $ids[$j], 'reasons' => []];
                    $pairs[$pairKey]['reasons'][$reason] = true;
                }
            }
        }
        ksort($pairs, SORT_NATURAL);
        $pairs = array_slice(array_values($pairs), 0, self::MAX_PAIRS);

        $ids = [];
        foreach ($pairs as $pair) {
            $ids[] = $pair[0];
            $ids[] = $pair[1];
        }
        $payloads = collect($this->payloads($students->only(array_unique($ids))->values()))->keyBy('id');
        $order = ['google_user', 'email', 'name'];

        return array_map(fn (array $pair) => [
            'a' => $payloads[$pair[0]],
            'b' => $payloads[$pair[1]],
            'reasons' => array_values(array_filter($order, fn ($r) => isset($pair['reasons'][$r]))),
        ], $pairs);
    }

    /**
     * {id, name, student_code, has_google, classrooms: [{id, name,
     * academic_year, student_number, closed}]} of each student.
     *
     * @param  Collection<int, User>  $students
     * @return list<array<string, mixed>>
     */
    public function payloads(Collection $students): array
    {
        $ids = $students->pluck('id')->all();
        $rows = $ids === [] ? collect() : DB::table('classroom_students')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
            ->whereIn('classroom_students.student_id', $ids)
            ->orderByDesc('classrooms.academic_year')
            ->orderBy('classrooms.name')
            ->get(['classroom_students.student_id', 'classroom_students.student_number', 'classrooms.id', 'classrooms.name', 'classrooms.academic_year', 'classrooms.closed_at'])
            ->groupBy('student_id');
        $linked = $ids === [] ? [] : array_flip(DB::table('user_google_identities')->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        return $students->map(function (User $student) use ($rows, $linked) {
            $classes = $rows->get($student->id, collect());

            return [
                'id' => $student->id,
                'name' => $student->name,
                'student_code' => $student->student_code,
                // A linked Google sign-in account (§24.9, user_google_identities).
                'has_google' => isset($linked[$student->id]),
                'classrooms' => $classes->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => $c->name,
                    'academic_year' => (int) $c->academic_year,
                    'student_number' => (int) $c->student_number,
                    'closed' => $c->closed_at !== null,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return Builder<User> */
    private static function activeStudents(int $schoolId)
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->where('role', User::ROLE_STUDENT)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('merged_into_id');
    }
}
