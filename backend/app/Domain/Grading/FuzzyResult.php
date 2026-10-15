<?php

namespace App\Domain\Grading;

/**
 * One FuzzyEngine evaluation: the outputs and the complete trace behind them
 * (DESIGN §11.1) so the review screen can show "why this score".
 * degenerate = no rule fired (Σw = 0): there are no outputs and the caller
 * sets grading_state = manual.
 */
final readonly class FuzzyResult
{
    /**
     * @param  array<string, float>  $inputs
     * @param  array<string, array<string, float>>  $memberships
     * @param  list<array{name: string, weight: float, then: array<string, float>, note: string}>  $rules
     * @param  array<string, float>  $outputs  empty when degenerate
     */
    public function __construct(
        public array $inputs,
        public array $memberships,
        public array $rules,
        public float $weightSum,
        public array $outputs,
    ) {}

    public function isDegenerate(): bool
    {
        return $this->weightSum <= 0.0;
    }

    public function output(string $name): ?float
    {
        return $this->outputs[$name] ?? null;
    }

    /** @return array<string, float> rule name => weight, in rule order */
    public function weights(): array
    {
        return array_column($this->rules, 'weight', 'name');
    }

    /** @return list<string> names of the rules with a non-zero weight, strongest first */
    public function fired(): array
    {
        $fired = array_values(array_filter($this->rules, fn (array $r) => $r['weight'] > 0.0));
        usort($fired, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return array_column($fired, 'name');
    }

    /**
     * The shape of ml/fuzzy FuzzyResult.to_dict(), stored in responses.fuzzy_trace.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inputs' => $this->inputs,
            'memberships' => $this->memberships,
            'rules' => $this->rules,
            'weight_sum' => $this->weightSum,
            'outputs' => (object) $this->outputs,
            'degenerate' => $this->isDegenerate(),
        ];
    }
}
