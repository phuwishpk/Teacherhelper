<?php

namespace App\Domain\Firestore;

use App\Domain\Notifications\Fcm\ServiceAccount;
use App\Models\User;
use Firebase\JWT\JWT;

/**
 * Firebase custom tokens (DESIGN §38.1): a JWT the app trades for a Firebase
 * session with signInWithCustomToken. RS256 with the service account's key,
 * iss/sub = client_email, aud = the Identity Toolkit audience, uid =
 * FirestoreUid::of(user), claims = {role} only (security rules check the uid,
 * never claims), one hour.
 */
final class FirebaseCustomTokens
{
    public const AUDIENCE = 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit';

    public const LIFETIME = 3600;

    public function __construct(private readonly ServiceAccount $account) {}

    public static function fromConfig(): self
    {
        return new self(FirestoreConfig::account());
    }

    /** @return array{token: string, uid: string, expires_at: string} */
    public function issue(User $user, ?int $now = null): array
    {
        $now ??= time();
        $uid = FirestoreUid::of($user);
        $token = JWT::encode([
            'iss' => $this->account->clientEmail,
            'sub' => $this->account->clientEmail,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'uid' => $uid,
            'claims' => ['role' => $user->role],
        ], $this->account->privateKey(), 'RS256', $this->account->privateKeyId);

        return [
            'token' => $token,
            'uid' => $uid,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + self::LIFETIME),
        ];
    }
}
