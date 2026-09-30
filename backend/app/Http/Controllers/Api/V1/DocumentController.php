<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Courses\CourseDocumentResult;
use App\Domain\Courses\CourseDocuments;
use App\Domain\Documents\SourceDocuments;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DocumentExtraction;
use App\Models\SourceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Teachers' documents and what Gemini read from them (DESIGN §19.5, §19.9).
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly SourceDocuments $documents,
        private readonly CourseDocuments $courseDocuments,
    ) {}

    /**
     * POST /api/v1/documents multipart files[] -> 201 {data: [{id, sha256,
     * original_name, mime_type, size_bytes, page_count, needs_page_range,
     * cached_purposes[], estimate: {input_tokens, output_tokens, thb}}]}.
     * cached_purposes: the file was read before in this school (reading it
     * again costs nothing). 422 unsupported_file_type (Word, Google Docs,
     * anything but a photo or PDF), file_too_large, pdf_unreadable.
     */
    public function store(Request $request): JsonResponse
    {
        $max = (int) config('eduvision.documents.max_files');
        $files = $request->file('files');
        $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values($files) : []);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));
        if ($files === []) {
            throw new ApiException('กรุณาแนบไฟล์อย่างน้อย 1 ไฟล์', 'validation_failed', 422, ['files' => ['กรุณาแนบไฟล์อย่างน้อย 1 ไฟล์']]);
        }
        if (count($files) > $max) {
            throw new ApiException("แนบได้ไม่เกิน {$max} ไฟล์ต่อครั้ง", 'validation_failed', 422, ['files' => ["แนบได้ไม่เกิน {$max} ไฟล์ต่อครั้ง"]]);
        }
        foreach ($files as $i => $file) {
            if (! $file->isValid()) {
                // Bigger than PHP's upload_max_filesize, or a broken upload.
                $message = 'อัปโหลดไฟล์ไม่สำเร็จ ไฟล์อาจใหญ่เกิน '.config('eduvision.documents.max_file_mb').' MB';

                throw new ApiException($message, 'file_too_large', 422, ["files.{$i}" => [$message]]);
            }
        }

        $stored = $this->documents->store($request->user(), $files);

        return response()->json(['data' => array_map(fn (SourceDocument $d) => SourceDocuments::toApi($d), $stored)], 201);
    }

    /**
     * GET /api/v1/document-extractions/{id} -> {data: {id, purpose, status,
     * error, created_at, updated_at, result}} of the teacher's own school
     * (404 otherwise). result: the structured key (or course, DESIGN
     * §20.1) once `done`. A course or lesson-plan read adds
     * indicator_matches: [{code, skill|null}] for the teacher's school.
     */
    public function extraction(Request $request, int $id): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $extraction = DocumentExtraction::query()->where('school_id', $schoolId)->findOrFail($id);

        if (in_array($extraction->purpose, CourseDocumentResult::KINDS, true)) {
            return response()->json(['data' => $extraction->toApi() + $this->courseDocuments->payload($extraction, $schoolId)]);
        }

        return response()->json(['data' => $extraction->toApi() + [
            'result' => $extraction->isDone() ? $extraction->result : null,
        ]]);
    }
}
