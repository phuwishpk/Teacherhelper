<?php

namespace App\Domain\AnswerKeys;

use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\MediaResolution;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;
use App\Models\Question;

/**
 * The two Gemini calls of the teacher's answer key (DESIGN §19.5, §21.2,
 * §21.6), through the gateway (schema check, one retry of invalid output,
 * ai_calls):
 *
 * - read(): `answer_key_read`, the teacher's key from photos or PDFs, read
 *   once (thinking medium, up to 16,384 output tokens);
 * - draft(): `answer_key_draft`, no teacher key: Gemini answers the
 *   questions itself, from the typed questions and/or a question sheet
 *   (thinking medium, 4,096).
 *
 * Every file goes at GEMINI_MEDIA_DOCUMENT (teachers' documents only,
 * §21.5), a PDF as one part. The prompt carries the questions the app
 * already has (number, type, text), never a name from the database.
 */
final class AnswerKeyReader
{
    public const FEATURE_READ = 'key_from_document';

    public const FEATURE_DRAFT = 'key_ai_draft';

    /** Seconds per call: a 30-page PDF read at thinking medium takes a while. */
    private const TIMEOUT = 150;

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     * @param  list<Question>  $questions  the assignment's questions, if any
     *
     * @throws GeminiException
     */
    public function read(array $files, array $questions, string $subject, string $gradeLabel, GeminiKey $key, ?int $assignmentId = null): AnswerKeyResult
    {
        return $this->run(AnswerKeyResult::KIND_READ, $files, $questions, $subject, $gradeLabel, $key, $assignmentId);
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files  question sheets (may be empty)
     * @param  list<Question>  $questions
     *
     * @throws GeminiException
     */
    public function draft(array $files, array $questions, string $subject, string $gradeLabel, GeminiKey $key, ?int $assignmentId = null): AnswerKeyResult
    {
        return $this->run(AnswerKeyResult::KIND_DRAFT, $files, $questions, $subject, $gradeLabel, $key, $assignmentId);
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     * @param  list<Question>  $questions
     */
    public function request(string $kind, array $files, array $questions, string $subject, string $gradeLabel): GeminiRequest
    {
        $prompt = $this->prompts->get($kind, 'general');
        $briefs = array_map(fn (Question $q) => [
            'question_no' => (int) $q->position,
            'type' => $q->type,
            'question' => trim($q->prompt_text),
        ], $questions);
        $level = MediaResolution::forPart(MediaResolution::PART_DOCUMENT);

        return new GeminiRequest(
            purpose: $kind,
            type: 'general',
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(),
            userText: $prompt->renderUser([
                'subject' => $subject !== '' ? $subject : '-',
                'grade_label' => $gradeLabel,
                'questions_json' => $briefs === [] ? '[]' : json_encode($briefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                'file_note' => self::fileNote($kind, $files),
            ]),
            images: array_map(fn (array $f) => new GeminiImage($f['bytes'], $f['mime_type'], $level), array_values($files)),
            responseSchema: ResponseSchemas::get($kind, 'general'),
            temperature: $prompt->temperature,
            hints: ['kind' => $kind, 'questions' => $briefs],
            timeout: self::TIMEOUT,
            thinkingLevel: $prompt->thinking,
            maxOutputTokens: $prompt->maxOutputTokens,
        );
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     * @param  list<Question>  $questions
     */
    private function run(string $kind, array $files, array $questions, string $subject, string $gradeLabel, GeminiKey $key, ?int $assignmentId): AnswerKeyResult
    {
        $call = new GeminiCall(
            request: $this->request($kind, $files, $questions, $subject, $gradeLabel),
            check: fn (array $data) => AnswerKeyResult::fromGemini($kind, $data)->toArray(),
            feature: $kind === AnswerKeyResult::KIND_READ ? self::FEATURE_READ : self::FEATURE_DRAFT,
            assignmentId: $assignmentId,
            questionCount: $questions === [] ? null : count($questions),
        );

        return AnswerKeyResult::fromArray((array) $this->gateway->runOne($call, $key)->data);
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     */
    private static function fileNote(string $kind, array $files): string
    {
        if ($files === []) {
            return 'There are no attached files: answer from the question texts above.';
        }
        $parts = array_map(fn (array $f) => $f['mime_type'] === 'application/pdf'
            ? 'a PDF of '.$f['page_count'].' page'.($f['page_count'] === 1 ? '' : 's')
            : 'a photo of one page', $files);
        $list = count($files).' file'.(count($files) === 1 ? '' : 's').' ('.implode(', ', $parts).')';

        return $kind === AnswerKeyResult::KIND_READ
            ? $list
            : "The attached {$list} show the question sheet: read the questions from it.";
    }
}
