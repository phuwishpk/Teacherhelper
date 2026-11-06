<?php

namespace App\Domain\Google;

use App\Models\GoogleEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes `google_events` (DESIGN §32). A routine successful sign-in is not
 * recorded (user_google_identities.last_login_at has it); everything else
 * is. Recording never breaks the request it describes.
 */
final class GoogleEvents
{
    public static function record(string $flow, string $event, string $result, ?int $userId = null, ?string $detail = null, ?string $email = null): void
    {
        if ($flow === GoogleEvent::FLOW_SIGNIN && (($event === 'login' && $result === 'ok') || ($event === 'web_callback' && in_array($result, ['login_ticket', 'link_ticket'], true)))) {
            return; // routine success, or a step of one that is recorded where it ends
        }
        try {
            GoogleEvent::query()->create([
                'user_id' => $userId,
                'flow' => $flow,
                'event' => mb_substr($event, 0, 40),
                'result' => mb_substr($result, 0, 60),
                'detail' => $detail === null ? null : mb_substr($detail, 0, 60),
                'email' => $email === null || $email === '' ? null : mb_strtolower(mb_substr($email, 0, 255)),
            ]);
        } catch (Throwable $e) {
            Log::warning('google_events.write_failed', ['error' => $e::class]);
        }
    }

    /** Deletes the rows past GoogleEvent::RETENTION_DAYS; returns how many. */
    public static function purge(): int
    {
        return GoogleEvent::query()->where('created_at', '<', now()->subDays(GoogleEvent::RETENTION_DAYS))->delete();
    }
}
