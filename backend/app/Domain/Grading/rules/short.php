<?php

/*
 * Fuzzy system 1 for short answers (DESIGN §11.4; twin of ml/fuzzy SHORT).
 * M = how well the answer matches the key (AnswerMatcher). One rule per set.
 */

return [
    'name' => 'short',
    'variables' => [
        'M' => [
            'mismatch' => ['type' => 'ramp_down', 'a' => 0.3, 'b' => 0.6],
            'close' => ['type' => 'tri', 'a' => 0.4, 'm' => 0.7, 'b' => 0.95],
            'match' => ['type' => 'ramp_up', 'a' => 0.85, 'b' => 1.0],
        ],
    ],
    'outputs' => ['score_ratio', 'u'],
    'rules' => [
        ['name' => 'S1', 'when' => ['M' => 'mismatch'], 'then' => ['score_ratio' => [0.0, 0.0, 0.0], 'u' => 0.0]],
        ['name' => 'S2', 'when' => ['M' => 'close'], 'then' => ['score_ratio' => [0.7, 0.5, 0.0], 'u' => 0.5]],
        ['name' => 'S3', 'when' => ['M' => 'match'], 'then' => ['score_ratio' => [1.0, 1.0, 1.0], 'u' => 1.0]],
    ],
];
