<?php

namespace App\Domain\Mastery;

use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Skill;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * Classical item statistics of an assignment (DESIGN §14.3), from published
 * submissions only:
 *
 * - p (difficulty) = mean(final_score / max_points) per question;
 * - r (discrimination) = mean ratio of the top 27 % minus the bottom 27 %
 *   of students by the total that counts (Submission::effectiveTotal(),
 *   COALESCE(total_override, total_score), §22.13, so a total taken from
 *   Classroom ranks the student correctly), shown from MIN_FOR_R published
 *   students up, null ("ข้อมูลน้อย") below that;
 * - most missed = questions by p ascending;
 * - skill × error type heatmap = counts of final_error_types over the
 *   skills of each question.
 */
final class ItemAnalysis
{
    public const MIN_FOR_R = 20;

    public const GROUP_FRACTION = 0.27;

    /**
     * @return array{
     *   published_count: int,
     *   min_count_for_r: int,
     *   items: list<array{question_id: int, position: int, type: string, max_points: float, prompt_text: string, n: int, p: float|null, r: float|null}>,
     *   most_missed: list<int>,
     *   skill_error_counts: list<array{skill: array{id: int, code: string, name: string, subject_id: int, grade_level: int|null}, error_type: string, count: int}>
     * }
     */
    public static function forAssignment(Assignment $assignment): array
    {
        $questions = $assignment->questions()->with('skills')->get();
        $submissions = Submission::query()
            ->where('assignment_id', $assignment->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->get(['id', 'total_score', 'total_override']);
        $published = $submissions->count();

        $responses = $published === 0
            ? collect()
            : Response::query()->whereIn('submission_id', $submissions->modelKeys())->get(['id', 'submission_id', 'question_id', 'ai_score', 'final_score', 'final_error_types']);

        /** @var array<int, array<int, float>> question_id => [submission_id => ratio] */
        $ratios = [];
        $maxPoints = $questions->mapWithKeys(fn (Question $q) => [$q->id => (float) $q->max_points]);
        foreach ($responses as $response) {
            $max = $maxPoints[$response->question_id] ?? 0.0;
            $score = $response->effectiveScore();
            if ($score === null || $max <= 0) {
                continue;
            }
            $ratios[$response->question_id][$response->submission_id] = max(0.0, min(1.0, $score / $max));
        }

        [$top, $bottom] = self::groups($submissions);

        $items = [];
        foreach ($questions as $question) {
            $values = $ratios[$question->id] ?? [];
            $items[] = [
                'question_id' => $question->id,
                'position' => (int) $question->position,
                'type' => $question->type,
                'max_points' => (float) $question->max_points,
                'prompt_text' => $question->prompt_text,
                'n' => count($values),
                'p' => $values === [] ? null : round(array_sum($values) / count($values), 3),
                'r' => $top === null ? null : self::discrimination($values, $top, $bottom),
            ];
        }

        $mostMissed = array_values(array_filter($items, fn (array $i) => $i['p'] !== null));
        usort($mostMissed, fn (array $a, array $b) => [$a['p'], $a['position']] <=> [$b['p'], $b['position']]);

        return [
            'published_count' => $published,
            'min_count_for_r' => self::MIN_FOR_R,
            'items' => $items,
            'most_missed' => array_column($mostMissed, 'question_id'),
            'skill_error_counts' => self::heatmap($questions, $responses),
        ];
    }

    /**
     * Top and bottom 27 % of the published submissions by the total that
     * counts (effectiveTotal(), ties broken by id so the groups are stable),
     * or [null, null] below MIN_FOR_R. The submissions need id, total_score
     * and total_override. Also the groups of the option analysis of an exam
     * (ExamOptionAnalysis, §22.13).
     *
     * @param  Collection<int, Submission>  $submissions
     * @return array{0: list<int>|null, 1: list<int>|null}
     */
    public static function groups(Collection $submissions): array
    {
        $published = $submissions->count();
        if ($published < self::MIN_FOR_R) {
            return [null, null];
        }
        $size = max(1, (int) round(self::GROUP_FRACTION * $published));
        $ordered = $submissions
            ->sortBy(fn (Submission $s) => [-($s->effectiveTotal() ?? 0.0), $s->id])
            ->pluck('id')
            ->values()
            ->all();

        return [array_slice($ordered, 0, $size), array_slice(array_reverse($ordered), 0, $size)];
    }

    /**
     * @param  array<int, float>  $values  submission_id => ratio
     * @param  list<int>  $top
     * @param  list<int>  $bottom
     */
    private static function discrimination(array $values, array $top, array $bottom): ?float
    {
        $mean = function (array $ids) use ($values): ?float {
            $picked = array_values(array_intersect_key($values, array_flip($ids)));

            return $picked === [] ? null : array_sum($picked) / count($picked);
        };
        $high = $mean($top);
        $low = $mean($bottom);

        return $high === null || $low === null ? null : round($high - $low, 3);
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @param  Collection<int, Response>  $responses
     * @return list<array{skill: array{id: int, code: string, name: string, subject_id: int, grade_level: int|null}, error_type: string, count: int}>
     */
    private static function heatmap($questions, $responses): array
    {
        /** @var array<int, Skill> */
        $skills = [];
        /** @var array<int, list<int>> question_id => skill ids */
        $questionSkills = [];
        foreach ($questions as $question) {
            foreach ($question->skills as $skill) {
                $skills[$skill->id] = $skill;
                $questionSkills[$question->id][] = $skill->id;
            }
        }

        /** @var array<int, array<string, int>> */
        $counts = [];
        foreach ($responses as $response) {
            $types = $response->final_error_types;
            if (! is_array($types) || $types === []) {
                continue;
            }
            foreach ($questionSkills[$response->question_id] ?? [] as $skillId) {
                foreach (array_unique(array_map('strval', $types)) as $type) {
                    $counts[$skillId][$type] = ($counts[$skillId][$type] ?? 0) + 1;
                }
            }
        }

        $out = [];
        ksort($counts);
        foreach ($counts as $skillId => $byType) {
            ksort($byType);
            foreach ($byType as $type => $count) {
                $skill = $skills[$skillId];
                $out[] = [
                    'skill' => [
                        'id' => $skill->id,
                        'code' => $skill->code,
                        'name' => $skill->name,
                        'subject_id' => $skill->subject_id,
                        'grade_level' => $skill->grade_level,
                    ],
                    'error_type' => $type,
                    'count' => $count,
                ];
            }
        }
        usort($out, fn (array $a, array $b) => [$a['skill']['code'], $a['error_type']] <=> [$b['skill']['code'], $b['error_type']]);

        return $out;
    }
}
