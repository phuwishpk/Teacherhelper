<?php

namespace App\Domain\Review;

use App\Domain\Grading\PriorityResult;
use App\Models\Response;

/**
 * Flags that change how a response is reviewed (DESIGN §10.7, §11.8, §13,
 * §18.3). None is a column of §8.4; they are read from where the pipeline
 * stores them:
 *
 * - suspicious: Gemini reported suspicious_instruction (a possible prompt
 *   injection in the handwriting). fuzzy_trace.suspicious_instruction, the
 *   review-priority flag, or extraction.suspicious_instruction.
 * - identity_mismatch: a Google Classroom scan whose QR names another
 *   student than the one who handed it in (fuzzy_trace.identity_mismatch,
 *   DESIGN §18.3). It is set when the scan is ingested (ResponseWriter's
 *   $identityMismatch) and is a STICKY trace key: grading rewrites
 *   fuzzy_trace in full (scores, failures, manual reasons, requeues), so
 *   every such write goes through carry() to keep it. Only a rescan of the
 *   page starts from a clean trace, and it is flagged again if the new
 *   image mismatches too.
 * - appeal_open: the student's appeal on this answer waits for the teacher.
 *
 * Suspicious and identity-mismatch rows are "flagged": they sit right after
 * the manual rows in the queue and are never approved in bulk.
 */
final class ReviewFlags
{
    public const SUSPICIOUS = 'suspicious';

    public const IDENTITY_MISMATCH = 'identity_mismatch';

    public const APPEAL_OPEN = 'appeal_open';

    /** fuzzy_trace keys that describe the scanned image, not one grading attempt. */
    public const STICKY_TRACE_KEYS = [self::IDENTITY_MISMATCH];

    /**
     * The trace to store: $next plus the sticky keys of $previous.
     *
     * @param  array<string, mixed>|null  $previous  the stored fuzzy_trace
     * @param  array<string, mixed>|null  $next  what the pipeline writes now
     * @return array<string, mixed>|null
     */
    public static function carry(?array $previous, ?array $next): ?array
    {
        $kept = array_intersect_key($previous ?? [], array_flip(self::STICKY_TRACE_KEYS));

        return $kept === [] ? $next : array_merge($next ?? [], $kept);
    }

    /** Marks the answer as handed in by another student than its QR names (§18.3); the caller saves. */
    public static function markIdentityMismatch(Response $response): void
    {
        $trace = $response->fuzzy_trace ?? [];
        $trace[self::IDENTITY_MISMATCH] = true;
        $response->fuzzy_trace = $trace;
    }

    public static function suspicious(Response $response): bool
    {
        $trace = $response->fuzzy_trace ?? [];

        return ($trace['suspicious_instruction'] ?? false) === true
            || (($trace['priority']['flag'] ?? null) === PriorityResult::FLAG_SUSPICIOUS)
            || (($response->extraction['suspicious_instruction'] ?? false) === true);
    }

    public static function identityMismatch(Response $response): bool
    {
        return ($response->fuzzy_trace['identity_mismatch'] ?? false) === true;
    }

    public static function flagged(Response $response): bool
    {
        return self::suspicious($response) || self::identityMismatch($response);
    }

    /**
     * @return list<string>
     */
    public static function of(Response $response, bool $appealOpen): array
    {
        return array_values(array_filter([
            self::suspicious($response) ? self::SUSPICIOUS : null,
            self::identityMismatch($response) ? self::IDENTITY_MISMATCH : null,
            $appealOpen ? self::APPEAL_OPEN : null,
        ]));
    }
}
