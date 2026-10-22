<?php

namespace App\Domain\Firestore;

use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Domain\Notifications\Fcm\ServiceAccount;

/**
 * Whether this server mirrors into Firestore (DESIGN §38.1): FIRESTORE_ENABLED
 * plus a usable FIREBASE_CREDENTIALS key file. Off means NullLiveStore and
 * 503 firestore_not_configured on POST /auth/firebase-token.
 */
final class FirestoreConfig
{
    public static function enabled(): bool
    {
        return (bool) config('services.firebase.firestore_enabled')
            && trim((string) config('services.firebase.credentials')) !== '';
    }

    public static function database(): string
    {
        $db = trim((string) config('services.firebase.firestore_database'));

        return $db === '' ? '(default)' : $db;
    }

    public static function timeout(): int
    {
        return max(1, (int) config('services.firebase.timeout', 10));
    }

    /** @throws FirebaseCredentialsInvalid */
    public static function account(): ServiceAccount
    {
        return ServiceAccount::fromFile(
            trim((string) config('services.firebase.credentials')),
            config('services.firebase.project_id') ?: null,
        );
    }
}
