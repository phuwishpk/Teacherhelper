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

    /** ai_calls.feature of the crop path (DESIGN §21.8). */
    public const FEATURE = 'grading_crop';

    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @param  list<RubricCriterion>  $criteria  open questions, in position order
     *
     * @throws CropMissing
     */
    public function forResponse(Response $response, Question $question, array $criteria, string $subject, string $gradeLabel, ?int $assignmentId = null): GeminiCall
    {
        [$crop, $finalCrop] = self::crops($response, $question);

        return $this->forCrops($response, $question, $criteria, $subject, $gradeLabel, $crop, $finalCrop, $assignmentId);
    }

    /**
     * forResponse() with the crops already loaded (the per-question fallback
     * of extract_batch reuses them).
     *
     * @param  list<RubricCriterion>  $criteria
     */
    public function forCrops(Response $response, Question $question, array $criteria, string $subject, string $gradeLabel, GeminiImage $crop, ?GeminiImage $finalCrop, ?int $assignmentId = null): GeminiCall
    {
        $type = $question->type;
        $criteriaCount = count($criteria);

        return new GeminiCall(
            request: $this->request($question, $criteria, $subject, $gradeLabel, $crop, $finalCrop),
            responseId: $response->id,
            questionId: $question->id,
            check: fn (array $data) => ExtractionValidator::normalize($type, $data, $criteriaCount),
            feature: self::FEATURE,
            assignmentId: $assignmentId,
            questionCount: 1,
        );
    }

    /**
     * The answer crop and, for show_work, the final-answer box, each with the
     * media resolution of its kind (DESIGN §21.5): short answers and the
     * final-answer box GEMINI_MEDIA_SHORT, working areas GEMINI_MEDIA_WORK.
     *
     * @return array{0: GeminiImage, 1: GeminiImage|null}
     *
     * @throws CropMissing
     */
    public static function crops(Response $response, Question $question, ?string $label = null, ?string $finalLabel = null): array
    {
        $mainLevel = MediaResolution::forPart($question->type === Question::TYPE_SHORT ? MediaResolution::PART_SHORT : MediaResolution::PART_WORK);
        $crop = new GeminiImage(self::crop($response->crop_path), GeminiImage::WEBP, $mainLevel, $label);
        $finalCrop = $question->type === Question::TYPE_SHOW_WORK && $response->final_crop_path !== null
            ? new GeminiImage(self::crop($response->final_crop_path), GeminiImage::WEBP, MediaResolution::forPart(MediaResolution::PART_SHORT), $finalLabel)
            : null;

        return [$crop, $finalCrop];
    }

    /**
     * The `extract` request for crops already in memory: the grading job via
     * forResponse(), and eduvision:gemini-check --injection with the fixtures.
     *
     * @param  list<RubricCriterion>  $criteria  open questions, in position order
     * @param  ?GeminiImage  $finalCrop  show_work only: the final answer box
     */
    public function request(Question $question, array $criteria, string $subject, string $gradeLabel, GeminiImage $crop, ?GeminiImage $finalCrop = null): GeminiRequest
    {
        $type = $question->type;
        $prompt = $this->prompts->get(self::PURPOSE, $type);
        $key = $question->answer_key ?? [];
        $images = [$crop];

        $vars = [
            'subject' => $subject,
            'grade_label' => $gradeLabel,
            'prompt_text' => trim($question->prompt_text),
        ];
        $hints = self::hints($question, $criteria);

        if ($type === Question::TYPE_SHOW_WORK) {
            $final = (array) ($key['final'] ?? []);
            $hasFinal = $finalCrop !== null;
            if ($hasFinal) {
                $images[] = $finalCrop;
            }
            $vars += [
                'accepted_final' => PromptText::quotedList(array_map('strval', (array) ($final['accepted'] ?? []))),
                'reference_steps' => PromptText::numberedLines(array_map('strval', (array) ($key['reference_steps'] ?? [])), '(empty)'),
                'answer_lines' => (string) ($question->answer_lines ?? 1),
                'final_image' => $hasFinal
                    ? 'Image 2: the final answer box labelled "คำตอบ".'
                    : 'There is no image of the final answer box: take final_answer_text from the last written line.',
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
        } else {
            $vars += [
                'criteria' => implode("\n", array_map(
                    fn (RubricCriterion $c, int $i) => ($i + 1).'. '.trim($c->description).($c->is_core ? ' [core idea]' : ''),
                    $criteria,
                    array_keys($criteria),
                )),
                'answer_lines' => (string) ($question->answer_lines ?? 1),
            ];
        }

        return new GeminiRequest(
            purpose: self::PURPOSE,
            type: $type,
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(),
            userText: $prompt->renderUser($vars),
            images: $images,
            responseSchema: ResponseSchemas::get(self::PURPOSE, $type),
            temperature: $prompt->temperature,
            hints: $hints,
            thinkingLevel: $prompt->thinking,
            maxOutputTokens: $prompt->maxOutputTokens,
        );
    }

    /**
     * What FakeGeminiClient needs to answer for one question (never sent to
     * Google): the type, the teacher's text (markers) and the key or rubric.
     *
     * @param  list<RubricCriterion>  $criteria
     * @return array<string, mixed>
     */
    public static function hints(Question $question, array $criteria): array
    {
        $type = $question->type;
        $key = $question->answer_key ?? [];
        $hints = ['type' => $type, 'question_text' => $question->prompt_text];

        return $hints + match ($type) {
            Question::TYPE_SHOW_WORK => [
                'accepted_final' => array_values(array_map('strval', (array) ($key['final']['accepted'] ?? []))),
                'numeric' => $key['final']['numeric'] ?? null,
                'reference_steps' => array_values(array_map('strval', (array) ($key['reference_steps'] ?? []))),
                'answer_lines' => (int) ($question->answer_lines ?? 1),
            ],
            Question::TYPE_SHORT => [
                'accepted' => array_values(array_map('strval', (array) ($key['accepted'] ?? []))),
                'numeric' => is_array($key['numeric'] ?? null) ? $key['numeric'] : null,
            ],
            Question::TYPE_OPEN => [
                'criteria' => array_map(fn (RubricCriterion $c, int $i) => ['criterion_id' => $i + 1, 'is_core' => $c->is_core], $criteria, array_keys($criteria)),
            ],
            default => [
                'correct' => is_string($key['correct'] ?? null) ? $key['correct'] : null,
            ],
        };
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
