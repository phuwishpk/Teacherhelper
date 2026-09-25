<?php

namespace App\Domain\Notifications\Fcm;

/**
 * Outcome of one FCM HTTP v1 send to one registration token.
 *
 * invalid_token: FCM says the token is dead or not ours (UNREGISTERED 404,
 * INVALID_ARGUMENT 400 with our fixed, tested payload, SENDER_ID_MISMATCH
 * 403). Firebase's token-management guide says to delete such tokens.
 */
final readonly class FcmResult
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token';

    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?int $httpStatus = null,
        public ?string $error = null,
    ) {}

    public function sent(): bool
    {
        return $this->status === self::SENT;
    }

    public function tokenInvalid(): bool
    {
        return $this->status === self::INVALID_TOKEN;
    }
}
