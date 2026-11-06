<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * The one generic fuzzy engine of DESIGN §11.1 (PHP twin of
 * ml/fuzzy/engine.py). Rule tables are config arrays (rules/*.php):
 *
 * - antecedents are conjunctions of "variable is set", AND = min;
 * - consequents are singletons, one constant per output;
 * - defuzzification is the weighted average y = Σ(wᵢ·zᵢ) / Σwᵢ;
 * - every evaluation returns the full trace (FuzzyResult).
 *
 * Inputs are ratios in [0, 1]; float noise is clamped, anything else throws.
 */
final class FuzzyEngine
{
    private const EPS = 1e-9;

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $variables  variable => set label => membership spec
     * @param  list<array{name: string, when: array<string, string>, then: array<string, float>, note?: string}>  $rules
     * @param  list<string>  $outputs
     */
    public function __construct(
        private readonly array $variables,
        private readonly array $rules,
        private readonly array $outputs,
    ) {
        $this->validate();
    }

    /**
     * @param  array<string, float|int>  $inputs  variable => value in [0, 1]
     */
    public function evaluate(array $inputs): FuzzyResult
    {
        $x = [];
        $memberships = [];
        foreach ($this->variables as $var => $sets) {
            if (! array_key_exists($var, $inputs)) {
                throw new InvalidArgumentException("missing input {$var}");
            }
            $x[$var] = self::unit($var, $inputs[$var]);
            foreach ($sets as $label => $spec) {
                $memberships[$var][$label] = Membership::evaluate($spec, $x[$var]);
            }
        }

        $acc = array_fill_keys($this->outputs, 0.0);
        $weightSum = 0.0;
        $traces = [];
        foreach ($this->rules as $rule) {
            $clauses = [];
            foreach ($rule['when'] as $var => $label) {
                $clauses[] = $memberships[$var][$label];
            }
            $weight = min($clauses);
            $traces[] = [
                'name' => $rule['name'],
                'weight' => $weight,
                'then' => $rule['then'],
                'note' => $rule['note'] ?? '',
            ];
            if ($weight > 0.0) {
                $weightSum += $weight;
                foreach ($this->outputs as $name) {
                    $acc[$name] += $weight * $rule['then'][$name];
                }
            }
        }

        $outputs = [];
        if ($weightSum > 0.0) {
            foreach ($this->outputs as $name) {
                $outputs[$name] = $acc[$name] / $weightSum;
            }
        }

        return new FuzzyResult($x, $memberships, $traces, $weightSum, $outputs);
    }

    private static function unit(string $name, float|int $value): float
    {
        $v = (float) $value;
        if (is_nan($v)) {
            throw new InvalidArgumentException("input {$name} is NaN");
        }
        if ($v < -self::EPS || $v > 1.0 + self::EPS) {
            throw new InvalidArgumentException("input {$name} must be within [0, 1], got {$v}");
        }

        return min(1.0, max(0.0, $v));
    }

    private function validate(): void
    {
        if ($this->variables === [] || $this->rules === [] || $this->outputs === []) {
            throw new InvalidArgumentException('a fuzzy engine needs variables, rules and outputs');
        }
        foreach ($this->variables as $sets) {
            foreach ($sets as $spec) {
                Membership::validate($spec);
            }
        }

        $seen = [];
        foreach ($this->rules as $rule) {
            $name = $rule['name'];
            if (isset($seen[$name])) {
                throw new InvalidArgumentException("duplicate rule name {$name}");
            }
            $seen[$name] = true;
            if ($rule['when'] === []) {
                throw new InvalidArgumentException("rule {$name}: empty antecedent");
            }
            foreach ($rule['when'] as $var => $label) {
                if (! isset($this->variables[$var][$label])) {
                    throw new InvalidArgumentException("rule {$name}: unknown set {$var} is {$label}");
                }
            }
            $keys = array_keys($rule['then']);
            sort($keys);
            $expected = $this->outputs;
            sort($expected);
            if ($keys !== $expected) {
                throw new InvalidArgumentException("rule {$name}: consequent must define exactly ".implode(', ', $this->outputs));
            }
        }
    }
}
