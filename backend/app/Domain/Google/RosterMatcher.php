<?php

namespace App\Domain\Google;

/**
 * Suggests which student of the classroom each Google Classroom account
 * belongs to (DESIGN §18.6 GET /classrooms/{id}/google-roster), by name,
 * in passes that go from strict to loose, and never suggests one student
 * twice:
 *
 *   1. the same normalised name (titles, spaces and punctuation ignored);
 *   2. the same words in another order ("Jaidee Somchai");
 *   3. the same first name;
 *
 * each only when exactly one account and exactly one student still
 * unassigned carry the key (two children with one name get no guess).
 *
 * Students already matched to another account are not offered. The teacher
 * confirms or corrects every pair; a suggestion is only a starting point.
 */
final class RosterMatcher
{
    /**
     * @param  list<array{google_user_id: string, name: string}>  $accounts
     * @param  list<array{id: int, name: string}>  $students  candidates (not matched to another account)
     * @return array<string, int> google_user_id => suggested student id
     */
    public static function suggest(array $accounts, array $students): array
    {
        $suggested = [];
        $taken = [];

        foreach ([[NameNormalizer::class, 'key'], [NameNormalizer::class, 'unorderedKey']] as $keyOf) {
            $byKey = [];
            foreach ($students as $student) {
                if (isset($taken[$student['id']])) {
                    continue;
                }
                $key = $keyOf($student['name']);
                if ($key !== '') {
                    $byKey[$key][] = $student['id'];
                }
            }
            $accountsPerKey = [];
            foreach ($accounts as $account) {
                if (! isset($suggested[$account['google_user_id']])) {
                    $key = $keyOf($account['name']);
                    $accountsPerKey[$key] = ($accountsPerKey[$key] ?? 0) + 1;
                }
            }
            foreach ($accounts as $account) {
                if (isset($suggested[$account['google_user_id']])) {
                    continue;
                }
                $key = $keyOf($account['name']);
                $ids = $byKey[$key] ?? [];
                $free = array_values(array_filter($ids, fn (int $id) => ! isset($taken[$id])));
                if (count($free) === 1 && ($accountsPerKey[$key] ?? 0) === 1) {
                    $suggested[$account['google_user_id']] = $free[0];
                    $taken[$free[0]] = true;
                }
            }
        }

        // Pass 3: a first name that is unique on both sides.
        $studentsByFirst = [];
        foreach ($students as $student) {
            if (! isset($taken[$student['id']]) && ($first = NameNormalizer::firstName($student['name'])) !== '') {
                $studentsByFirst[$first][] = $student['id'];
            }
        }
        $accountsByFirst = [];
        foreach ($accounts as $account) {
            if (! isset($suggested[$account['google_user_id']]) && ($first = NameNormalizer::firstName($account['name'])) !== '') {
                $accountsByFirst[$first][] = $account['google_user_id'];
            }
        }
        foreach ($accountsByFirst as $first => $userIds) {
            $ids = $studentsByFirst[$first] ?? [];
            if (count($userIds) === 1 && count($ids) === 1) {
                $suggested[$userIds[0]] = $ids[0];
            }
        }

        return $suggested;
    }
}
