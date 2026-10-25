<?php

/*
 * Fuzzy system 1 for show_work (DESIGN §11.3; twin of ml/fuzzy/rulesets.py
 * SHOW_WORK). F = final-answer correctness, S = share of valid steps.
 * score_ratio singletons are [lenient, normal, strict]; u does not depend
 * on strictness.
 */

$fewMediumMany = [
    'few' => ['type' => 'ramp_down', 'a' => 0.0, 'b' => 0.5],
    'medium' => ['type' => 'tri', 'a' => 0.2, 'm' => 0.5, 'b' => 0.8],
    'many' => ['type' => 'ramp_up', 'a' => 0.5, 'b' => 1.0],
];

return [
    'name' => 'show_work',
    'variables' => [
        'F' => ['wrong' => ['type' => 'low'], 'correct' => ['type' => 'high']],
        'S' => $fewMediumMany,
    ],
    'outputs' => ['score_ratio', 'u'],
    'rules' => [
        ['name' => 'R1', 'when' => ['F' => 'correct', 'S' => 'many'], 'then' => ['score_ratio' => [1.00, 1.00, 1.00], 'u' => 1.0], 'note' => 'understands well'],
        ['name' => 'R2', 'when' => ['F' => 'correct', 'S' => 'medium'], 'then' => ['score_ratio' => [0.85, 0.80, 0.70], 'u' => 0.7]],
        ['name' => 'R3', 'when' => ['F' => 'correct', 'S' => 'few'], 'then' => ['score_ratio' => [0.60, 0.50, 0.30], 'u' => 0.4], 'note' => 'answer right but method wrong: guessed or copied'],
        ['name' => 'R4', 'when' => ['F' => 'wrong', 'S' => 'many'], 'then' => ['score_ratio' => [0.70, 0.60, 0.40], 'u' => 0.6], 'note' => 'method right, slipped at the end: partial understanding'],
        ['name' => 'R5', 'when' => ['F' => 'wrong', 'S' => 'medium'], 'then' => ['score_ratio' => [0.45, 0.35, 0.20], 'u' => 0.3]],
        ['name' => 'R6', 'when' => ['F' => 'wrong', 'S' => 'few'], 'then' => ['score_ratio' => [0.00, 0.00, 0.00], 'u' => 0.0]],
    ],
];
