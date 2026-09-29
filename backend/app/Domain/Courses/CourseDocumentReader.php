<?php

namespace App\Domain\Courses;

use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\MediaResolution;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;

/**
 * The one Gemini call that reads a course description, course structure or
 * lesson plans (prompt document_read, DESIGN §20.1, §21.2, §21.6: thinking
 * medium, up to 16,384 output tokens), through the gateway (schema check,
 * one retry of invalid output, ai_calls with feature course_import). Every
 * file goes at GEMINI_MEDIA_DOCUMENT (teachers' documents, §21.5).
 */
final class CourseDocumentReader
{
    public const PURPOSE = 'document_read';

    public const FEATURE = 'course_import';

    /** Seconds per call: a 30-page PDF read at thinking medium takes a while. */
    private const TIMEOUT = 150;

    private const KIND_TEXT = [
        CourseDocumentResult::KIND_COURSE => 'a course description or a course structure (คำอธิบายรายวิชา / โครงสร้างรายวิชา)',
        CourseDocumentResult::KIND_LESSON_PLAN => 'lesson plans (แผนการจัดการเรียนรู้)',
    ];

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     * @return array<string, mixed> CourseDocumentResult
     *
     * @throws GeminiException
     */
    public function read(string $kind, array $files, GeminiKey $key): array
    {
        $call = new GeminiCall(
            request: $this->request($kind, $files),
            check: fn (array $data) => CourseDocumentResult::fromGemini($kind, $data),
            feature: self::FEATURE,
        );

        return (array) $this->gateway->runOne($call, $key)->data;
    }

    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int}>  $files
     */
    public function request(string $kind, array $files): GeminiRequest
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
                'document_kind' => self::KIND_TEXT[$kind] ?? self::KIND_TEXT[CourseDocumentResult::KIND_COURSE],
            ]),
            images: array_map(fn (array $f) => new GeminiImage($f['bytes'], $f['mime_type'], $level), array_values($files)),
            responseSchema: ResponseSchemas::get(self::PURPOSE, 'general'),
            temperature: $prompt->temperature,
            hints: ['kind' => $kind],
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
        $parts = array_map(fn (array $f) => $f['mime_type'] === 'application/pdf'
            ? 'a PDF of '.$f['page_count'].' page'.($f['page_count'] === 1 ? '' : 's')
            : 'a photo of one page', $files);

        return count($files).' file'.(count($files) === 1 ? '' : 's').' ('.implode(', ', $parts).')';
    }
}
