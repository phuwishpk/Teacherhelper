<?php

namespace App\Domain\Gemini;

use App\Models\Question;
use App\Models\Response;
use App\Models\RubricCriterion;

/**
 * `extract_batch` (DESIGN §21.4): the crops of several questions of one
 * student's scanned page in one call. A text label before each crop names
 * its question (Q{position}); the output is {answers: [...]} with the
 * extraction of each question, read by MultiExtraction. System instruction
 * and rules travel once per page instead of once per question.
 *
 * Nothing but the crops and the teacher's question, key or rubric leaves the
 * server (as with `extract`, §1 principle 3).
 */
final class BatchExtractionRequests
{
    public const PURPOSE = 'extract_batch';

    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @param  list<array{response: Response, question: Question, criteria: list<RubricCriterion>, crop: GeminiImage, final: GeminiImage|null}>  $items
     */
    public function forItems(array $items, string $subject, string $gradeLabel, ?int $assignmentId = null): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, 'general');
        $images = [];
        $briefs = [];
        $hints = [];
        $meta = [];
        foreach ($items as $item) {
            $question = $item['question'];
            $no = (int) $question->position;
            $work = $question->type === Question::TYPE_SHOW_WORK;
            $indexes = [count($images)];
            $images[] = self::labelled($item['crop'], $work ? "Q{$no}: working area" : "Q{$no}: answer box");
            if ($item['final'] !== null) {
                $indexes[] = count($images);
                $images[] = self::labelled($item['final'], "Q{$no}: final answer box");
            }
            $briefs[] = QuestionBriefs::of($question, $item['criteria']);
            $hints[$no] = ExtractionRequests::hints($question, $item['criteria']) + ['images' => $indexes];
            $meta[$no] = ['type' => $question->type, 'criteria' => count($item['criteria'])];
        }

        $request = new GeminiRequest(
            purpose: self::PURPOSE,
            type: 'general',
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(),
            userText: $prompt->renderUser([
                'subject' => $subject,
                'grade_label' => $gradeLabel,
                'question_count' => count($items),
                'questions_json' => QuestionBriefs::json($briefs),
            ]),
            images: $images,
            responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
            temperature: $prompt->temperature,
            hints: ['questions' => $hints],
            thinkingLevel: $prompt->thinking,
            maxOutputTokens: $prompt->maxOutputTokens,
        );

        return new GeminiCall(
            request: $request,
            check: fn (array $data) => MultiExtraction::split($data, $meta, withFound: false),
            feature: ExtractionRequests::FEATURE,
            assignmentId: $assignmentId,
            questionCount: count($items),
            validationSchema: MultiExtraction::ENVELOPE,
        );
    }

    private static function labelled(GeminiImage $image, string $label): GeminiImage
    {
        return new GeminiImage($image->data, $image->mimeType, $image->mediaResolution, $label);
    }
}
