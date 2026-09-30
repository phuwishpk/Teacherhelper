<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\SourceDocuments;
use App\Domain\Exams\ExamDocuments;
use App\Domain\Exams\ExamFigures;
use App\Domain\Exams\ExamImages;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ExamPageImage;
use App\Models\SourceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading a teacher's exam file into draft sections and questions (DESIGN
 * §22.4, §22.15): the read itself (once per school, cached), the page
 * images the figures are cropped from, and the source files the app renders
 * pages from. Only the owner's exams; anything else is a 404.
 */
class ExamImportController extends Controller
{
    public function __construct(private readonly ExamDocuments $documents) {}

    /**
     * POST /api/v1/exams/{id}/import {document_ids[], page_from?, page_to?,
     * guidance?} -> 200 cached {data: {cached: true, estimate: null,
     * extraction, import, applied: {sections, questions, skipped[]},
     * figures_pending[]}} or 202 queued {data: {cached: false, estimate,
     * extraction, import, applied: null, figures_pending: []}}; poll GET
     * /document-extractions/{id}, then GET /exams/{id}. Every question comes
     * as a draft the teacher approves. 409 exam_structure_locked /
     * assignment_closed; 422 ai_key_missing, document_too_long,
     * document_missing, file_too_large, validation_failed.
     */
    public function import(Request $request, int $id): JsonResponse
    {
        $exam = ExamController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $outcome = $this->documents->request($exam, $request->user(), $request->only(ExamDocuments::INPUT_FIELDS));

        return response()->json(['data' => [
            'cached' => $outcome['cached'],
            'estimate' => $outcome['cached'] ? null : $outcome['estimate'],
            'extraction' => $outcome['extraction']->toApi(),
            'import' => $outcome['import']->toApi(),
            'applied' => $outcome['applied'],
            'figures_pending' => $outcome['applied'] === null ? [] : ExamFigures::pending($exam->refresh()),
        ]], $outcome['cached'] ? 200 : 202);
    }

    /**
     * POST /api/v1/exams/{id}/import/estimate (the body of import) -> {data:
     * {pages, cached, estimate: {input_tokens, output_tokens, thb}}}: the
     * cost shown before every read (free, queues nothing).
     */
    public function estimate(Request $request, int $id): JsonResponse
    {
        $exam = ExamController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        return response()->json(['data' => $this->documents->estimate($request->user(), $request->only(ExamDocuments::INPUT_FIELDS))]);
    }

    /**
     * POST /api/v1/exams/{id}/page-images multipart {source_document_id,
     * page_no, image (JPEG, 10 MB)} -> 201 {data: {page_image,
     * figures_pending[]}}: a page the app rendered from a PDF or HEIC; the
     * figures waiting for it are cropped (CropExamFiguresJob).
     */
    public function storePageImage(Request $request, int $id): JsonResponse
    {
        $exam = ExamController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $page = ExamFigures::storeUpload($exam, $request->user(), $request->only(['source_document_id', 'page_no']), $request->file('image'));

        return response()->json(['data' => [
            'page_image' => $page->toApi(),
            'figures_pending' => ExamFigures::pending($exam),
        ]], 201);
    }

    /** GET /api/v1/exam-page-images/{id} -> image/jpeg (for drawing a box); 404 document_missing once deleted */
    public function showPageImage(Request $request, int $id): StreamedResponse
    {
        $page = ExamPageImage::query()->whereIn('assignment_id', ExamController::ownQuery($request)->select('assignments.id'))->findOrFail($id);
        Gate::authorize('update', $page->assignment);
        if ($page->file_path === null || ! ExamImages::disk()->exists($page->file_path)) {
            throw new ApiException('ภาพหน้าเอกสารนี้ถูกลบแล้ว', 'document_missing', 404);
        }

        return ExamImages::disk()->response($page->file_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * GET /api/v1/exams/{id}/documents/{document_id}/file -> the source file
     * for the app to render pages from. Only a file this exam read
     * (exam_imports) of the caller's own exam: any other id is a 404, even a
     * file of the same school (source_documents are shared by SHA-256).
     * 404 document_missing once the file was deleted (30 days).
     */
    public function documentFile(Request $request, int $id, int $document_id): StreamedResponse
    {
        $exam = ExamController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);
        abort_unless(in_array($document_id, ExamFigures::importedDocumentIds($exam), true), 404);
        $document = SourceDocument::query()->where('school_id', $exam->school_id)->findOrFail($document_id);
        $disk = SourceDocuments::disk();
        if ($document->file_path === null || ! $disk->exists($document->file_path)) {
            throw new ApiException('ไฟล์นี้ถูกลบจากเครื่องแม่ข่ายแล้ว (เก็บไว้ 30 วัน) แนบไฟล์ใหม่อีกครั้ง', 'document_missing', 404);
        }

        return $disk->response($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
