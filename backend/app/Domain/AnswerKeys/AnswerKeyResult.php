<?php

namespace App\Domain\AnswerKeys;

use App\Domain\Assignments\QuestionData;
use App\Domain\Gemini\GeminiException;
use App\Models\Question;

/**
 * The structured key Gemini read or drafted (answer_key_read /
 * answer_key_draft, DESIGN §19.5), normalised to what `questions` stores,
 * and the form it is cached in (document_extractions.result):
 *
 *   {kind: answer_key_read|answer_key_draft, notes_th,
 *    questions: [{question_no, type, prompt_text, max_points|null,
 *                 answer_key|null (QuestionData shape), model_answer|null,
 *                 confidence|null}]}
 *
 * A question whose key could not be read keeps answer_key = null: the
 * teacher fills it in before approving.
 */
final readonly class AnswerKeyResult
{
    public const KIND_READ = 'answer_key_read';

    public const KIND_DRAFT = 'answer_key_draft';

    /**
     * @param  list<array{question_no: int, type: string, prompt_text: string, max_points: float|null, answer_key: array<string, mixed>|null, model_answer: string|null, confidence: string|null}>  $questions  by question_no
     */
    public function __construct(
        public string $kind,
        public array $questions,
        public string $notes = '',
    ) {}

    /**
     * Gemini's output, checked beyond the schema; throws invalid output when
     * no question could be used.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws GeminiException
     */
    public static function fromGemini(string $kind, array $data): self
    {
        $questions = [];
        foreach ((array) ($data['questions'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $no = (int) ($item['question_no'] ?? 0);
            $type = (string) ($item['type'] ?? '');
            $prompt = trim((string) ($item['prompt_text'] ?? ''));
            if ($no < 1 || $no > QuestionData::MAX_QUESTIONS || ! in_array($type, Question::TYPES, true) || isset($questions[$no])) {
                continue;
            }
            $points = isset($item['max_points']) && is_numeric($item['max_points']) && (float) $item['max_points'] > 0
                ? round(min(100.0, (float) $item['max_points']), 2)
                : null;

            $questions[$no] = [
                'question_no' => $no,
                'type' => $type,
                'prompt_text' => mb_substr($prompt, 0, QuestionData::MAX_PROMPT),
                'max_points' => $points,
                'answer_key' => self::key($type, $item),
                'model_answer' => $type === Question::TYPE_OPEN ? self::modelAnswer($item) : null,
                'confidence' => in_array($item['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $item['confidence'] : null,
            ];
        }
        if ($questions === []) {
            throw GeminiException::invalidOutput('no usable question in the answer key');
        }
        ksort($questions);

        return new self($kind, array_values($questions), mb_substr(trim((string) ($data['notes_th'] ?? '')), 0, 1000));
    }

    /**
     * @param  array<string, mixed>  $result  document_extractions.result
     */
    public static function fromArray(array $result): self
    {
        return new self(
            (string) ($result['kind'] ?? self::KIND_READ),
            array_values((array) ($result['questions'] ?? [])),
            (string) ($result['notes_th'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'notes_th' => $this->notes, 'questions' => $this->questions];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function key(string $type, array $item): ?array
    {
        if ($type === Question::TYPE_MCQ) {
            $correct = strtoupper(trim((string) ($item['correct_option'] ?? '')));

            return in_array($correct, Question::MCQ_OPTIONS, true) ? ['correct' => $correct] : null;
        }
        if ($type === Question::TYPE_OPEN) {
            return null;
        }

        $accepted = self::strings((array) ($item['accepted_answers'] ?? []), QuestionData::MAX_ACCEPTED, 255);
        $numeric = isset($item['numeric_value']) && is_numeric($item['numeric_value']) && is_finite((float) $item['numeric_value'])
            ? ['value' => (float) $item['numeric_value'], 'abs_tol' => 0.0]
            : null;
        if ($accepted === [] && $numeric !== null) {
            $accepted = [self::number($numeric['value'])];
        }
        if ($accepted === []) {
            return null;
        }
        $final = ['accepted' => $accepted] + ($numeric !== null ? ['numeric' => $numeric] : []);

        return $type === Question::TYPE_SHORT
            ? $final
            : ['final' => $final, 'reference_steps' => self::strings((array) ($item['reference_steps'] ?? []), QuestionData::MAX_REFERENCE_STEPS, 500)];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function modelAnswer(array $item): ?string
    {
        $answer = trim((string) ($item['model_answer'] ?? ''));
        $points = self::strings((array) ($item['key_points'] ?? []), 10, 500);
        if ($points !== []) {
            $answer .= ($answer === '' ? '' : "\n\n").'ประเด็นสำคัญ:'."\n".implode("\n", array_map(fn (string $p) => '- '.$p, $points));
        }

        return $answer === '' ? null : mb_substr($answer, 0, QuestionData::MAX_MODEL_ANSWER);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values, int $max, int $length): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = mb_substr($value, 0, $length);
            }
        }

        return array_slice($out, 0, $max);
    }

    private static function number(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
