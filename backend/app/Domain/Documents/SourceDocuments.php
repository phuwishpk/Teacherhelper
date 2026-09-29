<?php

namespace App\Domain\Documents;

use App\Domain\Pages\PageFiles;
use App\Domain\Pages\PdfPageCounter;
use App\Domain\Scans\ScanFiles;
use App\Exceptions\ApiException;
use App\Models\DocumentExtraction;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Teachers' documents (DESIGN §19.5, §19.8 `source_documents`): answer
 * keys and question sheets uploaded with POST /documents, kept as they came
 * at documents/{school}/{sha256}.{ext} on the private disk.
 *
 * - accepted: what Gemini reads directly (JPEG, PNG, WebP, HEIC/HEIF, PDF);
 *   Word and Google Docs are refused (422 unsupported_file_type "บันทึกเป็น
 *   PDF แล้วแนบใหม่"), as is anything else;
 * - at most eduvision.documents.max_file_mb per file (422 file_too_large);
 * - a PDF whose pages cannot be counted is unreadable (422 pdf_unreadable);
 * - the same file uploaded again in the school reuses its row (found by
 *   sha256), so the read-once cache (document_extractions) finds it.
 *
 * The files are deleted after eduvision.documents.retention_days (purge());
 * what Gemini read from them stays.
 */
final class SourceDocuments
{
    private const WORD_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'pages', 'gdoc', 'ppt', 'pptx', 'xls', 'xlsx'];

    public static function disk(): FilesystemAdapter
    {
        return ScanFiles::disk();
    }

    public static function path(int $schoolId, string $sha256, string $mimeType): string
    {
        return "documents/{$schoolId}/{$sha256}.".(PageFiles::TYPES[$mimeType] ?? 'bin');
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<SourceDocument>
     *
     * @throws ApiException
     */
    public function store(User $teacher, array $files): array
    {
        $checked = [];
        foreach (array_values($files) as $i => $file) {
            $checked[] = self::check($file, "files.{$i}");
        }

        $documents = [];
        foreach ($checked as $item) {
            $documents[] = $this->keep($teacher, $item);
        }

        return $documents;
    }

    /**
     * The API form of an uploaded document (POST /documents).
     *
     * @return array<string, mixed>
     */
    public static function toApi(SourceDocument $document): array
    {
        $cached = DocumentExtraction::query()
            ->where('school_id', $document->school_id)
            ->where('input_hash', $document->sha256)
            ->where('status', DocumentExtraction::STATUS_DONE)
            ->orderBy('purpose')
            ->pluck('purpose')
            ->values()
            ->all();

        return [
            'id' => $document->id,
            'sha256' => $document->sha256,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'page_count' => $document->page_count,
            'needs_page_range' => $document->page_count > (int) config('eduvision.documents.max_pages'),
            'cached_purposes' => $cached,
            'estimate' => CostEstimate::forPages($document->page_count),
        ];
    }

    /** Deletes the files of documents older than the retention (eduvision:purge-images). */
    public static function purge(): int
    {
        $cutoff = now()->subDays((int) config('eduvision.documents.retention_days'));
        $disk = self::disk();
        $count = 0;
        SourceDocument::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($documents) use ($disk, &$count) {
                foreach ($documents as $document) {
                    $disk->delete($document->file_path);
                    $document->file_path = null;
                    $document->save();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return array{bytes: string, mime_type: string, name: string, page_count: int}
     */
    private static function check(UploadedFile $file, string $field): array
    {
        $name = Str::limit(trim((string) $file->getClientOriginalName()) ?: 'document', 250, '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $declared = strtolower((string) $file->getClientMimeType());
        $isWord = in_array($extension, self::WORD_EXTENSIONS, true)
            || str_contains($declared, 'officedocument')
            || str_contains($declared, 'msword')
            || str_starts_with($declared, 'application/vnd.google-apps.');
        $type = $isWord ? null : (PageFiles::acceptedType($declared, $name) ?? PageFiles::acceptedType($file->getMimeType(), $name));
        if ($type === null) {
            $message = $isWord
                ? "ไฟล์ \"{$name}\" เป็น Word หรือ Google Docs ซึ่งอ่านไม่ได้ บันทึกเป็น PDF แล้วแนบใหม่"
                : "ไฟล์ \"{$name}\" เป็นชนิดที่อ่านไม่ได้ แนบเป็นรูป (JPEG, PNG, WebP, HEIC) หรือ PDF";

            throw new ApiException($message, 'unsupported_file_type', 422, [$field => [$message]]);
        }

        $maxMb = (int) config('eduvision.documents.max_file_mb');
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            $message = "ไฟล์ \"{$name}\" ใหญ่เกิน {$maxMb} MB";

            throw new ApiException($message, 'file_too_large', 422, [$field => [$message]]);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        if ($bytes === '') {
            throw new ApiException("ไฟล์ \"{$name}\" ว่างเปล่า", 'unsupported_file_type', 422, [$field => ["ไฟล์ \"{$name}\" ว่างเปล่า"]]);
        }
        $pages = 1;
        if ($type === 'application/pdf') {
            $pages = PdfPageCounter::count($bytes);
            if ($pages === 0) {
                $message = 'อ่านไฟล์ PDF นี้ไม่ได้ ส่งเป็นรูป หรือบันทึกเป็น PDF ใหม่';

                throw new ApiException($message, 'pdf_unreadable', 422, [$field => [$message]]);
            }
        }

        return ['bytes' => $bytes, 'mime_type' => $type, 'name' => $name, 'page_count' => min(65535, $pages)];
    }

    /**
     * @param  array{bytes: string, mime_type: string, name: string, page_count: int}  $item
     */
    private function keep(User $teacher, array $item): SourceDocument
    {
        $sha = hash('sha256', $item['bytes']);
        $schoolId = (int) $teacher->school_id;
        $path = self::path($schoolId, $sha, $item['mime_type']);
        $disk = self::disk();

        $existing = SourceDocument::query()->where('school_id', $schoolId)->where('sha256', $sha)->orderByDesc('id')->first();
        if ($existing !== null && $existing->file_path !== null && $disk->exists($existing->file_path)) {
            return $existing;
        }

        $disk->put($path, $item['bytes']);
        if ($existing !== null) {
            // The file was purged: the same bytes come back under the same row.
            $existing->forceFill(['file_path' => $path, 'created_at' => now()])->save();

            return $existing;
        }

        return SourceDocument::create([
            'school_id' => $schoolId,
            'uploaded_by' => $teacher->id,
            'sha256' => $sha,
            'original_name' => $item['name'],
            'mime_type' => $item['mime_type'],
            'size_bytes' => strlen($item['bytes']),
            'page_count' => $item['page_count'],
            'file_path' => $path,
        ]);
    }
}
