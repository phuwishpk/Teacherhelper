<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Documents\SourceDocuments;
use App\Exceptions\ApiException;
use App\Jobs\CropExamFiguresJob;
use App\Models\Assignment;
use App\Models\ExamImport;
use App\Models\ExamPageImage;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SourceDocument;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Figures of questions read from an exam file (DESIGN §22.4): a light GD
 * crop of the page the figure is on, never a PDF render on the server.
 *
 * - figure_source of a question or option read from a file:
 *   {source_document_id, page_no, box_2d, page_image_id}; page_image_id is
 *   null while the figure waits for its page image ("pending"). A figure
 *   the teacher boxed again keeps the same shape; a picture the teacher
 *   attached has figure_source NULL.
 * - Page images (exam_page_images): a photo document (JPEG/PNG/WebP, at most
 *   10 MB and 6,000 px on the long side) is decoded by the server itself in
 *   CropExamFiguresJob; a PDF or HEIC page is rendered by the app and
 *   uploaded (storeUpload). Pages are kept at most PAGE_MAX_PX on the long
 *   side.
 * - The crop: box_2d [ymin, xmin, ymax, xmax] in 0–1000 of the page, grown
 *   by PAD (2% of the page) on every side, the long side scaled to at most
 *   eduvision.exams.image_max_px, JPEG 85 at the figure paths of ExamImages.
 */
final class ExamFigures
{
    /** 2% of the page (0–1000 scale) added around a box. */
    public const PAD = 20;

    /** A box smaller than this (0–1000 scale) on either side is refused. */
    private const MIN_BOX = 5;

    /** Long side of a stored page image (the app renders at most this). */
    public const PAGE_MAX_PX = 2000;

    /** Photos the server decodes itself (DESIGN §22.4). */
    public const SERVER_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const SERVER_MAX_BYTES = 10 * 1024 * 1024;

    public const SERVER_MAX_PX = 6000;

    /** Pending reasons: the app renders the page, or the file is gone. */
    public const NEEDS_RENDER = 'needs_render';

    public const DOCUMENT_MISSING = 'document_missing';

    /**
     * A valid box_2d [ymin, xmin, ymax, xmax] in 0–1000, else null.
     *
     * @return list<int>|null
     */
    public static function box(mixed $box): ?array
    {
        if (! is_array($box) || ! array_is_list($box) || count($box) !== 4) {
            return null;
        }
        $out = [];
        foreach ($box as $value) {
            if (! is_int($value) && ! (is_float($value) && floor($value) === $value) && ! (is_string($value) && ctype_digit($value))) {
                return null;
            }
            $value = (int) $value;
            if ($value < 0 || $value > 1000) {
                return null;
            }
            $out[] = $value;
        }
        [$ymin, $xmin, $ymax, $xmax] = $out;
        if ($ymax - $ymin < self::MIN_BOX || $xmax - $xmin < self::MIN_BOX) {
            return null;
        }

        return $out;
    }

    public static function pagePath(ExamPageImage $page): string
    {
        return "exams/{$page->school_id}/{$page->assignment_id}/pages/{$page->id}.jpg";
    }

    /**
     * Pages whose figures still wait for a page image (no exam_page_images
     * row yet, or only one whose file was purged): the app renders them, or
     * cannot because the file is gone.
     *
     * @return list<array{source_document_id: int, page_no: int, original_name: string|null, mime_type: string|null, figures: int, reason: string}>
     */
    public static function pending(Assignment $exam): array
    {
        $counts = [];
        foreach (self::pendingTargets($exam) as $target) {
            $key = $target->figure_source['source_document_id'].':'.$target->figure_source['page_no'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        if ($counts === []) {
            return [];
        }
        // A page image counts while it has its file, or while the server still decodes it (size 0);
        // one whose file was purged (30 days) leaves its waiting figures pending again.
        $pages = ExamPageImage::query()->where('assignment_id', $exam->id)
            ->where(fn ($q) => $q->whereNotNull('file_path')->orWhere('width_px', 0))->get()
            ->mapWithKeys(fn (ExamPageImage $p) => [$p->source_document_id.':'.$p->page_no => true]);
        $documents = SourceDocument::query()
            ->whereIn('id', array_map(fn (string $k) => (int) explode(':', $k)[0], array_keys($counts)))
            ->get()->keyBy('id');

        $out = [];
        foreach ($counts as $key => $count) {
            if ($pages->has($key)) {
                continue;
            }
            [$documentId, $pageNo] = array_map('intval', explode(':', $key));
            $document = $documents->get($documentId);
            $stored = $document?->file_path !== null && SourceDocuments::disk()->exists($document->file_path);
            $out[] = [
                'source_document_id' => $documentId,
                'page_no' => $pageNo,
                'original_name' => $document?->original_name,
                'mime_type' => $document?->mime_type,
                'figures' => $count,
                'reason' => $stored ? self::NEEDS_RENDER : self::DOCUMENT_MISSING,
            ];
        }
        usort($out, fn (array $a, array $b) => [$a['source_document_id'], $a['page_no']] <=> [$b['source_document_id'], $b['page_no']]);

        return $out;
    }

    /**
     * After a read was applied: every page with waiting figures that already
     * has a page image (the same file read into this exam before) is cropped
     * again, and every page of a photo document the server can decode gets
     * its page image row; CropExamFiguresJob decodes it and crops.
     */
    public static function queueServerPages(Assignment $exam): void
    {
        $keys = [];
        foreach (self::pendingTargets($exam) as $target) {
            $keys[(int) $target->figure_source['source_document_id'].':'.(int) $target->figure_source['page_no']] = true;
        }
        if ($keys === []) {
            return;
        }
        $rows = ExamPageImage::query()->where('assignment_id', $exam->id)->get()
            ->keyBy(fn (ExamPageImage $p) => $p->source_document_id.':'.$p->page_no);
        $imported = self::importedDocumentIds($exam);
        foreach (array_keys($keys) as $key) {
            [$documentId, $pageNo] = array_map('intval', explode(':', $key));
            $row = $rows->get($key);
            if ($row === null) {
                $document = SourceDocument::query()->find($documentId);
                $decodable = $document !== null && $pageNo === 1
                    && in_array($documentId, $imported, true)
                    && in_array($document->mime_type, self::SERVER_TYPES, true)
                    && $document->size_bytes <= self::SERVER_MAX_BYTES
                    && $document->file_path !== null;
                if (! $decodable) {
                    continue; // the app renders it (PDF, HEIC, a photo too large for GD here)
                }
                $row = ExamPageImage::create([
                    'school_id' => $exam->school_id, 'assignment_id' => $exam->id, 'source_document_id' => $documentId,
                    'page_no' => 1, 'width_px' => 0, 'height_px' => 0, 'uploaded_by' => null,
                ]);
            }
            CropExamFiguresJob::dispatch($row->id);
        }
    }

    /**
     * CropExamFiguresJob: decodes a photo page the server owns if its file
     * is not written yet, then crops every figure still waiting for this
     * page.
     *
     * @return int figures cropped
     */
    public static function cropPage(int $pageImageId): int
    {
        $page = ExamPageImage::query()->find($pageImageId);
        if ($page === null) {
            return 0;
        }
        if ($page->file_path === null && ! self::decodeServerPage($page)) {
            return 0;
        }
        $image = self::loadPage($page);
        if ($image === null) {
            return 0;
        }

        return AssignmentLocked::run($page->assignment_id, function (Assignment $exam) use ($page, $image) {
            $cropped = 0;
            foreach (self::pendingTargets($exam) as $target) {
                $source = $target->figure_source;
                if ((int) $source['source_document_id'] !== $page->source_document_id || (int) $source['page_no'] !== $page->page_no) {
                    continue;
                }
                $box = self::box($source['box_2d'] ?? null);
                if ($box === null) {
                    continue;
                }
                self::write($exam, $target, $image, $page, $box);
                $cropped++;
            }

            return $cropped;
        }, allowClosed: true);
    }

    /**
     * PUT /questions/{id}/figure, /question-options/{id}/figure
     * {page_image_id, box_2d}: the teacher's own box on a page image of the
     * same exam, cropped at once.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException errors.page_image_id / errors.box_2d
     * @throws ApiException 422 document_missing (the page image was deleted)
     */
    public static function recrop(Question|QuestionOption $target, array $input): void
    {
        $question = $target instanceof Question ? $target : $target->question;
        AssignmentLocked::run($question->assignment_id, function (Assignment $exam) use ($target, $input) {
            $id = filter_var($input['page_image_id'] ?? null, FILTER_VALIDATE_INT);
            $page = $id === false ? null : ExamPageImage::query()->where('assignment_id', $exam->id)->find($id);
            if ($page === null) {
                throw ValidationException::withMessages(['page_image_id' => 'ไม่พบภาพหน้าเอกสารนี้ในข้อสอบ']);
            }
            $box = self::box($input['box_2d'] ?? null);
            if ($box === null) {
                throw ValidationException::withMessages(['box_2d' => 'กรอบต้องเป็น [บน, ซ้าย, ล่าง, ขวา] ในช่วง 0–1000 และไม่เล็กเกินไป']);
            }
            $image = self::loadPage($page);
            if ($image === null) {
                throw new ApiException('ภาพหน้าเอกสารนี้ถูกลบแล้ว แนบไฟล์ข้อสอบใหม่ หรือแนบรูปเอง', 'document_missing', 422, ['page_image_id' => ['ภาพหน้าเอกสารนี้ถูกลบแล้ว']]);
            }
            $target = $target::query()->findOrFail($target->id);
            self::write($exam, $target, $image, $page, $box);
            if ($target instanceof Question) {
                // A blank question of a manual exam now has a prompt (DESIGN §22.4).
                ExamEditor::autoApprove($target->refresh(), $exam);
            }
        });
    }

    /**
     * POST /exams/{id}/page-images multipart {source_document_id, page_no,
     * image}: a page the app rendered (JPEG, at most 10 MB). The document
     * must be one this exam read (exam_imports); the same page again
     * replaces the image. The figures waiting for it are cropped by
     * CropExamFiguresJob.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function storeUpload(Assignment $exam, User $teacher, array $input, mixed $file): ExamPageImage
    {
        $v = Validator::make($input, [
            'source_document_id' => ['required', 'integer', 'min:1'],
            'page_no' => ['required', 'integer', 'min:1', 'max:65535'],
        ], [
            'source_document_id.required' => 'กรุณาระบุไฟล์ของหน้านี้',
            'page_no.required' => 'กรุณาระบุเลขหน้า',
            'page_no.integer' => 'เลขหน้าต้องเป็นตัวเลข',
            'page_no.min' => 'เลขหน้าต้องเริ่มที่ 1',
        ])->validate();
        $documentId = (int) $v['source_document_id'];
        $pageNo = (int) $v['page_no'];
        if (! in_array($documentId, self::importedDocumentIds($exam), true)) {
            throw ValidationException::withMessages(['source_document_id' => 'ไฟล์นี้ไม่ได้ถูกอ่านเข้าข้อสอบนี้']);
        }
        $document = SourceDocument::query()->findOrFail($documentId);
        if ($pageNo > $document->page_count) {
            throw ValidationException::withMessages(['page_no' => "ไฟล์นี้มี {$document->page_count} หน้า"]);
        }

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['image' => 'กรุณาแนบภาพหน้าเอกสาร']);
        }
        if ($file->getSize() > self::SERVER_MAX_BYTES) {
            throw ValidationException::withMessages(['image' => 'ภาพหน้าเอกสารต้องมีขนาดไม่เกิน 10 MB']);
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw ValidationException::withMessages(['image' => 'ภาพหน้าเอกสารต้องเป็นไฟล์ JPEG']);
        }
        if ($info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > self::SERVER_MAX_PX) {
            throw ValidationException::withMessages(['image' => 'ภาพหน้าเอกสารใหญ่เกินไป ด้านยาวต้องไม่เกิน '.number_format(self::SERVER_MAX_PX).' พิกเซล']);
        }
        $size = [$info[0], $info[1]];
        if (max($size) > self::PAGE_MAX_PX && self::fitsInMemory($size[0], $size[1])) {
            // Larger than the app renders: kept at PAGE_MAX_PX like a page the server decodes.
            $image = @imagecreatefromstring($bytes);
            if (! $image instanceof GdImage) {
                throw ValidationException::withMessages(['image' => 'ภาพหน้าเอกสารต้องเป็นไฟล์ JPEG']);
            }
            $scaled = ExamImages::scaled($image, self::PAGE_MAX_PX);
            unset($image);
            $bytes = ExamImages::jpeg($scaled);
            $size = [imagesx($scaled), imagesy($scaled)];
        }

        $page = AssignmentLocked::run($exam->id, function (Assignment $exam) use ($teacher, $document, $pageNo, $bytes, $size) {
            $page = ExamPageImage::query()->firstOrNew(['assignment_id' => $exam->id, 'source_document_id' => $document->id, 'page_no' => $pageNo]);
            $page->fill(['school_id' => $exam->school_id, 'width_px' => $size[0], 'height_px' => $size[1], 'uploaded_by' => $teacher->id]);
            $page->save();
            $path = self::pagePath($page);
            ExamImages::disk()->put($path, $bytes);
            $page->file_path = $path;
            // A new file on an old (purged) row starts its retention again, or
            // purge() would take it on the next run (created_at is the file's age).
            $page->created_at = now();
            $page->save();

            return $page;
        }, allowClosed: true);

        CropExamFiguresJob::dispatch($page->id);

        return $page->refresh();
    }

    /**
     * Source documents the exam read (exam_imports), in import order: only
     * the files the teacher who asked for the read uploaded themselves
     * (DESIGN §22.17), so a colleague's file never unlocks the download or
     * a page upload even if an import names it.
     *
     * @return list<int>
     */
    public static function importedDocumentIds(Assignment $exam): array
    {
        $imports = ExamImport::query()->where('assignment_id', $exam->id)->orderBy('id')->get();
        $all = [];
        foreach ($imports as $import) {
            array_push($all, ...$import->documentIds());
        }
        $uploaders = SourceDocument::query()->whereIn('id', $all === [] ? [0] : array_values(array_unique($all)))->pluck('uploaded_by', 'id');

        $ids = [];
        foreach ($imports as $import) {
            foreach ($import->documentIds() as $id) {
                if ((int) ($uploaders[$id] ?? 0) === (int) $import->requested_by) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Page images older than the document retention lose their file (eduvision:purge-images). */
    public static function purge(): int
    {
        $cutoff = now()->subDays((int) config('eduvision.documents.retention_days'));
        $count = 0;
        ExamPageImage::query()->whereNotNull('file_path')->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($pages) use (&$count) {
                foreach ($pages as $page) {
                    ExamImages::disk()->delete($page->file_path);
                    $page->file_path = null;
                    $page->save();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Questions and options of the exam whose figure waits for a page image.
     *
     * @return Collection<int, Question|QuestionOption>
     */
    private static function pendingTargets(Assignment $exam): Collection
    {
        $questions = Question::query()->where('assignment_id', $exam->id)->whereNotNull('figure_source')->get();
        $options = QuestionOption::query()->whereNotNull('figure_source')
            ->whereIn('question_id', Question::query()->select('id')->where('assignment_id', $exam->id))
            ->get();

        return $questions->concat($options)->filter(fn ($t) => self::isPending($t->figure_source))->values();
    }

    /** @param  array<string, mixed>|null  $source */
    public static function isPending(?array $source): bool
    {
        return is_array($source)
            && ($source['page_image_id'] ?? null) === null
            && isset($source['source_document_id'], $source['page_no']);
    }

    private static function write(Assignment $exam, Question|QuestionOption $target, GdImage $image, ExamPageImage $page, array $box): void
    {
        $path = $target instanceof Question ? ExamImages::questionPath($target, $exam) : ExamImages::optionPath($target, $exam);
        ExamImages::writeJpeg(self::crop($image, $box), $path);
        $source = [
            'page_image_id' => $page->id,
            'source_document_id' => $page->source_document_id,
            'page_no' => $page->page_no,
            'box_2d' => $box,
        ];
        $target->forceFill([$target instanceof Question ? 'prompt_image_path' : 'image_path' => $path, 'figure_source' => $source])->save();
    }

    /** The box grown by PAD, cut out and scaled to the figure size. */
    private static function crop(GdImage $page, array $box): GdImage
    {
        [$ymin, $xmin, $ymax, $xmax] = $box;
        $w = imagesx($page);
        $h = imagesy($page);
        $x0 = max(0, (int) floor(max(0, $xmin - self::PAD) / 1000 * $w));
        $y0 = max(0, (int) floor(max(0, $ymin - self::PAD) / 1000 * $h));
        $x1 = min($w, (int) ceil(min(1000, $xmax + self::PAD) / 1000 * $w));
        $y1 = min($h, (int) ceil(min(1000, $ymax + self::PAD) / 1000 * $h));
        $cw = max(1, $x1 - $x0);
        $ch = max(1, $y1 - $y0);

        $cut = imagecreatetruecolor($cw, $ch);
        imagecopy($cut, $page, 0, 0, $x0, $y0, $cw, $ch);

        return ExamImages::scaled($cut);
    }

    /**
     * Whether GD can decode a w x h picture (truecolor, about 5 bytes a
     * pixel with the scaled copy) within the PHP memory_limit left, so a
     * 6,000 px photo on shared hosting fails softly instead of fatally.
     */
    private static function fitsInMemory(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $width * $height * 5 + 16 * 1024 * 1024 <= $bytes - memory_get_usage();
    }

    private static function loadPage(ExamPageImage $page): ?GdImage
    {
        if ($page->file_path === null || ! ExamImages::disk()->exists($page->file_path)) {
            return null;
        }
        $image = @imagecreatefromstring((string) ExamImages::disk()->get($page->file_path));

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * Writes the page image of a photo document the server decodes itself.
     * When it cannot (file gone, too large, not an image GD reads), the row
     * goes, so the page is pending again for the app to render.
     */
    private static function decodeServerPage(ExamPageImage $page): bool
    {
        $document = $page->source_document_id === null ? null : SourceDocument::query()->find($page->source_document_id);
        $disk = SourceDocuments::disk();
        $bytes = $document?->file_path !== null && $disk->exists($document->file_path) ? (string) $disk->get($document->file_path) : '';
        $info = $bytes === '' || strlen($bytes) > self::SERVER_MAX_BYTES ? false : @getimagesizefromstring($bytes);
        $ok = $info !== false
            && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            && $info[0] >= 1 && $info[1] >= 1 && max($info[0], $info[1]) <= self::SERVER_MAX_PX
            && self::fitsInMemory($info[0], $info[1]);
        $image = $ok ? @imagecreatefromstring($bytes) : false;
        if (! $image instanceof GdImage) {
            Log::info('exam_figures.server_decode_failed', ['page_image_id' => $page->id, 'source_document_id' => $page->source_document_id]);
            $page->delete();

            return false;
        }

        $scaled = ExamImages::scaled($image, self::PAGE_MAX_PX);
        $path = self::pagePath($page);
        ExamImages::writeJpeg($scaled, $path);
        // created_at is the file's age for purge(): a purged row decoded again starts over.
        $page->forceFill(['file_path' => $path, 'width_px' => imagesx($scaled), 'height_px' => imagesy($scaled), 'created_at' => now()])->save();

        return true;
    }
}
