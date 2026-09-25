<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Log;

/**
 * Notifier used without FIREBASE_CREDENTIALS (local machines, tests, a
 * server before the Firebase project exists): writes each push to the log.
 * Carries ids and the notification text (which never holds a score or a
 * student's name), never device tokens.
 */
final class LogNotifier extends PushNotifier
{
    protected function push(array $userIds, PushMessage $message): void
    {
        Log::info('notify.'.$message->type, [
            'user_ids' => array_values($userIds),
            'data' => $message->data(),
            'text' => $message->body,
        ]);
    }
}
