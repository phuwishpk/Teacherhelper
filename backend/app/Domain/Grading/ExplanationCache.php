<?php

namespace App\Domain\Grading;

use App\Models\Question;
use Illuminate\Support\Facades\DB;

/**
 * Reuse of explanations for identical wrong answers (DESIGN §21.7 item 6),
 * table explanation_cache: one text per (question, answer key hash).
 *
 * The key, after the §11.4 normalisation (AnswerMatcher::normalize), with a
 * prefix per kind of explanation so the kinds never collide:
 *
 *   short                         "short:" + the answer
 *   show_work, every step valid   "final:" + the final answer (the
 *                                 explanation is about the final answer only)
 *   show_work, a step not valid   "steps:" + every line in line order + the
 *                                 final answer (one different line = its own
 *                                 explanation)
 *   open, mcq, blank, empty       no key: never reused
 *
 * The hashed key also carries a fingerprint of what the explanation was
 * written against (prompt text, answer key, model answer, points, match
 * mode and the rubric criteria), so editing the question or its rubric
 * after grading makes the old texts unreachable: a text that says "the
 * correct answer is 25" is never reused once the key is corrected to 24.
 *
 * A teacher's edited text beats Gemini's: remember() never lets an `ai`
 * row replace a `teacher` row.
 */
final class ExplanationCache
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_TEACHER = 'teacher';

    private const TABLE = 'explanation_cache';

    /**
     * @param  array<string, mixed>  $extraction  the validated `extract` output of the answer
     */
    public static function hash(Question $question, array $extraction): ?string
    {
        $key = self::key($question->type, $extraction);

        return $key === null ? null : hash('sha256', self::fingerprint($question)."\n".$key);
    }

    /**
     * What the stored explanation depends on besides the answer itself.
     * Loads the rubric criteria when the caller has not.
     */
    public static function fingerprint(Question $question): string
    {
        $criteria = $question->rubricCriteria
            ->map(fn ($c) => [(int) $c->position, (string) $c->description, round((float) $c->points, 2), (bool) $c->is_core])
            ->sortBy(fn (array $c) => $c[0])
            ->values()
            ->all();

        return hash('sha256', (string) json_encode([
            'prompt' => (string) $question->prompt_text,
            'answer_key' => $question->answer_key,
            'model_answer' => $question->model_answer,
            'max_points' => round((float) $question->max_points, 2),
            'match_mode' => (string) $question->match_mode,
            'criteria' => $criteria,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    public static function key(string $type, array $extraction): ?string
    {
        if (($extraction['blank'] ?? false) === true) {
            return null;
        }

        if ($type === Question::TYPE_SHORT) {
            $answer = AnswerMatcher::normalize((string) ($extraction['answer_text'] ?? ''));

            return $answer === '' ? null : 'short:'.$answer;
        }

        if ($type === Question::TYPE_SHOW_WORK) {
            $final = AnswerMatcher::normalize((string) ($extraction['final_answer_text'] ?? ''));
            $steps = array_values(array_filter((array) ($extraction['steps'] ?? []), 'is_array'));
            $allValid = array_reduce($steps, fn (bool $ok, array $s) => $ok && (bool) ($s['valid'] ?? false), true);
            if ($allValid) {
                return $final === '' ? null : 'final:'.$final;
            }
            usort($steps, fn (array $a, array $b) => (int) ($a['line'] ?? 0) <=> (int) ($b['line'] ?? 0));
            $lines = array_map(fn (array $s) => AnswerMatcher::normalize((string) ($s['text'] ?? '')), $steps);

            return 'steps:'.json_encode(['lines' => $lines, 'final' => $final], JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * The stored texts of these (question id, hash) pairs.
     *
     * @param  list<array{0: int, 1: string}>  $pairs
     * @return array<string, array{explanation: string, source: string}> "question_id:hash" => row
     */
    public function find(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }
        $rows = DB::table(self::TABLE)
            ->whereIn('question_id', array_values(array_unique(array_column($pairs, 0))))
            ->whereIn('answer_hash', array_values(array_unique(array_column($pairs, 1))))
            ->get(['question_id', 'answer_hash', 'explanation', 'source']);

        $found = [];
        foreach ($rows as $row) {
            $found[$row->question_id.':'.$row->answer_hash] = ['explanation' => (string) $row->explanation, 'source' => (string) $row->source];
        }

        return $found;
    }

    /**
     * Stores a text for a key. An `ai` text fills an empty slot or replaces
     * an older `ai` text only when $replaceAi (a regenerated explanation);
     * a `teacher` text always wins.
     */
    public function remember(int $questionId, string $hash, string $text, string $source, ?int $responseId, bool $replaceAi = false): void
    {
        $row = ['explanation' => $text, 'source' => $source, 'response_id' => $responseId, 'updated_at' => now()];
        $where = ['question_id' => $questionId, 'answer_hash' => $hash];

        if ($source === self::SOURCE_TEACHER) {
            DB::table(self::TABLE)->updateOrInsert($where, $row);

            return;
        }
        if ($replaceAi) {
            DB::table(self::TABLE)->where($where)->where('source', self::SOURCE_AI)->update($row);
        }
        DB::table(self::TABLE)->insertOrIgnore([$where + $row]);
    }
}
