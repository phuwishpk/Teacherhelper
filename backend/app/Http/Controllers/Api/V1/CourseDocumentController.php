<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\CourseDocuments;
use App\Domain\Courses\CourseImporter;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Courses and lesson plans from the teacher's documents (DESIGN §20.1,
 * §20.7): read once by Gemini (cached for the school), confirmed by the
 * teacher in a form, then imported in one transaction.
 */
class CourseDocumentController extends Controller
{
    public function __construct(
        private readonly CourseDocuments $documents,
        private readonly CourseImporter $importer,
    ) {}

    /**
     * POST /api/v1/courses/extract {document_ids[], purpose: course|lesson_plan,
     * page_from?, page_to?, guidance?} -> 200 cached {data: {cached: true,
     * estimate: null, extraction, result, indicator_matches}} or 202 queued
     * {data: {cached: false, estimate, extraction, result: null,
     * indicator_matches: []}}; poll GET /document-extractions/{id}.
     * guidance: the teacher's guidance to the AI (DESIGN §21.12, at most 500
     * characters), part of the cache key and echoed as extraction.guidance.
     * 422 ai_key_missing, document_too_long, document_missing,
     * file_too_large, document_split_unsupported, validation_failed
     * (errors.guidance).
     */
    public function extract(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);
        $teacher = $request->user();
        $outcome = $this->documents->request($teacher, $request->only(['document_ids', 'purpose', 'page_from', 'page_to', 'guidance']));
        $extraction = $outcome['extraction'];

        return response()->json(['data' => [
            'cached' => $outcome['cached'],
            'estimate' => $outcome['cached'] ? null : $outcome['estimate'],
            'extraction' => $extraction->toApi(),
        ] + $this->documents->payload($extraction, $teacher->school_id)], $outcome['cached'] ? 200 : 202);
    }

    /**
     * POST /api/v1/courses/extract/estimate (the body of extract) -> {data:
     * {purpose, pages, cached, estimate: {input_tokens, output_tokens,
     * thb}}}: the cost shown before every read (free, queues nothing).
     */
    public function estimate(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);

        return response()->json(['data' => $this->documents->estimate($request->user(), $request->only(['document_ids', 'purpose', 'page_from', 'page_to', 'guidance']))]);
    }

    /** POST /api/v1/courses/import (see CourseImporter) -> 201 {data: course detail} */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);
        $course = $this->importer->import($request->user(), $request->all());

        return response()->json(['data' => CourseResource::detail(CourseResource::loadDetail($course->refresh()))], 201);
    }
}
