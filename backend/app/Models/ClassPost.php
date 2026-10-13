<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * DESIGN §30.7 `class_posts`: an announcement or a material a teacher wrote
 * for one classroom.
 *
 * @property int $id
 * @property int $classroom_id
 * @property int $author_id
 * @property int|null $course_id
 * @property string $kind
 * @property string|null $title
 * @property string|null $body
 * @property int|null $unit_id
 * @property string|null $topic
 * @property string $audience
 * @property string $state
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 * @property string|null $batch_id
 * @property bool $send_to_google
 */
class ClassPost extends Model
{
    public const KIND_ANNOUNCEMENT = 'announcement';

    public const KIND_MATERIAL = 'material';

    public const KINDS = [self::KIND_ANNOUNCEMENT, self::KIND_MATERIAL];

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_STUDENTS = 'students';

    public const STATE_DRAFT = 'draft';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_PUBLISHED = 'published';

    public const STATES = [self::STATE_DRAFT, self::STATE_SCHEDULED, self::STATE_PUBLISHED];

    protected $fillable = [
        'classroom_id', 'author_id', 'course_id', 'kind', 'title', 'body', 'unit_id', 'topic',
        'audience', 'state', 'scheduled_at', 'published_at', 'batch_id', 'send_to_google',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classroom_id' => 'integer',
            'author_id' => 'integer',
            'course_id' => 'integer',
            'unit_id' => 'integer',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'send_to_google' => 'boolean',
        ];
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ClassPostAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ClassPostAttachment::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasOne<ClassPostGooglePost, $this> */
    public function googlePost(): HasOne
    {
        return $this->hasOne(ClassPostGooglePost::class, 'class_post_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_post_recipients', 'class_post_id', 'student_id');
    }
}
