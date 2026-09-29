<?php

namespace App\Domain\Mastery;

use App\Domain\Grading\Understanding;
use App\Models\Mastery;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\Response;
use App\Models\SkillObservation;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * EWMA mastery (DESIGN §14.2):
 *
 *   m1 = s1,  mt = αt · st + (1 − αt) · mt−1,  α = 0.30 homework / 0.15 practice
 *
 * over the student's skill_observations ordered by observed_at. Only
 * published answers count (§14.2): recordSubmission() writes one
 * observation per (response, skill) with score_ratio = final_score /
 * max_points at publish time, and replaces the rows when the submission is
 * published again (rescan) or an accepted appeal changed a score. Every
 * change recomputes the whole (student, skill) series, which is cheap
 * because the series are short.
 */
final class MasteryCalculator
{
    public const ALPHA_HOMEWORK = 0.30;

    public const ALPHA_PRACTICE = 0.15;

    /** Skills below this mastery get practice recommendations (§14.1). */
    public const WEAK_BELOW = 0.75;

    /** Fewer observations than this show as "ข้อมูลยังน้อย" (§14.2). */
    public const MIN_OBS_FOR_LEVEL = 2;

    public const LEVEL_TOO_LITTLE = 'too_little';

    /**
     * The pure formula. Observations must already be in observed_at order.
     *
     * @param  list<array{score_ratio: float|int|string, source: string}>  $observations
     * @return array{value: float, n_obs: int}|null null without observations
     */
    public static function ewma(array $observations): ?array
    {
        $m = null;
        $n = 0;
        foreach ($observations as $observation) {
            $s = max(0.0, min(1.0, (float) $observation['score_ratio']));
            $alpha = $observation['source'] === SkillObservation::SOURCE_PRACTICE ? self::ALPHA_PRACTICE : self::ALPHA_HOMEWORK;
            $m = $m === null ? $s : $alpha * $s + (1.0 - $alpha) * $m;
            $n++;
        }

        return $m === null ? null : ['value' => self::round3($m), 'n_obs' => $n];
    }

    /**
     * The mastery after each observation (DESIGN §20.4 chart 1): the same
     * formula as ewma(), keeping every intermediate value. The last one
     * equals ewma()['value'].
     *
     * @param  list<array{score_ratio: float|int|string, source: string}>  $observations
     * @return list<float>
     */
    public static function ewmaSeries(array $observations): array
    {
        $m = null;
        $out = [];
        foreach ($observations as $observation) {
            $s = max(0.0, min(1.0, (float) $observation['score_ratio']));
            $alpha = $observation['source'] === SkillObservation::SOURCE_PRACTICE ? self::ALPHA_PRACTICE : self::ALPHA_HOMEWORK;
            $m = $m === null ? $s : $alpha * $s + (1.0 - $alpha) * $m;
            $out[] = self::round3($m);
        }

        return $out;
    }

    /**
     * Half-up to 3 decimals, the same on every PHP version: round() pre-rounds
     * differently before 8.4, so 0.7025 could come out as 0.702 on one server
     * and 0.703 on another (see ScoreRounding).
     */
    public static function round3(float $value): float
    {
        return floor($value * 1000 + 0.5 + 1e-7) / 1000;
    }

    /** good | partial | not_yet (§11.7 cut points), or too_little below MIN_OBS_FOR_LEVEL. */
    public static function level(float $value, int $nObs): string
    {
        return $nObs < self::MIN_OBS_FOR_LEVEL ? self::LEVEL_TOO_LITTLE : Understanding::fromU($value);
    }

    /** Recomputes one (student, skill) from its observations; deletes the row when none are left. */
    public function recompute(int $studentId, int $skillId): ?Mastery
    {
        $observations = SkillObservation::query()
            ->where('student_id', $studentId)
            ->where('skill_id', $skillId)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get(['score_ratio', 'source'])
            ->map(fn (SkillObservation $o) => ['score_ratio' => $o->score_ratio, 'source' => $o->source])
            ->all();

        $result = self::ewma($observations);
        if ($result === null) {
            Mastery::query()->where('student_id', $studentId)->where('skill_id', $skillId)->delete();

            return null;
        }

        $now = now();
        Mastery::query()->upsert(
            [[
                'student_id' => $studentId,
                'skill_id' => $skillId,
                'value' => $result['value'],
                'n_obs' => $result['n_obs'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['student_id', 'skill_id'],
            ['value', 'n_obs', 'updated_at'],
        );

        return self::find($studentId, $skillId);
    }

    public static function find(int $studentId, int $skillId): ?Mastery
    {
        return Mastery::query()->where('student_id', $studentId)->where('skill_id', $skillId)->first();
    }

    /**
     * Observations of every answer of a published submission, one per skill
     * of the question (§14.2), observed at the time of publishing. Earlier
     * rows of the same answers are replaced. Not published (any more): the
     * rows are removed instead, so a submission reopened by a confirmed
     * rescan (SubmissionReopened) stops counting until its next publish.
     *
     * @return int observations written
     */
    public function recordSubmission(int $submissionId): int
    {
        return DB::transaction(function () use ($submissionId) {
            $submission = Submission::query()->find($submissionId);
            if ($submission === null) {
                return 0;
            }
            $responses = Response::query()->where('submission_id', $submission->id)->with('question.skills')->get();
            $responseIds = $responses->modelKeys();

            $touched = SkillObservation::query()->whereIn('response_id', $responseIds)->distinct()->pluck('skill_id')->all();
            SkillObservation::query()->whereIn('response_id', $responseIds)->delete();

            $rows = [];
            if ($submission->isPublished()) {
                $observedAt = $submission->published_at ?? now();
                foreach ($responses as $response) {
                    $question = $response->question;
                    $score = $response->effectiveScore();
                    if ($question === null || $score === null || (float) $question->max_points <= 0) {
                        continue;
                    }
                    $ratio = self::round3(max(0.0, min(1.0, $score / (float) $question->max_points)));
                    foreach ($question->skills as $skill) {
                        $rows[] = [
                            'student_id' => $submission->student_id,
                            'skill_id' => $skill->id,
                            'source' => SkillObservation::SOURCE_HOMEWORK,
                            'response_id' => $response->id,
                            'practice_attempt_id' => null,
                            'score_ratio' => $ratio,
                            'observed_at' => $observedAt,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $touched[] = $skill->id;
                    }
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    SkillObservation::query()->insert($chunk);
                }
            }

            foreach (array_unique($touched) as $skillId) {
                $this->recompute($submission->student_id, (int) $skillId);
            }

            return count($rows);
        });
    }

    /** The observation of a practice attempt (α = 0.15) and the skill's new mastery. */
    public function recordPracticeAttempt(PracticeAttempt $attempt, PracticeItem $item): ?Mastery
    {
        SkillObservation::create([
            'student_id' => $attempt->student_id,
            'skill_id' => $item->skill_id,
            'source' => SkillObservation::SOURCE_PRACTICE,
            'response_id' => null,
            'practice_attempt_id' => $attempt->id,
            'score_ratio' => self::round3(max(0.0, min(1.0, (float) $attempt->score_ratio))),
            'observed_at' => $attempt->created_at ?? now(),
        ]);

        return $this->recompute($attempt->student_id, $item->skill_id);
    }
}
