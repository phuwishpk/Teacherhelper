<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §30.7 `class_post_files`: a file a teacher uploaded for their
 * posts (private disk). Shared by every post that attaches it and deleted
 * once none does.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 */
class ClassPostFile extends Model
{
    protected $fillable = ['owner_id', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'size_bytes' => 'integer'];
    }

    /** @return HasMany<ClassPostAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ClassPostAttachment::class);
    }
}
