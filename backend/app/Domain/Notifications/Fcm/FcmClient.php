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

    /** FcmError codes that mean "delete this registration token". */
    private const DEAD_TOKEN_CODES = ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'];

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
        $codes = [];
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                $codes[] = $detail['errorCode'];
            }
        }
        $error = trim('HTTP '.$response->status().' '.$status.' '.implode(',', $codes));

        $dead = array_intersect($codes, self::DEAD_TOKEN_CODES) !== []
            || ($response->status() === 404 && $status === 'NOT_FOUND');

        return new FcmResult($dead ? FcmResult::INVALID_TOKEN : FcmResult::FAILED, $response->status(), $error);
    }
}
