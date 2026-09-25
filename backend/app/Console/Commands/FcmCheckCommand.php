<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Fcm\AccessTokens;
use App\Domain\Notifications\Fcm\FcmAuthFailed;
use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Domain\Notifications\Fcm\ServiceAccount;
use App\Domain\Notifications\PushMessage;
use App\Models\DeviceToken;
use Illuminate\Console\Command;

/**
 * Checks the FCM setup from the server (Plesk: Scheduled Task "Run now";
 * locally: php artisan eduvision:fcm-check). Reads FIREBASE_CREDENTIALS,
 * asks Google for an access token and, with --user, sends one test push to
 * that user's registered devices. Never prints the key or device tokens.
 */
class FcmCheckCommand extends Command
{
    protected $signature = 'eduvision:fcm-check
        {--user= : also send a test push to this user id\'s devices}';

    protected $description = 'Check the Firebase service account and FCM HTTP v1 (DESIGN §9.9)';

    public function handle(): int
    {
        $path = trim((string) config('services.firebase.credentials'));
        if ($path === '') {
            $this->warn('FIREBASE_CREDENTIALS is empty: pushes are only written to the log (LogNotifier).');

            return self::FAILURE;
        }

        try {
            $account = ServiceAccount::fromFile($path, config('services.firebase.project_id') ?: null);
        } catch (FirebaseCredentialsInvalid $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("project: {$account->projectId}");
        $this->line("account: {$account->clientEmail}");

        $timeout = (int) config('services.firebase.timeout', 10);
        $client = new FcmClient($account, new AccessTokens($account, $timeout), $timeout);
        try {
            $client->checkCredentials();
        } catch (FcmAuthFailed $e) {
            $this->error("access token: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info('access token ok');

        $userId = $this->option('user');
        if ($userId === null) {
            return self::SUCCESS;
        }

        $devices = DeviceToken::query()->where('user_id', (int) $userId)->get();
        if ($devices->isEmpty()) {
            $this->warn("user {$userId} has no registered device (sign in on the app first)");

            return self::FAILURE;
        }
        $message = new PushMessage('check', 'ทดสอบการแจ้งเตือนจาก EduVision');
        $failed = 0;
        foreach ($devices as $device) {
            $result = $client->send($device->fcm_token, $message);
            if ($result->tokenInvalid()) {
                $device->delete();
            }
            $failed += $result->sent() ? 0 : 1;
            $this->line("device {$device->id}: {$result->status}".($result->error !== null ? " ({$result->error})" : ''));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
