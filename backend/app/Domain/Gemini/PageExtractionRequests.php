<?php

namespace App\Domain\Gemini;

use App\Models\Question;
use App\Models\RubricCriterion;

/**
 * `extract_page` (DESIGN §19.4, §19.10): one file of a whole-page submission
 * (a photo, or a PDF sent as one part so Gemini sees all its pages) with the
 * key of every question in one call. Output {answers: [{question_no, found,
 * answer_box?, ...}]}, read by MultiExtraction. The file goes at the page
 * media resolution (GEMINI_MEDIA_PAGE, PDFs of student work included, §21.5).
 *
 * The prompt carries the teacher's questions only: no name from the
 * database, and it tells Gemini never to copy a name it sees on the page.
 */
final class PageExtractionRequests
{
    public const PURPOSE = 'extract_page';

    /** ai_calls.feature of the whole-page path (DESIGN §21.8). */
    public const FEATURE = 'grading_page';

    /** Seconds per call: a page with every question takes longer than a crop. */
    public const TIMEOUT = 90;

    public function __construct(private readonly PromptRepository $prompts) {}

    /**
     * @param  list<array{question: Question, criteria: list<RubricCriterion>}>  $items
     */
    public function forFile(string $bytes, string $mimeType, int $pageCount, array $items, string $subject, string $gradeLabel, ?int $assignmentId = null): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, 'general');
        $briefs = [];
        $hints = [];
        $meta = [];
        foreach ($items as $item) {
            $question = $item['question'];
            $no = (int) $question->position;
            $briefs[] = QuestionBriefs::of($question, $item['criteria']);
            $hints[$no] = ExtractionRequests::hints($question, $item['criteria']);
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
                'file_note' => $mimeType === GeminiImage::PDF
                    ? ($pageCount > 1 ? "a PDF with {$pageCount} pages" : 'a PDF with one page')
                    : 'a photo of one page',
                'questions_json' => QuestionBriefs::json($briefs),
            ]),
            images: [new GeminiImage($bytes, $mimeType, MediaResolution::forPart(MediaResolution::PART_PAGE))],
            responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
            temperature: $prompt->temperature,
            hints: ['questions' => $hints],
            timeout: self::TIMEOUT,
        );

        return new GeminiCall(
            request: $request,
            questionId: count($items) === 1 ? $items[0]['question']->id : null,
            check: fn (array $data) => MultiExtraction::split($data, $meta, withFound: true),
            feature: self::FEATURE,
            assignmentId: $assignmentId,
            questionCount: count($items),
            validationSchema: MultiExtraction::ENVELOPE,
        );
    }
}
