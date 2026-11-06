<?php

namespace App\Domain\Gemini;

use App\Models\Question;

/**
 * Output of `rubric_draft` (DESIGN §10.4):
 * {reference_steps?: string[], criteria?: [{description_th, points, is_core}]}.
 * validateFor() is the server-side check the design requires before a draft
 * is stored: criteria points sum to max_points and exactly one is_core.
 */
final readonly class RubricDraft
{
    public const MAX_STEPS = 10;

    public const MAX_CRITERIA = 10;

    /**
     * @param  list<string>  $referenceSteps
     * @param  list<array{description: string, points: float, is_core: bool}>  $criteria
     */
    public function __construct(
        public array $referenceSteps = [],
        public array $criteria = [],
    ) {}

    /**
     * Parses the structured-output JSON. Shape errors are invalid_output.
     *
     * @param  array<string, mixed>  $json
     *
     * @throws GeminiException
     */
    public static function fromArray(array $json): self
    {
        $steps = [];
        foreach ((array) ($json['reference_steps'] ?? []) as $step) {
            if (! is_string($step)) {
                throw GeminiException::invalidOutput('reference_steps must be strings');
            }
            if (trim($step) !== '') {
                $steps[] = trim($step);
            }
        }

        $criteria = [];
        foreach ((array) ($json['criteria'] ?? []) as $criterion) {
            if (! is_array($criterion)
                || ! is_string($criterion['description_th'] ?? null)
                || ! is_numeric($criterion['points'] ?? null)
                || ! is_bool($criterion['is_core'] ?? null)) {
                throw GeminiException::invalidOutput('criteria items need description_th, points and is_core');
            }
            $criteria[] = [
                'description' => trim($criterion['description_th']),
                'points' => round((float) $criterion['points'], 2),
                'is_core' => $criterion['is_core'],
            ];
        }

        return new self($steps, $criteria);
    }

    /**
     * @throws GeminiException
     */
    public function validateFor(string $type, float $maxPoints): void
    {
        if ($type === Question::TYPE_SHOW_WORK && $this->referenceSteps === []) {
            throw GeminiException::invalidOutput('show_work draft has no reference steps');
        }
        if ($type === Question::TYPE_OPEN && $this->criteria === []) {
            throw GeminiException::invalidOutput('open draft has no criteria');
        }
        if (count($this->referenceSteps) > self::MAX_STEPS || count($this->criteria) > self::MAX_CRITERIA) {
            throw GeminiException::invalidOutput('draft is too long');
        }

        if ($this->criteria !== []) {
            foreach ($this->criteria as $criterion) {
                if ($criterion['description'] === '' || $criterion['points'] < 0) {
                    throw GeminiException::invalidOutput('criterion without description or with negative points');
                }
            }
            $core = count(array_filter($this->criteria, fn (array $c) => $c['is_core']));
            if ($core !== 1) {
                throw GeminiException::invalidOutput("draft has {$core} core criteria, expected exactly 1");
            }
            $sum = array_sum(array_column($this->criteria, 'points'));
            if (abs($sum - $maxPoints) > 0.001) {
                throw GeminiException::invalidOutput("criteria points sum to {$sum}, expected {$maxPoints}");
            }
        }
    }
}
