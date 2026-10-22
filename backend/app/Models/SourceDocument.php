<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §19.8 `source_documents`: a file a teacher uploaded (POST
 * /documents): an answer key or a question sheet as a photo (JPEG, PNG,
 * WebP, HEIC/HEIF) or a PDF, stored as it came at
 * documents/{school}/{sha256}.{ext} and deleted after
 * eduvision.documents.retention_days (the read result stays in
 * document_extractions). page_count is 1 for a photo.
 *
 * @property int $id
 * @property int $school_id
 * @property int $uploaded_by
 * @property string $sha256
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $page_count
 * @property string|null $file_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SourceDocument extends Model
{
    protected $fillable = [
        'school_id',
        'uploaded_by',
        'sha256',
        'original_name',
        'mime_type',
        'size_bytes',
        'page_count',
        'file_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
