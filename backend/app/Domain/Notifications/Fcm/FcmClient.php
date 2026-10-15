<?php

namespace App\Domain\Notifications\Fcm;

use App\Domain\Notifications\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1 (DESIGN §9.9):
 * POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
 * with a bearer token from AccessTokens, one request per device token (v1
 * has no multicast). A 401 drops the cached access token and retries once.
 */
final class FcmClient
{
    public const BASE_URL = 'https://fcm.googleapis.com/v1';

    /**
     * FcmError codes that always mean "delete this registration token".
     * INVALID_ARGUMENT is not one of them: FCM also returns it for a bad
     * payload (too large, wrong data type), and deleting on that would wipe
     * every recipient's token; see isDeadToken().
     */
    private const DEAD_TOKEN_CODES = ['UNREGISTERED', 'SENDER_ID_MISMATCH'];

    /** The request field a BadRequest violation names when the token itself is malformed. */
    private const TOKEN_FIELD = 'message.token';

    public function __construct(
        private readonly ServiceAccount $account,
        private readonly AccessTokens $tokens,
        private readonly int $timeout = 10,
    ) {}

    public function projectId(): string
    {
        return $this->account->projectId;
    }

    /** Proves the credentials work (a fresh access token); throws FcmAuthFailed otherwise. */
    public function checkCredentials(): void
    {
        $this->tokens->forget();
        $this->tokens->get();
    }

    public function send(string $deviceToken, PushMessage $message): FcmResult
    {
        try {
            $response = $this->post($deviceToken, $message);
            if ($response->status() === 401) {
                $this->tokens->forget();
                $response = $this->post($deviceToken, $message);
            }
        } catch (ConnectionException $e) {
            return new FcmResult(FcmResult::FAILED, null, 'unreachable: '.$e->getMessage());
        } catch (FcmAuthFailed $e) {
            return new FcmResult(FcmResult::FAILED, null, $e->getMessage());
        }

        return self::result($response);
    }

    /**
     * The request body (public for tests and the check command).
     *
     * @return array<string, mixed>
     */
    public static function body(string $deviceToken, PushMessage $message): array
    {
        return ['message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => $message->data(),
            // One tray entry per kind: a newer "3 appeals" replaces "2 appeals".
            'android' => ['notification' => ['tag' => $message->type]],
        ]];
    }

    private function post(string $deviceToken, PushMessage $message): Response
    {
        return Http::withToken($this->tokens->get())
            ->acceptJson()
            ->timeout($this->timeout)
            ->post(self::BASE_URL.'/projects/'.rawurlencode($this->account->projectId).'/messages:send', self::body($deviceToken, $message));
    }

    private static function result(Response $response): FcmResult
    {
        if ($response->successful()) {
            return new FcmResult(FcmResult::SENT, $response->status());
        }

        $status = (string) ($response->json('error.status') ?? '');
        $details = array_values(array_filter((array) $response->json('error.details', []), 'is_array'));
        $codes = [];
        foreach ($details as $detail) {
            if (is_string($detail['errorCode'] ?? null)) {
                $codes[] = $detail['errorCode'];
            }
        }
        $error = trim('HTTP '.$response->status().' '.$status.' '.implode(',', $codes));

        return new FcmResult(self::isDeadToken($codes, $details) ? FcmResult::INVALID_TOKEN : FcmResult::FAILED, $response->status(), $error);
    }

    /**
     * Firebase's token-management guide: UNREGISTERED (404) and
     * SENDER_ID_MISMATCH (403) name the token; INVALID_ARGUMENT (400) does
     * only when a google.rpc.BadRequest detail puts the violation on
     * message.token. A bare 404 NOT_FOUND without an FcmError code is left
     * alone too: that is what a wrong project id in the URL gives, for every
     * device at once.
     *
     * @param  list<string>  $codes
     * @param  list<array<mixed>>  $details
     */
    private static function isDeadToken(array $codes, array $details): bool
    {
        if (array_intersect($codes, self::DEAD_TOKEN_CODES) !== []) {
            return true;
        }
        if (! in_array('INVALID_ARGUMENT', $codes, true)) {
            return false;
        }
        foreach ($details as $detail) {
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                if (is_array($violation) && ($violation['field'] ?? null) === self::TOKEN_FIELD) {
                    return true;
                }
            }
        }

        return false;
    }
}
