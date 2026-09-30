<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `exam_imports`: one "อ่านจากไฟล์" of an exam (§22.4): the
 * files in the order they were sent with their page range, who asked, and
 * the school's read (document_extractions, purpose exam) it waits for.
 * applied_at is set once the read's sections and draft questions were
 * added to the exam. The rows are also the only proof that lets the exam's
 * owner download a source file again (GET /exams/{id}/documents/{id}/file).
 *
 * @property int $id
 * @property int $assignment_id
 * @property int|null $extraction_id
 * @property list<array{source_document_id: int, page_from: int, page_to: int}> $documents
 * @property int $requested_by
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExamImport extends Model
{
    protected $fillable = ['assignment_id', 'extraction_id', 'documents', 'requested_by', 'applied_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_id' => 'integer',
            'extraction_id' => 'integer',
            'documents' => 'array',
            'requested_by' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<DocumentExtraction, $this> */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(DocumentExtraction::class);
    }

    /** @return list<int> */
    public function documentIds(): array
    {
        return array_values(array_map(fn (array $d) => (int) ($d['source_document_id'] ?? 0), (array) $this->documents));
    }

    /**
     * @return array{id: int, extraction_id: int|null, documents: list<array<string, int>>, applied_at: string|null, created_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'extraction_id' => $this->extraction_id,
            'documents' => array_values((array) $this->documents),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
