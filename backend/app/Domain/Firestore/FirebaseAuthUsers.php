<?php

namespace App\Domain\Firestore;

use App\Domain\Notifications\Fcm\AccessTokens;
use App\Domain\Notifications\Fcm\FcmAuthFailed;
use App\Domain\Notifications\Fcm\ServiceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Authentication users of the live layer (DESIGN §38.8): the app
 * signs in with a custom token, which creates user `u{id}` in Firebase Auth
 * on first use. Deleting that user ends every Firebase session of it (the
 * refresh tokens die; an ID token already issued lives out its hour), so a
 * deleted Krucheck account cannot keep reading Firestore from a device.
 *
 * Identity Toolkit REST with the service account, scope identitytoolkit.
 */
final class FirebaseAuthUsers
{
    public const BASE_URL = 'https://identitytoolkit.googleapis.com/v1';

    public const SCOPE = 'https://www.googleapis.com/auth/identitytoolkit';

    public function __construct(
        private readonly ServiceAccount $account,
        private readonly AccessTokens $tokens,
        private readonly int $timeout = 10,
    ) {}

    public static function fromConfig(): self
    {
        $account = FirestoreConfig::account();
        $timeout = FirestoreConfig::timeout();

        return new self($account, new AccessTokens($account, $timeout, self::SCOPE), $timeout);
    }

    /**
     * Deletes the Firebase user; a user that never signed in (USER_NOT_FOUND)
     * counts as deleted. Returns false in that case.
     *
     * @throws FirestoreUnavailable
     */
    public function delete(string $uid): bool
    {
        $url = self::BASE_URL.'/projects/'.$this->account->projectId.'/accounts:delete';
        try {
            $response = Http::withToken($this->tokens->get())->acceptJson()->timeout($this->timeout)->post($url, ['localId' => $uid]);
            if ($response->status() === 401) {
                $this->tokens->forget();
                $response = Http::withToken($this->tokens->get())->acceptJson()->timeout($this->timeout)->post($url, ['localId' => $uid]);
            }
        } catch (ConnectionException $e) {
            throw new FirestoreUnavailable('identitytoolkit unreachable: '.$e->getMessage());
        } catch (FcmAuthFailed $e) {
            throw new FirestoreUnavailable('auth: '.$e->getMessage());
        }

        if ($response->successful()) {
            return true;
        }
        $message = (string) $response->json('error.message');
        if (str_starts_with($message, 'USER_NOT_FOUND')) {
            return false;
        }

        throw new FirestoreUnavailable('accounts:delete failed: HTTP '.$response->status().($message !== '' ? ' '.mb_substr($message, 0, 200) : ''));
    }
}
