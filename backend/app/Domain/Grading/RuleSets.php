<?php

namespace App\Domain\Grading;

use InvalidArgumentException;

/**
 * Loads the rule tables in rules/*.php (DESIGN §11.3–§11.5, §11.8; the
 * twins of ml/fuzzy/rulesets.py) and builds one FuzzyEngine per
 * (rule set, strictness). A singleton written as [lenient, normal, strict]
 * depends on the assignment's strictness; a plain number does not.
 */
final class RuleSets
{
    public const SHOW_WORK = 'show_work';

    public const SHORT = 'short';

    public const OPEN = 'open';

    public const REVIEW_PRIORITY = 'review_priority';

    public const STRICTNESS = ['lenient', 'normal', 'strict'];

    /** @var array<string, FuzzyEngine> */
    private static array $engines = [];

    /** @var array<string, array<string, mixed>> */
    private static array $configs = [];

    public static function engine(string $name, string $strictness = 'normal'): FuzzyEngine
    {
        return self::$engines[$name.'.'.$strictness] ??= self::build($name, $strictness);
    }

    /**
     * @return array{name: string, variables: array<string, array<string, array<string, mixed>>>, outputs: list<string>, rules: list<array<string, mixed>>}
     */
    public static function config(string $name): array
    {
        if (! in_array($name, [self::SHOW_WORK, self::SHORT, self::OPEN, self::REVIEW_PRIORITY], true)) {
            throw new InvalidArgumentException("no fuzzy rule set {$name}");
        }

        return self::$configs[$name] ??= require __DIR__.'/rules/'.$name.'.php';
    }

    private static function build(string $name, string $strictness): FuzzyEngine
    {
        $index = array_search($strictness, self::STRICTNESS, true);
        if ($index === false) {
            throw new InvalidArgumentException("unknown strictness {$strictness}");
        }

        $config = self::config($name);
        $rules = [];
        foreach ($config['rules'] as $rule) {
            $then = [];
            foreach ($rule['then'] as $output => $z) {
                $then[$output] = (float) (is_array($z) ? $z[$index] : $z);
            }
            $rules[] = [
                'name' => $rule['name'],
                'when' => $rule['when'],
                'then' => $then,
                'note' => $rule['note'] ?? '',
            ];
        }

        return new FuzzyEngine($config['variables'], $rules, $config['outputs']);
    }
}
