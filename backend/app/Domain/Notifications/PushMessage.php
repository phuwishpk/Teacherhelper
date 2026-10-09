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
 *   classroom_work_imported teacher assignment_id (courseWork from the Classroom website, §19.3)
 *   google_reconnect   teacher     - (the Google grant stopped working, §19.3)
 *   grades_published   student     course_id, classroom_id (the classroom's grades were published, §23.7, §24.28)
 *   course_request     teacher     request_id, classroom_id (a course waits to be bound to their homeroom, §24.7)
 *   course_request_decided teacher request_id, classroom_id, course_id (approved or declined, §24.7)
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

    public const CLASSROOM_WORK_IMPORTED = 'classroom_work_imported';

    public const GOOGLE_RECONNECT = 'google_reconnect';

    public const GRADES_PUBLISHED = 'grades_published';

    public const COURSE_REQUEST = 'course_request';

    public const COURSE_REQUEST_DECIDED = 'course_request_decided';

    public const TITLE = 'Krucheck';

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
