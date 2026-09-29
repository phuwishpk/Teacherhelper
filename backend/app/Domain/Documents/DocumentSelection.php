<?php

namespace App\Domain\Documents;

use App\Domain\Pages\PdfRange;
use App\Exceptions\ApiException;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * The documents one read uses ({document_ids[], page_from?, page_to?},
 * DESIGN §19.5): uploaded in the teacher's school, at most
 * eduvision.documents.max_pages pages in all, or one PDF with a page range
 * of at most that many pages (422 document_too_long without one).
 *
 * inputHash() is the read-once cache key (document_extractions.input_hash):
 * the file's SHA-256 for one whole file, otherwise the SHA-256 of the sorted
 * file hashes plus the page range.
 */
final readonly class DocumentSelection
{
    /**
     * @param  list<SourceDocument>  $documents  in the order asked for
     */
    public function __construct(
        public array $documents,
        public ?int $pageFrom = null,
        public ?int $pageTo = null,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>  $input  {document_ids, page_from?, page_to?}
     *
     * @throws ApiException
     */
    public static function resolve(User $teacher, array $input, bool $required = true): self
    {
        $maxFiles = (int) config('eduvision.documents.max_files');
        $validated = Validator::make($input, [
            'document_ids' => [$required ? 'required' : 'sometimes', 'nullable', 'array', 'max:'.$maxFiles],
            'document_ids.*' => ['integer', 'distinct', 'min:1'],
            'page_from' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535', 'required_with:page_to'],
            'page_to' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535', 'required_with:page_from', 'gte:page_from'],
        ], [
            'document_ids.required' => 'กรุณาแนบไฟล์เฉลยอย่างน้อย 1 ไฟล์',
            'document_ids.array' => 'รายการไฟล์ไม่ถูกต้อง',
            'document_ids.max' => "แนบได้ไม่เกิน {$maxFiles} ไฟล์ต่อครั้ง",
            'document_ids.*.integer' => 'รหัสไฟล์ไม่ถูกต้อง',
            'document_ids.*.distinct' => 'เลือกไฟล์ซ้ำกัน',
            'page_from.required_with' => 'กรุณาระบุหน้าแรกของช่วง',
            'page_to.required_with' => 'กรุณาระบุหน้าสุดท้ายของช่วง',
            'page_to.gte' => 'หน้าสุดท้ายต้องไม่น้อยกว่าหน้าแรก',
            'page_from.integer' => 'เลขหน้าต้องเป็นตัวเลข',
            'page_to.integer' => 'เลขหน้าต้องเป็นตัวเลข',
            'page_from.min' => 'เลขหน้าต้องเริ่มที่ 1',
            'page_to.min' => 'เลขหน้าต้องเริ่มที่ 1',
        ])->validate();

        $ids = array_values(array_map('intval', $validated['document_ids'] ?? []));
        $found = SourceDocument::query()
            ->where('school_id', $teacher->school_id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->get()
            ->keyBy('id');
        if ($found->count() !== count($ids)) {
            throw new ApiException('ไม่พบไฟล์ที่เลือก แนบไฟล์ใหม่อีกครั้ง', 'validation_failed', 422, ['document_ids' => ['ไม่พบไฟล์ที่เลือก']]);
        }
        $documents = array_map(fn (int $id) => $found[$id], $ids);

        $from = isset($validated['page_from']) ? (int) $validated['page_from'] : null;
        $to = isset($validated['page_to']) ? (int) $validated['page_to'] : null;
        $max = (int) config('eduvision.documents.max_pages');

        if ($from !== null && $to !== null) {
            if (count($documents) !== 1 || ! $documents[0]->isPdf()) {
                throw new ApiException('เลือกช่วงหน้าได้เมื่อแนบ PDF ไฟล์เดียว', 'validation_failed', 422, ['page_from' => ['เลือกช่วงหน้าได้เมื่อแนบ PDF ไฟล์เดียว']]);
            }
            if ($to > $documents[0]->page_count) {
                throw new ApiException("ไฟล์นี้มี {$documents[0]->page_count} หน้า", 'validation_failed', 422, ['page_to' => ["ไฟล์นี้มี {$documents[0]->page_count} หน้า"]]);
            }
            if ($to - $from + 1 > $max) {
                throw new ApiException("เลือกได้ไม่เกิน {$max} หน้าต่อครั้ง", 'document_too_long', 422, ['page_to' => ["เลือกได้ไม่เกิน {$max} หน้าต่อครั้ง"]]);
            }

            return new self($documents, $from, $to);
        }

        $selection = new self($documents);
        if ($selection->pageCount() > $max) {
            $message = "เอกสารยาว {$selection->pageCount()} หน้า เกิน {$max} หน้า เลือกช่วงหน้าที่ต้องใช้ก่อน";

            throw new ApiException($message, 'document_too_long', 422, ['page_from' => [$message]]);
        }

        return $selection;
    }

    public function isEmpty(): bool
    {
        return $this->documents === [];
    }

    public function hasRange(): bool
    {
        return $this->pageFrom !== null && $this->pageTo !== null;
    }

    /** Pages Gemini will read. */
    public function pageCount(): int
    {
        if ($this->hasRange()) {
            return (int) $this->pageTo - (int) $this->pageFrom + 1;
        }

        return array_sum(array_map(fn (SourceDocument $d) => $d->page_count, $this->documents));
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn (SourceDocument $d) => $d->id, $this->documents);
    }

    /** The files part of the cache key ('' without files). */
    public function inputHash(): string
    {
        if ($this->documents === []) {
            return '';
        }
        if (count($this->documents) === 1 && ! $this->hasRange()) {
            return $this->documents[0]->sha256;
        }
        $hashes = array_map(fn (SourceDocument $d) => $d->sha256, $this->documents);
        sort($hashes);

        return hash('sha256', implode(',', $hashes).($this->hasRange() ? "|p{$this->pageFrom}-{$this->pageTo}" : ''));
    }

    /**
     * The bytes to send, the page range cut out of its PDF. Before a read
     * (not needed on a cache hit): every file must still be stored and
     * together at most eduvision.documents.max_total_mb.
     *
     * @return list<array{bytes: string, mime_type: string, page_count: int}>
     *
     * @throws ApiException 422 document_missing / file_too_large / document_split_unsupported
     */
    public function files(): array
    {
        $disk = SourceDocuments::disk();
        $files = [];
        $total = 0;
        foreach ($this->documents as $document) {
            if ($document->file_path === null || ! $disk->exists($document->file_path)) {
                $message = "ไฟล์ \"{$document->original_name}\" ถูกลบจากเครื่องแม่ข่ายแล้ว แนบใหม่อีกครั้ง";

                throw new ApiException($message, 'document_missing', 422, ['document_ids' => [$message]]);
            }
            $bytes = (string) $disk->get($document->file_path);
            $pages = $document->page_count;
            if ($this->hasRange()) {
                $bytes = PdfRange::cut($bytes, (int) $this->pageFrom, (int) $this->pageTo);
                $pages = $this->pageCount();
            }
            $total += strlen($bytes);
            $files[] = ['bytes' => $bytes, 'mime_type' => $document->mime_type, 'page_count' => $pages];
        }

        $maxMb = (int) config('eduvision.documents.max_total_mb');
        if ($total > $maxMb * 1024 * 1024) {
            $message = "ไฟล์ที่เลือกรวมกันใหญ่เกิน {$maxMb} MB แนบทีละน้อยลง หรือเลือกช่วงหน้า";

            throw new ApiException($message, 'file_too_large', 422, ['document_ids' => [$message]]);
        }

        return $files;
    }
}
