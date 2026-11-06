<?php

namespace App\Domain\Pages;

use App\Exceptions\ApiException;
use App\Models\SubmissionPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The files of a whole-page hand-in uploaded to the API (DESIGN §19.4,
 * §19.6): the student's own submission (POST /student/assignments/{id}/
 * submission) and the teacher's upload for a student (POST /assignments/
 * {id}/students/{student_id}/pages). The same rules as a Classroom hand-in
 * (ClassroomAttachmentFetcher), answered as API errors instead:
 *
 * - at least one file (422 validation_failed, errors.files);
 * - JPEG, PNG, WebP, HEIC/HEIF or PDF only; Word and Google Docs are told to
 *   save as PDF (422 unsupported_file_type);
 * - at most SUBMISSION_MAX_FILE_MB per file (422 file_too_large; an upload
 *   PHP refused, e.g. above upload_max_filesize, counts as too large);
 * - a PDF whose pages cannot be counted (PdfPageCounter) is unreadable (422
 *   pdf_unreadable);
 * - at most SUBMISSION_MAX_PAGES pages in all, each PDF page counted (422
 *   too_many_pages).
 *
 * Nothing is stored here: the checked bytes go to WholePageSubmissions::receive().
 */
final class PageUploads
{
    /**
     * @param  mixed  $input  the request's `files` input: one file or a list
     * @return list<array{bytes: string, mime_type: string, page_count: int}>
     *
     * @throws ApiException
     */
    public static function check(mixed $input): array
    {
        $files = $input instanceof UploadedFile ? [$input] : (is_array($input) ? array_values($input) : []);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));
        if ($files === []) {
            $message = 'กรุณาแนบรูปหรือไฟล์ PDF ของงานอย่างน้อย 1 ไฟล์';

            throw new ApiException($message, 'validation_failed', 422, ['files' => [$message]]);
        }

        $maxPages = PageFiles::maxPages();
        $tooMany = "ส่งได้ไม่เกิน {$maxPages} หน้าต่อครั้ง (นับทุกหน้าของไฟล์ PDF)";
        if (count($files) > $maxPages) {
            throw new ApiException($tooMany, 'too_many_pages', 422, ['files' => [$tooMany]]);
        }

        $maxMb = (int) config('eduvision.submissions.max_file_mb');
        $checked = [];
        $pages = 0;
        foreach ($files as $i => $file) {
            $field = "files.{$i}";
            if (! $file->isValid()) {
                // Bigger than PHP's upload_max_filesize, or a broken upload.
                $message = "อัปโหลดไฟล์ไม่สำเร็จ ไฟล์อาจใหญ่เกิน {$maxMb} MB";

                throw new ApiException($message, 'file_too_large', 422, [$field => [$message]]);
            }
            $name = Str::limit(trim((string) $file->getClientOriginalName()) ?: 'page', 120, '');
            $declared = strtolower((string) $file->getClientMimeType());
            $office = PageFiles::isOfficeDocument($declared, $name);
            $type = $office ? null : (PageFiles::acceptedType($declared, $name) ?? PageFiles::acceptedType($file->getMimeType(), $name));
            if ($type === null) {
                $message = $office
                    ? "ไฟล์ \"{$name}\" เป็น Word หรือ Google Docs ซึ่งตรวจไม่ได้ บันทึกเป็น PDF หรือถ่ายรูปแล้วส่งใหม่"
                    : "ไฟล์ \"{$name}\" เป็นชนิดที่ตรวจไม่ได้ ส่งเป็นรูป (JPEG, PNG, WebP, HEIC) หรือ PDF";

                throw new ApiException($message, 'unsupported_file_type', 422, [$field => [$message]]);
            }
            if ($file->getSize() > PageFiles::maxBytes()) {
                $message = "ไฟล์ \"{$name}\" ใหญ่เกิน {$maxMb} MB";

                throw new ApiException($message, 'file_too_large', 422, [$field => [$message]]);
            }

            $bytes = (string) file_get_contents($file->getRealPath());
            if ($bytes === '') {
                $message = "ไฟล์ \"{$name}\" ว่างเปล่า";

                throw new ApiException($message, 'unsupported_file_type', 422, [$field => [$message]]);
            }
            $count = 1;
            if ($type === 'application/pdf') {
                $count = PdfPageCounter::count($bytes);
                if ($count === 0) {
                    $message = 'อ่านไฟล์ PDF นี้ไม่ได้ ส่งเป็นรูป หรือบันทึกเป็น PDF ใหม่';

                    throw new ApiException($message, 'pdf_unreadable', 422, [$field => [$message]]);
                }
            }
            $pages += $count;
            if ($pages > $maxPages) {
                throw new ApiException($tooMany, 'too_many_pages', 422, ['files' => [$tooMany]]);
            }
            $checked[] = ['bytes' => $bytes, 'mime_type' => $type, 'page_count' => $count];
        }

        return $checked;
    }

    /**
     * The API form of a stored page (no file path: the file is read through
     * GET /submission-pages/{id}/image).
     *
     * @return array{id: int, position: int, mime_type: string, page_count: int, size_bytes: int, state: string}
     */
    public static function toApi(SubmissionPage $page): array
    {
        return [
            'id' => $page->id,
            'position' => $page->position,
            'mime_type' => $page->mime_type,
            'page_count' => $page->page_count,
            'size_bytes' => $page->size_bytes,
            'state' => $page->state,
        ];
    }
}
