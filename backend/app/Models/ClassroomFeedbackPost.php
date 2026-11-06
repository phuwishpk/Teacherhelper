<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN §19.7, §19.8 `classroom_feedback_posts`: the private announcement
 * (courses.announcements.create, INDIVIDUAL_STUDENTS) that tells one student
 * the result of one publish. PostClassroomFeedbackJob sends it; queue
 * retries end as `failed` + last_error (Thai), and the teacher can send the
 * failed ones again (POST /assignments/{id}/google-feedback/retry).
 *
 * A submission published again gets a new row; lists, retries and the
 * attention count use the latest row of each submission only.
 *
 * @property int $id
 * @property int $submission_id
 * @property Carbon $published_at the publish this row reports (submissions.published_at)
 * @property string $course_id
 * @property string $google_user_id the student's matched Classroom account when queued
 * @property string|null $announcement_id
 * @property string $state queued|posted|failed
 * @property string|null $last_error
 * @property Carbon|null $posted_at
 */
class ClassroomFeedbackPost extends Model
{
    public const STATE_QUEUED = 'queued';

    public const STATE_POSTED = 'posted';

    public const STATE_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'published_at',
        'course_id',
        'google_user_id',
        'announcement_id',
        'state',
        'last_error',
        'posted_at',
    ];

    protected $attributes = [
        'state' => self::STATE_QUEUED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * The latest row of each submission of these assignments.
     *
     * @param  Builder<Assignment>|\Illuminate\Database\Query\Builder|list<int>  $assignmentIds
     * @return Builder<self>
     */
    public static function latestOf(mixed $assignmentIds): Builder
    {
        return self::query()->whereIn('id', DB::table('classroom_feedback_posts as p')
            ->join('submissions as s', 's.id', '=', 'p.submission_id')
            ->whereIn('s.assignment_id', $assignmentIds)
            ->groupBy('p.submission_id')
            ->selectRaw('MAX(p.id)'));
    }
}
