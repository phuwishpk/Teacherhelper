<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * Linear membership functions of DESIGN §11.1, clamped to [0, 1]:
 *
 *   ramp_up(x; a, b)   = clamp((x − a) / (b − a))     0 when x <= a, 1 when x >= b
 *   ramp_down(x; a, b) = clamp((b − x) / (b − a))     1 when x <= a, 0 when x >= b
 *   tri(x; a, m, b)    = ramp_up(x; a, m) when x <= m, ramp_down(x; m, b) when x > m
 *   low(x) = 1 − x, high(x) = x                      two-valued variables (F, K, D, L, B)
 *
 * Specs are the config arrays of rules/*.php, the same shape as
 * ml/fuzzy/membership.py::build, and every formula keeps the Python
 * operation order so both give bit-identical results (fuzzy_golden.json).
 */
final class Membership
{
    public const TYPES = ['ramp_up', 'ramp_down', 'tri', 'low', 'high'];

    public static function clamp(float $v): float
    {
        return min(1.0, max(0.0, $v));
    }

    public static function rampUp(float $x, float $a, float $b): float
    {
        return self::clamp(($x - $a) / ($b - $a));
    }

    public static function rampDown(float $x, float $a, float $b): float
    {
        return self::clamp(($b - $x) / ($b - $a));
    }

    public static function tri(float $x, float $a, float $m, float $b): float
    {
        return $x <= $m ? self::rampUp($x, $a, $m) : self::rampDown($x, $m, $b);
    }

    public static function low(float $x): float
    {
        return self::clamp(1.0 - $x);
    }

    public static function high(float $x): float
    {
        return self::clamp($x);
    }

    /**
     * @param  array{type: string, a?: float, m?: float, b?: float}  $spec
     */
    public static function evaluate(array $spec, float $x): float
    {
        return match ($spec['type']) {
            'ramp_up' => self::rampUp($x, (float) $spec['a'], (float) $spec['b']),
            'ramp_down' => self::rampDown($x, (float) $spec['a'], (float) $spec['b']),
            'tri' => self::tri($x, (float) $spec['a'], (float) $spec['m'], (float) $spec['b']),
            'low' => self::low($x),
            'high' => self::high($x),
        };
    }

    /**
     * Rejects a spec the engine could not evaluate (unknown type, a >= b).
     *
     * @param  array<string, mixed>  $spec
     */
    public static function validate(array $spec): void
    {
        $type = $spec['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('unknown membership type '.var_export($type, true));
        }
        if ($type === 'ramp_up' || $type === 'ramp_down') {
            if (! isset($spec['a'], $spec['b']) || ! ((float) $spec['a'] < (float) $spec['b'])) {
                throw new InvalidArgumentException("{$type} needs a < b");
            }
        }
        if ($type === 'tri') {
            if (! isset($spec['a'], $spec['m'], $spec['b'])
                || ! ((float) $spec['a'] < (float) $spec['m'] && (float) $spec['m'] < (float) $spec['b'])) {
                throw new InvalidArgumentException('tri needs a < m < b');
            }
        }
    }
}
