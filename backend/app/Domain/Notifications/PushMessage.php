<?php

namespace App\Domain\Notifications;

/**
 * One push notification (DESIGN §9.9) as every Notifier sends it: a Thai
 * title and body for the system tray, plus a `data` map the app routes on
 * (app/lib/core/push/push_routes.dart):
 *
 *   type               recipients  data
 *   grading_done       teacher     assignment_id
 *   appeal_opened      teacher     -
 *   results_published  student     submission_id, assignment_id
 *   appeal_resolved    student     submission_id, appeal_id
 *   retake_requested   student     assignment_id (Google Classroom, §18.2)
 *
 * The body never carries a score (§9.9: nothing on the lock screen) and the
 * data carries ids only.
 */
final readonly class PushMessage
{
    public const GRADING_DONE = 'grading_done';

    public const APPEAL_OPENED = 'appeal_opened';

    public const RESULTS_PUBLISHED = 'results_published';

    public const APPEAL_RESOLVED = 'appeal_resolved';

    public const RETAKE_REQUESTED = 'retake_requested';

    public const TITLE = 'EduVision';

    /**
     * @param  array<string, int|string>  $data  ids only; sent as strings
     */
    public function __construct(
        public string $type,
        public string $body,
        public array $data = [],
        public string $title = self::TITLE,
    ) {}

    /**
     * FCM data values must be strings; `type` always comes first.
     *
     * @return array<string, string>
     */
    public function data(): array
    {
        return ['type' => $this->type] + array_map(fn ($v) => (string) $v, $this->data);
    }
}
