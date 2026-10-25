<?php

namespace App\Domain\Mastery;

/**
 * The roll-up formula of DESIGN §20.3, pure (no database): the value of a
 * node (a standard, a unit or the course) from the mastery of the
 * indicators planned under it.
 *
 *   A(node, s)     = { i ∈ I(node) : mastery(s, i) exists and n_obs ≥ 1 }
 *   value(node, s) = mean{ mastery(s, i).value : i ∈ A(node, s) }   (plain mean)
 *                    null when A is empty ("ยังไม่ได้ประเมิน")
 *   coverage       = |A(node, s)| / |I(node)|   (null when I is empty)
 *
 * A classroom (build step 9) is the plain mean of its students' node
 * values, counting only the students with a value (each student weighs
 * the same, whatever number of indicators they were assessed on); an
 * indicator is assessed for the classroom when at least one student has
 * it.
 */
final class MasteryRollup
{
    /**
     * @param  list<int>  $planned  I(node): skill ids
     * @param  array<int, array{value: float, n_obs: int}>  $mastery  the student's rows keyed by skill id
     * @return array{value: float|null, assessed: int, planned: int, coverage: float|null, passed: int}
     */
    public static function student(array $planned, array $mastery, float $passThreshold): array
    {
        $values = [];
        foreach (array_unique($planned) as $skillId) {
            $row = $mastery[$skillId] ?? null;
            if ($row !== null && $row['n_obs'] >= 1) {
                $values[] = (float) $row['value'];
            }
        }
        $planned = count(array_unique($planned));

        return [
            'value' => $values === [] ? null : MasteryCalculator::round3(array_sum($values) / count($values)),
            'assessed' => count($values),
            'planned' => $planned,
            'coverage' => $planned === 0 ? null : MasteryCalculator::round3(count($values) / $planned),
            'passed' => count(array_filter($values, fn (float $v) => self::passes($v, $passThreshold))),
        ];
    }

    /**
     * @param  list<int>  $planned  I(node): skill ids
     * @param  array<int, array<int, array{value: float, n_obs: int}>>  $byStudent  student id => skill id => row
     * @return array{value: float|null, assessed: int, planned: int, coverage: float|null, students_assessed: int, student_count: int}
     */
    public static function classroom(array $planned, array $byStudent, float $passThreshold): array
    {
        $planned = array_values(array_unique($planned));
        $values = [];
        $assessed = [];
        foreach ($byStudent as $mastery) {
            $node = self::student($planned, $mastery, $passThreshold);
            if ($node['value'] !== null) {
                $values[] = $node['value'];
            }
            foreach ($planned as $skillId) {
                if (($mastery[$skillId]['n_obs'] ?? 0) >= 1) {
                    $assessed[$skillId] = true;
                }
            }
        }

        return [
            'value' => $values === [] ? null : MasteryCalculator::round3(array_sum($values) / count($values)),
            'assessed' => count($assessed),
            'planned' => count($planned),
            'coverage' => $planned === [] ? null : MasteryCalculator::round3(count($assessed) / count($planned)),
            'students_assessed' => count($values),
            'student_count' => count($byStudent),
        ];
    }

    /** "ผ่าน" of an indicator (DESIGN §20.3): mastery ≥ MASTERY_PASS_THRESHOLD. */
    public static function passes(float $value, float $threshold): bool
    {
        return $value + 1e-9 >= $threshold;
    }
}
