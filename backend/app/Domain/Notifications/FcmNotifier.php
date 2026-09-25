<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Fcm\FcmClient;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

/**
 * Sends each push through FCM HTTP v1 to every device the recipients
 * registered (POST /devices). A token FCM reports as dead is deleted, so the
 * next push skips it. Other failures are logged and not retried: a push is
 * best effort and a retry could reach the devices that already got it.
 * Logs carry ids and counts, never tokens.
 */
final class FcmNotifier extends PushNotifier
{
    public function __construct(private readonly FcmClient $client) {}

    protected function push(array $userIds, PushMessage $message): void
    {
        $devices = DeviceToken::query()->whereIn('user_id', $userIds)->orderBy('id')->get();
        $sent = 0;
        $removed = 0;
        $failed = 0;
        foreach ($devices as $device) {
            $result = $this->client->send($device->fcm_token, $message);
            if ($result->sent()) {
                $sent++;
            } elseif ($result->tokenInvalid()) {
                $device->delete();
                $removed++;
            } else {
                $failed++;
                Log::warning('fcm.send_failed', ['device_id' => $device->id, 'user_id' => $device->user_id, 'type' => $message->type, 'error' => $result->error]);
            }
        }

        Log::info('notify.'.$message->type, [
            'channel' => 'fcm',
            'user_ids' => array_values($userIds),
            'data' => $message->data(),
            'devices' => $devices->count(),
            'sent' => $sent,
            'removed_tokens' => $removed,
            'failed' => $failed,
        ]);
    }
}
