<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §30.7 `class_post_attachments`: a file or a link of a post.
 *
 * @property int $id
 * @property int $class_post_id
 * @property string $kind
 * @property int|null $class_post_file_id
 * @property string|null $url
 * @property string $title
 * @property int $position
 */
class ClassPostAttachment extends Model
{
    public const KIND_FILE = 'file';

    public const KIND_LINK = 'link';

    protected $fillable = ['class_post_id', 'kind', 'class_post_file_id', 'url', 'title', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['class_post_id' => 'integer', 'class_post_file_id' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<ClassPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class, 'class_post_id');
    }

    /** @return BelongsTo<ClassPostFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ClassPostFile::class, 'class_post_file_id');
    }
}
