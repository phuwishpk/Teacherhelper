<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\LiveStore;
use App\Domain\Notifications\Fcm\ServiceAccount;

/**
 * A throwaway RSA service account on disk, pointed at by FIREBASE_CREDENTIALS,
 * with FIRESTORE_ENABLED switched as each test needs (DESIGN §38).
 */
trait FirestoreFixtures
{
    protected const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    protected const COMMIT_URL = 'https://firestore.googleapis.com/v1/projects/eduvision-test/databases/(default)/documents:commit';

    protected string $publicKey;

    protected string $credentialsPath;

    /** @var array<string, mixed> */
    protected array $serviceAccount;

    protected function makeServiceAccountFile(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->serviceAccount = [
            'type' => 'service_account',
            'project_id' => 'eduvision-test',
            'private_key_id' => 'kid-fs',
            'private_key' => $privatePem,
            'client_email' => 'live@eduvision-test.iam.gserviceaccount.com',
            'token_uri' => self::TOKEN_URI,
        ];
        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fs').'.json';
        file_put_contents($this->credentialsPath, json_encode($this->serviceAccount));
    }

    protected function removeServiceAccountFile(): void
    {
        @unlink($this->credentialsPath);
    }

    protected function enableFirestore(bool $enabled = true, ?string $credentials = null): void
    {
        config([
            'services.firebase.credentials' => $credentials ?? $this->credentialsPath,
            'services.firebase.firestore_enabled' => $enabled,
            'services.firebase.firestore_database' => '(default)',
        ]);
        $this->app->forgetInstance(LiveStore::class);
    }

    protected function account(): ServiceAccount
    {
        return ServiceAccount::fromArray($this->serviceAccount);
    }
}
