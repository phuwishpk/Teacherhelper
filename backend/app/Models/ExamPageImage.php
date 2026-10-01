<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `exam_page_images`: one page of a teacher's exam document
 * that figures are cropped from (§22.4). A photo (JPEG/PNG/WebP) is decoded
 * by the server itself (uploaded_by NULL; file_path stays NULL until
 * CropExamFiguresJob wrote it); a PDF or HEIC page is rendered by the app
 * and uploaded (POST /exams/{id}/page-images). The file lives at
 * exams/{school}/{assignment}/pages/{id}.jpg and is deleted with the
 * document files (eduvision.documents.retention_days).
 *
 * @property int $id
 * @property int $school_id
 * @property int $assignment_id
 * @property int|null $source_document_id
 * @property int $page_no
 * @property string|null $file_path
 * @property int $width_px
 * @property int $height_px
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExamPageImage extends Model
{
    protected $fillable = [
        'school_id',
        'assignment_id',
        'source_document_id',
        'page_no',
        'file_path',
        'width_px',
        'height_px',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_id' => 'integer',
            'assignment_id' => 'integer',
            'source_document_id' => 'integer',
            'page_no' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<SourceDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class);
    }

    /**
     * @return array{id: int, source_document_id: int|null, page_no: int, width_px: int, height_px: int, available: bool}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'source_document_id' => $this->source_document_id,
            'page_no' => $this->page_no,
            'width_px' => $this->width_px,
            'height_px' => $this->height_px,
            'available' => $this->file_path !== null,
        ];
    }
}
