<?php

namespace App\Domain\Courses;

use App\Domain\Documents\CostEstimate;
use App\Domain\Documents\DocumentSelection;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\TeacherGuidance;
use App\Exceptions\ApiException;
use App\Jobs\ReadCourseDocumentJob;
use App\Models\DocumentExtraction;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reading a course description, course structure or lesson plans once
 * (DESIGN §20.1, §21.2), with the rules of the teacher's answer key
 * (§19.5): the files come from POST /documents, more than 30 pages need a
 * page range (422 document_too_long), and the result is cached for the
 * whole school in document_extractions under (school, files' hash,
 * purpose course | lesson_plan).
 *
 * - request(): POST /courses/extract. A cached result answers at once
 *   (200, free, no Gemini key needed); otherwise a usable key is required
 *   (422 ai_key_missing), the read is queued (ReadCourseDocumentJob, 202)
 *   and the app polls GET /document-extractions/{id}.
 * - estimate(): POST /courses/extract/estimate, the cost shown before
 *   every read, and whether the school read the same files before.
 * - process(): the job's one Gemini call (CourseDocumentReader).
 *
 * Nothing is written to courses here: the teacher confirms the result in a
 * form and sends it to POST /courses/import (CourseImporter).
 *
 * guidance (DESIGN §21.12): the teacher's optional guidance to the AI is
 * part of the cache key (TeacherGuidance::cacheKey; none = the key of
 * before) and is kept on the row, where the job reads it.
 */
final class CourseDocuments
{
    /** A read still `queued` after this long is presumed lost and queued again. */
    private const STALE_MINUTES = 15;

    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly CourseDocumentReader $reader,
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
        private readonly IndicatorMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {document_ids[], purpose, page_from?, page_to?, guidance?}
     * @return array{extraction: DocumentExtraction, cached: bool, estimate: array{input_tokens: int, output_tokens: int, thb: float|null}}
     *
     * @throws ApiException
     */
    public function request(User $teacher, array $input): array
    {
        [$kind, $selection, $guidance] = $this->resolve($teacher, $input);
        $estimate = CostEstimate::forPages($selection->pageCount());
        $hash = TeacherGuidance::cacheKey($selection->inputHash(), $guidance);
        $existing = $this->cached($teacher, $kind, $hash);
        if ($existing?->isDone()) {
            return ['extraction' => $existing, 'cached' => true, 'estimate' => $estimate];
        }

        if ($this->keys->forTeacher($teacher->id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }
        $selection->files(); // still stored, not too large, range cuttable: 422 now rather than a failed job

        $extraction = $existing ?? DocumentExtraction::createOrFirst(
            ['school_id' => $teacher->school_id, 'input_hash' => $hash, 'purpose' => $kind],
            ['status' => DocumentExtraction::STATUS_QUEUED, 'requested_by' => $teacher->id, 'guidance' => $guidance],
        );
        if ($extraction->isDone()) {
            return ['extraction' => $extraction, 'cached' => true, 'estimate' => $estimate];
        }
        $running = $extraction->status === DocumentExtraction::STATUS_QUEUED
            && ! $extraction->wasRecentlyCreated
            && $extraction->updated_at !== null
            && $extraction->updated_at->gt(now()->subMinutes(self::STALE_MINUTES));
        if (! $running) {
            // New, failed before, or lost: (re)queue it.
            $extraction->forceFill(['status' => DocumentExtraction::STATUS_QUEUED, 'error' => null, 'requested_by' => $teacher->id, 'updated_at' => now()])->save();
            ReadCourseDocumentJob::dispatch($extraction->id, $kind, $selection->ids(), $selection->pageFrom, $selection->pageTo, $teacher->id);
        }

        return ['extraction' => $extraction->refresh(), 'cached' => false, 'estimate' => $estimate];
    }

    /**
     * @param  array<string, mixed>  $input  {document_ids[], purpose, page_from?, page_to?, guidance?}
     * @return array{purpose: string, pages: int, cached: bool, estimate: array{input_tokens: int, output_tokens: int, thb: float|null}}
     *
     * @throws ApiException
     */
    public function estimate(User $teacher, array $input): array
    {
        [$kind, $selection, $guidance] = $this->resolve($teacher, $input);

        return [
            'purpose' => $kind,
            'pages' => $selection->pageCount(),
            'cached' => (bool) $this->cached($teacher, $kind, TeacherGuidance::cacheKey($selection->inputHash(), $guidance))?->isDone(),
            'estimate' => CostEstimate::forPages($selection->pageCount()),
        ];
    }

    /**
     * The API form of a course or lesson-plan read: {result, indicator_matches}
     * once done (the matches are computed for the teacher's school at read
     * time, so indicators added since are found), nulls before.
     *
     * @return array{result: array<string, mixed>|null, indicator_matches: list<array{code: string, skill: array<string, mixed>|null}>}
     */
    public function payload(DocumentExtraction $extraction, ?int $schoolId): array
    {
        if (! $extraction->isDone() || ! is_array($extraction->result)) {
            return ['result' => null, 'indicator_matches' => []];
        }

        return ['result' => $extraction->result, 'indicator_matches' => $this->matcher->forResult($schoolId, $extraction->result)];
    }

    /**
     * One read (the job's work).
     *
     * @param  list<int>  $documentIds
     *
     * @throws GeminiException transport error while retries are left
     */
    public function process(int $extractionId, string $kind, array $documentIds, ?int $pageFrom, ?int $pageTo, int $teacherId, bool $lastAttempt): void
    {
        $extraction = DocumentExtraction::query()->find($extractionId);
        if ($extraction === null || $extraction->isDone()) {
            return;
        }
        $key = $this->keys->forTeacher($teacherId);
        if ($key === null) {
            $this->fail($extraction, 'ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง');

            return;
        }

        $documents = SourceDocument::query()->whereIn('id', $documentIds === [] ? [0] : $documentIds)->get()->keyBy('id');
        $selection = new DocumentSelection(
            array_values(array_filter(array_map(fn (int $id) => $documents[$id] ?? null, $documentIds))),
            $pageFrom,
            $pageTo,
        );
        try {
            $files = $selection->files();
        } catch (ApiException $e) {
            $this->fail($extraction, $e->getMessage());

            return;
        }

        try {
            $result = $this->reader->read($kind, $files, $key, $extraction->guidance, $extraction->guidance === null ? null : $extraction->requested_by);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR && ! $lastAttempt) {
                throw $e; // transient: the queue retries with backoff
            }
            Log::warning('course_document.failed', ['extraction_id' => $extraction->id, 'kind' => $kind, 'status' => $e->status]);
            $this->fail($extraction, match ($e->status) {
                GeminiException::INVALID_OUTPUT => 'AI อ่านเอกสารไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น แนบไฟล์ใหม่ หรือกรอกในฟอร์มเอง',
                GeminiException::KEY_INVALID => 'Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าแล้วลองอีกครั้ง',
                default => 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง',
            });

            return;
        }

        $extraction->forceFill([
            'status' => DocumentExtraction::STATUS_DONE,
            'result' => $result,
            'model' => Str::limit($this->gateway->client()->model(), 64, ''),
            'prompt_version' => $this->prompts->get(CourseDocumentReader::PURPOSE, 'general')->versionLabel(),
            'error' => null,
        ])->save();
    }

    /** After the job ran out of tries. */
    public function giveUp(int $extractionId): void
    {
        $extraction = DocumentExtraction::query()->find($extractionId);
        if ($extraction !== null && $extraction->status === DocumentExtraction::STATUS_QUEUED) {
            $this->fail($extraction, 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: DocumentSelection, 2: string|null}
     *
     * @throws ApiException
     */
    private function resolve(User $teacher, array $input): array
    {
        $kind = $input['purpose'] ?? null;
        if (! is_string($kind) || ! in_array($kind, CourseDocumentResult::KINDS, true)) {
            $message = 'purpose ต้องเป็น course (คำอธิบาย/โครงสร้างรายวิชา) หรือ lesson_plan (แผนการสอน)';

            throw new ApiException($message, 'validation_failed', 422, ['purpose' => [$message]]);
        }
        $ids = $input['document_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            throw new ApiException('กรุณาแนบไฟล์เอกสารอย่างน้อย 1 ไฟล์', 'validation_failed', 422, ['document_ids' => ['กรุณาแนบไฟล์เอกสารอย่างน้อย 1 ไฟล์']]);
        }

        return [$kind, DocumentSelection::resolve($teacher, $input), TeacherGuidance::fromInput($input)];
    }

    private function cached(User $teacher, string $kind, string $hash): ?DocumentExtraction
    {
        return DocumentExtraction::query()
            ->where('school_id', $teacher->school_id)
            ->where('input_hash', $hash)
            ->where('purpose', $kind)
            ->first();
    }

    private function fail(DocumentExtraction $extraction, string $message): void
    {
        $extraction->forceFill(['status' => DocumentExtraction::STATUS_FAILED, 'error' => Str::limit($message, 250)])->save();
    }
}
