<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §30.11 `class_post_google_posts`: the Google Classroom copy of a
 * class post (an announcement or a courseWorkMaterial) and its send state.
 *
 * @property int $id
 * @property int $class_post_id
 * @property string $course_id
 * @property string $kind
 * @property string|null $google_id
 * @property string|null $alternate_link
 * @property string $state
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 */
class ClassPostGooglePost extends Model
{
    public const STATE_QUEUED = 'queued';

    public const STATE_SENT = 'sent';

    public const STATE_FAILED = 'failed';

    protected $fillable = ['class_post_id', 'course_id', 'kind', 'google_id', 'alternate_link', 'state', 'last_error', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['class_post_id' => 'integer', 'sent_at' => 'datetime'];
    }

    /** The copy exists in Classroom (created once; later sends patch it). */
    public function isInClassroom(): bool
    {
        return $this->google_id !== null && $this->google_id !== '';
    }

    /** @return BelongsTo<ClassPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class, 'class_post_id');
    }
}
