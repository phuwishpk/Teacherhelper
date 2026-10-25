<?php

namespace App\Domain\Grading;

/**
 * Output of fuzzy system 2 (ReviewPriority). flag: `suspicious` (Gemini
 * reported suspicious_instruction) or `manual` (no fuzzy result); both skip
 * the fuzzy step with p = 1. storedP() is what goes into
 * responses.review_priority; the band is decided on the unrounded p like
 * ml/fuzzy.
 */
final readonly class PriorityResult
{
    public const FLAG_SUSPICIOUS = 'suspicious';

    public const FLAG_MANUAL = 'manual';

    /**
     * @param  array{D: float, L: float, B: float}  $inputs
     */
    public function __construct(
        public float $p,
        public string $band,
        public ?string $flag,
        public array $inputs,
        public ?FuzzyResult $trace = null,
    ) {}

    public function storedP(): float
    {
        return round($this->p, 4);
    }

    /** Only confident, unflagged responses may be approved in bulk (§9.5, §10.7). */
    public function bulkApprovable(): bool
    {
        return $this->flag === null && $this->band === ReviewPriority::BAND_CONFIDENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'system' => 'review_priority',
            'inputs' => array_map(fn (float $v) => round($v, 4), $this->inputs),
            'flag' => $this->flag,
            'p' => $this->storedP(),
            'band' => $this->band,
            'trace' => $this->trace?->toArray(),
        ];
    }
}
