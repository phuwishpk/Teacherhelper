<?php

namespace App\Domain\Gemini;

/**
 * Reads the output of a call that answers several questions at once
 * (DESIGN §21.4 extract_batch, §19.4 extract_page): {answers: [{question_no,
 * found?, answer_box?, ...the fields of extract.{type}}]}.
 *
 * The server checks only the envelope in GeminiGateway (ENVELOPE); every
 * answer is checked here on its own against the schema and the validator of
 * its question type, so one bad answer never discards the others:
 *
 * - answers:  question_no => {found, data, answer_box}; data is the
 *   normalised extraction (null when found = false);
 * - invalid:  question_no => why (fails its schema, listed twice, ...);
 * - a question that is in neither list was left out by the model.
 *
 * The callers send what is invalid or missing again, one question per call
 * (the per-question fallback). answer_box ([ymin, xmin, ymax, xmax], 0–1000
 * like Gemini's box_2d) is optional: a malformed one is dropped, not an error.
 */
final class MultiExtraction
{
    /** What GeminiGateway checks of the whole output. */
    public const ENVELOPE = [
        'type' => 'object',
        'properties' => [
            'answers' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['question_no' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['question_no'],
                ],
            ],
        ],
        'required' => ['answers'],
    ];

    /** Fields that frame an answer rather than belong to its extraction. */
    private const FRAME = ['question_no', 'found', 'answer_box'];

    /**
     * @param  array<string, mixed>  $data  the output, envelope already valid
     * @param  array<int, array{type: string, criteria: int}>  $questions  question_no => type and rubric size
     * @param  bool  $withFound  extract_page: every answer says whether it was found on the page
     * @return array{answers: array<int, array{found: bool, data: array<string, mixed>|null, answer_box: list<int>|null}>, invalid: array<int, string>}
     */
    public static function split(array $data, array $questions, bool $withFound): array
    {
        $answers = [];
        $invalid = [];
        foreach ($data['answers'] as $item) {
            $no = (int) $item['question_no'];
            if (! isset($questions[$no])) {
                continue; // a question nobody asked about
            }
            if (isset($answers[$no]) || isset($invalid[$no])) {
                unset($answers[$no]);
                $invalid[$no] = 'question_no listed more than once';

                continue;
            }

            try {
                $answers[$no] = self::answer($item, $questions[$no]['type'], $questions[$no]['criteria'], $withFound);
            } catch (GeminiException $e) {
                $invalid[$no] = $e->getMessage();
            }
        }
        ksort($answers);
        ksort($invalid);

        return ['answers' => $answers, 'invalid' => $invalid];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{found: bool, data: array<string, mixed>|null, answer_box: list<int>|null}
     *
     * @throws GeminiException
     */
    private static function answer(array $item, string $type, int $criteria, bool $withFound): array
    {
        $found = true;
        if ($withFound) {
            if (! is_bool($item['found'] ?? null)) {
                throw GeminiException::invalidOutput('found is missing or not a boolean');
            }
            $found = $item['found'];
        }
        if (! $found) {
            return ['found' => false, 'data' => null, 'answer_box' => null];
        }

        $extraction = array_diff_key($item, array_flip(self::FRAME));
        $errors = SchemaValidator::validate(ResponseSchemas::get(ExtractionRequests::PURPOSE, $type), $extraction);
        if ($errors !== []) {
            throw GeminiException::invalidOutput('schema: '.implode('; ', array_slice($errors, 0, 3)));
        }

        return [
            'found' => true,
            'data' => ExtractionValidator::normalize($type, $extraction, $criteria),
            'answer_box' => self::box($item['answer_box'] ?? null),
        ];
    }

    /** @return list<int>|null */
    private static function box(mixed $box): ?array
    {
        if (! is_array($box) || ! array_is_list($box) || count($box) !== 4) {
            return null;
        }
        foreach ($box as $v) {
            if (! is_int($v) || $v < 0 || $v > 1000) {
                return null;
            }
        }

        return $box[0] < $box[2] && $box[1] < $box[3] ? $box : null;
    }
}
