<?php

namespace App\Domain\Notifications;

use App\Models\Assignment;

/**
 * Push notifications (DESIGN §9.9). LogNotifier writes them to the log until
 * the FCM notifier of Phase 4 replaces the binding.
 */
interface Notifier
{
    /** Every answer of the assignment has been graded: "ตรวจ {title} เสร็จแล้ว มี {n} ข้อรอตรวจทาน". */
    public function gradingFinished(Assignment $assignment, int $awaitingReview): void;
}
