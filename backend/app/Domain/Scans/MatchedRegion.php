<?php

namespace App\Domain\Scans;

use App\Models\Question;

/**
 * A meta region paired with its layout region (DESIGN §5.3) and the question
 * it answers.
 */
final readonly class MatchedRegion
{
    /**
     * @param  array<string, mixed>  $layout  the region object from layouts.pages
     */
    public function __construct(
        public ScanRegion $region,
        public array $layout,
        public Question $question,
    ) {}

    public function kind(): string
    {
        return (string) ($this->layout['kind'] ?? '');
    }

    public function hasFinalAnswer(): bool
    {
        return isset($this->layout['final_answer']);
    }

    /** @return list<string> */
    public function bubbleOptions(): array
    {
        return array_values(array_map(
            fn (array $b) => (string) $b['option'],
            is_array($this->layout['bubbles'] ?? null) ? $this->layout['bubbles'] : [],
        ));
    }
}
