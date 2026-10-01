<?php

namespace App\Domain\Gemini;

use Closure;

/**
 * A request plus what GeminiGateway needs around it: the ai_calls links and
 * the semantic check that runs after the schema check. The check returns the
 * normalised data or throws GeminiException::invalidOutput().
 *
 * feature, assignmentId and questionCount only label the ai_calls row
 * (DESIGN §21.8: e.g. feature grading_crop / grading_page, question_count
 * of a one-call-per-page request).
 *
 * validationSchema replaces the request's schema for the server-side check:
 * a call that answers many questions checks only its envelope there, and
 * each answer in `check`, so one bad answer never discards the others
 * (per-question fallback, DESIGN §21.4).
 *
 * guidance / guidanceBy: the teacher's guidance the prompt carries
 * (TeacherGuidance, DESIGN §21.12) and who wrote it, logged to
 * ai_calls.teacher_guidance / guidance_by. Null for every call without one.
 */
final readonly class GeminiCall
{
    /**
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $check
     * @param  array<string, mixed>|null  $validationSchema
     */
    public function __construct(
        public GeminiRequest $request,
        public ?int $responseId = null,
        public ?int $questionId = null,
        public ?int $skillId = null,
        public ?Closure $check = null,
        public ?string $feature = null,
        public ?int $assignmentId = null,
        public ?int $questionCount = null,
        public ?array $validationSchema = null,
        public ?string $guidance = null,
        public ?int $guidanceBy = null,
    ) {}
}
