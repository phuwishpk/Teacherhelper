<?php

/*
 * Fuzzy system 2: review priority (DESIGN §11.8; twin of ml/fuzzy
 * REVIEW_PRIORITY). D = readers disagree, L = hard to read, B = u near an
 * understanding boundary. Not affected by strictness.
 */

$lowHigh = ['low' => ['type' => 'low'], 'high' => ['type' => 'high']];

return [
    'name' => 'review_priority',
    'variables' => ['D' => $lowHigh, 'L' => $lowHigh, 'B' => $lowHigh],
    'outputs' => ['p'],
    'rules' => [
        ['name' => 'P1', 'when' => ['D' => 'high'], 'then' => ['p' => 1.0], 'note' => 'readers disagree'],
        ['name' => 'P2', 'when' => ['L' => 'high'], 'then' => ['p' => 0.8], 'note' => 'hard to read'],
        ['name' => 'P3', 'when' => ['B' => 'high'], 'then' => ['p' => 0.6], 'note' => 'near an understanding boundary'],
        ['name' => 'P4', 'when' => ['D' => 'low', 'L' => 'low', 'B' => 'low'], 'then' => ['p' => 0.0], 'note' => 'nothing suspicious'],
    ],
];
