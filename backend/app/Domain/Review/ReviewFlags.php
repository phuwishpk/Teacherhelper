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
 *   student than the one who handed it in (fuzzy_trace.identity_mismatch).
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
