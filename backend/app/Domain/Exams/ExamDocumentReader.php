<?php

namespace App\Domain\Exams;

use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\MediaResolution;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;
use App\Domain\Gemini\TeacherGuidance;

/**
 * The one Gemini call that reads a teacher's exam file (prompt exam_read,
 * DESIGN §22.4, §21.6: thinking medium, up to 16,384 output tokens,
 * temperature 0), through the gateway (schema check, one retry of invalid
 * output, ai_calls with purpose exam_read and feature exam_import). Every
 * file goes at GEMINI_MEDIA_DOCUMENT (§21.5). The teacher's guidance, if
 * any, fills the {teacher_guidance} slot (§21.12).
 */
final class ExamDocumentReader
{
    public const PURPOSE = 'exam_read';

    public const FEATURE = 'exam_import';

    /** Seconds per call: a 30-page exam read at thinking medium takes a while. */
    private const TIMEOUT = 150;

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     * @param  list<array{sha256: string, pages: int, page_offset: int}>  $refs  the same files, for figure references
     * @return array<string, mixed> ExamDocumentResult
     *
     * @throws GeminiException
     */
    public function read(array $files, array $refs, GeminiKey $key, ?string $guidance = null, ?int $guidanceBy = null, ?int $assignmentId = null): array
    {
        $call = new GeminiCall(
            request: $this->request($files, $guidance),
            check: fn (array $data) => ExamDocumentResult::fromGemini($data, $refs),
            feature: self::FEATURE,
            assignmentId: $assignmentId,
            guidance: $guidance,
            guidanceBy: $guidanceBy,
        );

        return (array) $this->gateway->runOne($call, $key)->data;
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     */
    public function request(array $files, ?string $guidance = null): GeminiRequest
    {
        $prompt = $this->prompts->get(self::PURPOSE, 'general');
        $level = MediaResolution::forPart(MediaResolution::PART_DOCUMENT);

        return new GeminiRequest(
            purpose: self::PURPOSE,
            type: 'general',
            promptVersion: $prompt->versionLabel(),
            systemInstruction: $prompt->renderSystem(),
            userText: $prompt->renderUser([
                'file_note' => self::fileNote($files),
                'teacher_guidance' => TeacherGuidance::block($guidance),
            ]),
            images: array_map(fn (array $f) => new GeminiImage($f['bytes'], $f['mime_type'], $level), array_values($files)),
            responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
            temperature: $prompt->temperature,
            hints: ['pages' => array_map(fn (array $f) => $f['page_count'], array_values($files))],
            timeout: self::TIMEOUT,
            thinkingLevel: $prompt->thinking,
            maxOutputTokens: $prompt->maxOutputTokens,
        );
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     */
    private static function fileNote(array $files): string
    {
        $parts = [];
        foreach (array_values($files) as $i => $f) {
            $parts[] = 'file '.($i + 1).': '.($f['mime_type'] === 'application/pdf'
                ? 'a PDF of '.$f['page_count'].' page'.($f['page_count'] === 1 ? '' : 's')
                : 'a photo of one page');
        }

        return count($files).' file'.(count($files) === 1 ? '' : 's').' ('.implode('; ', $parts).')';
    }
}
