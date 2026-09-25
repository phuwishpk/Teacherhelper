<?php

namespace App\Domain\Grading;

/**
 * Understanding level from the fuzzy output u (DESIGN §11.7):
 * u >= 0.75 good, 0.4 <= u < 0.75 partial, u < 0.4 not_yet.
 */
final class Understanding
{
    public const GOOD = 'good';

    public const PARTIAL = 'partial';

    public const NOT_YET = 'not_yet';

    public const GOOD_FROM = 0.75;

    public const PARTIAL_FROM = 0.4;

    public static function fromU(float $u): string
    {
        return match (true) {
            $u >= self::GOOD_FROM => self::GOOD,
            $u >= self::PARTIAL_FROM => self::PARTIAL,
            default => self::NOT_YET,
        };
    }
}
