<?php

namespace App\Domain\Gemini;

/**
 * A Gemini Batch API job as the transport saw it (DESIGN §20.8): its name
 * (batches/…), its state, and once it succeeded one reply per request,
 * keyed by the request's metadata key.
 *
 * state is Gemini's BATCH_STATE_* / JOB_STATE_* without the prefix, in
 * lower case: pending, running, succeeded, failed, cancelled, expired, or
 * unknown for anything else.
 */
final readonly class GeminiBatch
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, GeminiReply>  $replies
     */
    public function __construct(
        public string $name,
        public string $state,
        public array $replies = [],
        public ?string $error = null,
    ) {}

    /** BATCH_STATE_SUCCEEDED / JOB_STATE_SUCCEEDED / SUCCEEDED -> succeeded. */
    public static function normaliseState(mixed $state): string
    {
        if (! is_string($state)) {
            return self::UNKNOWN;
        }
        $state = strtolower((string) preg_replace('/^(BATCH_STATE_|JOB_STATE_)/i', '', trim($state)));

        return in_array($state, [self::PENDING, self::RUNNING, self::SUCCEEDED, self::FAILED, self::CANCELLED, self::EXPIRED], true)
            ? $state
            : self::UNKNOWN;
    }

    public function isDone(): bool
    {
        return in_array($this->state, [self::SUCCEEDED, self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }
}
