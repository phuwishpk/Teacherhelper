<?php

namespace App\Console\Commands;

use App\Domain\Firestore\FirebaseCustomTokens;
use App\Domain\Firestore\FirestoreClient;
use App\Domain\Firestore\FirestoreUnavailable;
use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Checks the Firestore setup from the server (Plesk: Scheduled Task "Run
 * now"; locally: php artisan eduvision:firestore-check): the key file, an
 * access token with the datastore scope, one write + read of
 * `_health/server`, and a custom token for the first teacher. Prints the
 * project and database, never a key or a token (DESIGN §38.4).
 */
class FirestoreCheckCommand extends Command
{
    protected $signature = 'eduvision:firestore-check';

    protected $description = 'Check the Firestore live layer: credentials, a write, a read and a custom token (DESIGN §38)';

    public function handle(): int
    {
        if (! (bool) config('services.firebase.firestore_enabled')) {
            $this->warn('FIRESTORE_ENABLED is false: nothing is mirrored and the app gets 503 firestore_not_configured.');

            return self::FAILURE;
        }
        if (trim((string) config('services.firebase.credentials')) === '') {
            $this->warn('FIREBASE_CREDENTIALS is empty: Firestore needs the same service-account key as FCM.');

            return self::FAILURE;
        }

        try {
            $client = FirestoreClient::fromConfig();
        } catch (FirebaseCredentialsInvalid $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line("project: {$client->projectId()}");
        $this->line("database: {$client->database()}");

        $now = now();
        try {
            $client->set('_health/server', ['checked_at' => $now, 'app' => (string) config('app.name')]);
            $this->info('write ok (_health/server)');
            $doc = $client->get('_health/server');
        } catch (FirestoreUnavailable $e) {
            $this->error($e->getMessage());
            $this->line('Is Firestore created in this Firebase project (console > Build > Firestore Database)?');

            return self::FAILURE;
        }
        if (! is_array($doc) || ! isset($doc['checked_at'])) {
            $this->error('read back failed: _health/server has no checked_at');

            return self::FAILURE;
        }
        $this->info('read ok');

        $user = User::query()->where('role', User::ROLE_TEACHER)->orderBy('id')->first();
        if ($user === null) {
            $this->warn('no teacher yet: custom token not tried');

            return self::SUCCESS;
        }
        $issued = FirebaseCustomTokens::fromConfig()->issue($user);
        $this->info("custom token ok (uid {$issued['uid']}, expires {$issued['expires_at']})");

        return self::SUCCESS;
    }
}
