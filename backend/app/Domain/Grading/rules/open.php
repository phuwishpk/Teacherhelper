<?php

/*
 * Fuzzy system 1 for open answers (DESIGN §11.5; twin of ml/fuzzy OPEN).
 * K = level of the core criterion, R = points-weighted mean of the others.
 */

return [
    'name' => 'open',
    'variables' => [
        'K' => ['low' => ['type' => 'low'], 'high' => ['type' => 'high']],
        'R' => [
            'few' => ['type' => 'ramp_down', 'a' => 0.0, 'b' => 0.5],
            'medium' => ['type' => 'tri', 'a' => 0.2, 'm' => 0.5, 'b' => 0.8],
            'many' => ['type' => 'ramp_up', 'a' => 0.5, 'b' => 1.0],
        ],
    ],
    'outputs' => ['score_ratio', 'u'],
    'rules' => [
        ['name' => 'O1', 'when' => ['K' => 'high', 'R' => 'many'], 'then' => ['score_ratio' => [1.00, 1.00, 1.00], 'u' => 1.0]],
        ['name' => 'O2', 'when' => ['K' => 'high', 'R' => 'medium'], 'then' => ['score_ratio' => [0.85, 0.80, 0.70], 'u' => 0.75]],
        ['name' => 'O3', 'when' => ['K' => 'high', 'R' => 'few'], 'then' => ['score_ratio' => [0.70, 0.60, 0.50], 'u' => 0.55], 'note' => 'has the core but little detail'],
        ['name' => 'O4', 'when' => ['K' => 'low', 'R' => 'many'], 'then' => ['score_ratio' => [0.55, 0.45, 0.30], 'u' => 0.35], 'note' => 'all the pieces but missed the core: below O3'],
        ['name' => 'O5', 'when' => ['K' => 'low', 'R' => 'medium'], 'then' => ['score_ratio' => [0.35, 0.25, 0.10], 'u' => 0.2]],
        ['name' => 'O6', 'when' => ['K' => 'low', 'R' => 'few'], 'then' => ['score_ratio' => [0.00, 0.00, 0.00], 'u' => 0.0]],
    ],
];
