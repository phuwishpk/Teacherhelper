<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §30.12 `assignment_attachments`: a file or a link of an assignment.
 *
 * @property int $id
 * @property int $assignment_id
 * @property string $kind
 * @property int|null $class_post_file_id
 * @property string|null $url
 * @property string $title
 * @property int $position
 */
class AssignmentAttachment extends Model
{
    protected $fillable = ['assignment_id', 'kind', 'class_post_file_id', 'url', 'title', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['assignment_id' => 'integer', 'class_post_file_id' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<ClassPostFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ClassPostFile::class, 'class_post_file_id');
    }
}
