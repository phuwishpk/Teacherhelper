<?php

namespace App\Domain\Gemini;

use App\Domain\Scans\ScanFiles;
use App\Models\Question;
use App\Models\Response;
use App\Models\RubricCriterion;

/**
 * Builds the `extract` request of one response (DESIGN §10.3). What leaves
 * the server is only the answer crop(s) and the teacher's question, key or
 * rubric, subject and grade: never the student's name, id or the QR (the
 * crop is cut from inside the answer box; §1 principle 3).
 */
final class ExtractionRequests
{
    public const PURPOSE = 'extract';

    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @param  list<RubricCriterion>  $criteria  open questions, in position order
     *
     * @throws CropMissing
     */
    public function forResponse(Response $response, Question $question, array $criteria, string $subject, string $gradeLabel): GeminiCall
    {
        $type = $question->type;
        $prompt = $this->prompts->get(self::PURPOSE, $type);
        $key = $question->answer_key ?? [];
        $images = [new GeminiImage(self::crop($response->crop_path))];

        $vars = [
            'subject' => $subject,
            'grade_label' => $gradeLabel,
            'prompt_text' => trim($question->prompt_text),
        ];
        $hints = ['type' => $type, 'question_text' => $question->prompt_text];

        if ($type === Question::TYPE_SHOW_WORK) {
            $final = (array) ($key['final'] ?? []);
            $hasFinal = $response->final_crop_path !== null;
            if ($hasFinal) {
                $images[] = new GeminiImage(self::crop($response->final_crop_path));
            }
            $vars += [
                'accepted_final' => PromptText::quotedList(array_map('strval', (array) ($final['accepted'] ?? []))),
                'reference_steps' => PromptText::numberedLines(array_map('strval', (array) ($key['reference_steps'] ?? [])), '(empty)'),
                'answer_lines' => (string) ($question->answer_lines ?? 1),
                'final_image' => $hasFinal
                    ? 'Image 2: the final answer box labelled "คำตอบ".'
                    : 'There is no image of the final answer box: take final_answer_text from the last written line.',
            ];
            $hints += [
                'accepted_final' => array_values(array_map('strval', (array) ($final['accepted'] ?? []))),
                'numeric' => $final['numeric'] ?? null,
                'reference_steps' => array_values(array_map('strval', (array) ($key['reference_steps'] ?? []))),
                'answer_lines' => (int) ($question->answer_lines ?? 1),
            ];
        } elseif ($type === Question::TYPE_SHORT) {
            $numeric = $key['numeric'] ?? null;
            $vars += [
                'accepted' => PromptText::quotedList(array_map('strval', (array) ($key['accepted'] ?? []))),
                'numeric_key' => is_array($numeric)
                    ? 'Numeric answer: '.PromptText::number((float) $numeric['value']).' (tolerance ±'.PromptText::number((float) ($numeric['abs_tol'] ?? 0)).')'
                    : 'The answer is not a number.',
                'match_rule' => $question->match_mode === 'exact'
                    ? 'Spelling counts: only an answer written letter for letter like an accepted answer is exact.'
                    : 'Small differences in wording or spacing are fine when the meaning or value is the same.',
            ];
            $hints += [
                'accepted' => array_values(array_map('strval', (array) ($key['accepted'] ?? []))),
                'numeric' => is_array($numeric) ? $numeric : null,
            ];
        } else {
            $vars += [
                'criteria' => implode("\n", array_map(
                    fn (RubricCriterion $c, int $i) => ($i + 1).'. '.trim($c->description).($c->is_core ? ' [core idea]' : ''),
                    $criteria,
                    array_keys($criteria),
                )),
                'answer_lines' => (string) ($question->answer_lines ?? 1),
            ];
            $hints += [
                'criteria' => array_map(fn (RubricCriterion $c, int $i) => ['criterion_id' => $i + 1, 'is_core' => $c->is_core], $criteria, array_keys($criteria)),
            ];
        }

        $request = new GeminiRequest(
            purpose: self::PURPOSE,
            type: $type,
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(),
            userText: $prompt->renderUser($vars),
            images: $images,
            responseSchema: ResponseSchemas::get(self::PURPOSE, $type),
            temperature: $prompt->temperature,
            hints: $hints,
        );

        $criteriaCount = count($criteria);

        return new GeminiCall(
            request: $request,
            responseId: $response->id,
            questionId: $question->id,
            check: fn (array $data) => ExtractionValidator::normalize($type, $data, $criteriaCount),
        );
    }

    /** @throws CropMissing */
    private static function crop(?string $path): string
    {
        $disk = ScanFiles::disk();
        if ($path === null || ! $disk->exists($path)) {
            throw new CropMissing('crop file missing: '.($path ?? 'no path'));
        }

        return (string) $disk->get($path);
    }
}
