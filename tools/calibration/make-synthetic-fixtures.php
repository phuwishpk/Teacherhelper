<?php

/*
 * Krucheck: synthetic golden fixtures for the Gemini calibration harness
 * (DESIGN §21.10, artisan eduvision:calibrate-gemini).
 *
 * Draws answer crops, whole pages and a typed answer key with PHP GD and
 * writes them with their labels to docs/fixtures/calibration/:
 *
 *   short     48 answer-box crops (numbers and Thai words), 768 px wide
 *   work      42 show_work crops (working area + final-answer box)
 *   page       8 whole pages of 5 questions (3 short, 2 show_work), 2000 px tall
 *   document   2 typed answer-key pages of 20 answers each
 *
 * The "handwriting" is a font with per-character jitter: it is NOT real
 * handwriting. It measures how the media resolution changes Gemini's
 * reading of small, noisy text, and fills the manifest until the team's
 * own handwriting samples are added (DESIGN §16.2; never real students').
 *
 * Deterministic (fixed seed). Needs PHP with GD + FreeType and the fonts of
 * backend/ (run composer install first for the mPDF Garuda fonts).
 *
 *   php tools/calibration/make-synthetic-fixtures.php
 *
 * Rewrites docs/fixtures/calibration/synthetic/ and the synthetic entries of
 * manifest.json; entries with another "source" (the team's samples) are kept.
 */

declare(strict_types=1);

const SEED = 20260930;

$root = dirname(__DIR__, 2);
$out = $root.'/docs/fixtures/calibration';
$dir = $out.'/synthetic';
$printed = $root.'/backend/resources/fonts/Sarabun-Regular.ttf';
$hands = array_values(array_filter([
    $root.'/backend/vendor/mpdf/mpdf/ttfonts/Garuda-Oblique.ttf',
    $root.'/backend/vendor/mpdf/mpdf/ttfonts/Garuda.ttf',
    $printed,
], 'is_file'));

if (! function_exists('imagettftext') || ! function_exists('imagewebp')) {
    fwrite(STDERR, "PHP GD with FreeType and WebP is required\n");
    exit(1);
}
if (! is_file($printed)) {
    fwrite(STDERR, "missing {$printed}\n");
    exit(1);
}
if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
    fwrite(STDERR, "cannot create {$dir}\n");
    exit(1);
}
foreach (glob($dir.'/*') ?: [] as $old) {
    unlink($old);
}

mt_srand(SEED);

/** Blank grayscale-ish canvas with light paper noise. */
function canvas(int $w, int $h): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    $paper = imagecolorallocate($im, 250, 250, 246);
    imagefill($im, 0, 0, $paper);
    for ($i = 0; $i < intdiv($w * $h, 400); $i++) {
        $g = mt_rand(215, 240);
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($im, $g, $g, $g));
    }

    return $im;
}

/** Printed text (question sheets, the typed key). */
function printed(GdImage $im, string $font, float $size, int $x, int $y, string $text): void
{
    imagettftext($im, $size, 0, $x, $y, imagecolorallocate($im, 30, 30, 30), $font, $text);
}

/**
 * "Handwritten" text: each grapheme cluster (a Thai base with its marks
 * stays together) with its own size, angle and baseline jitter, in a
 * pen-like blue-black.
 */
function hand(GdImage $im, array $fonts, float $size, int $x, int $y, string $text): int
{
    $font = $fonts[mt_rand(0, count($fonts) - 1)];
    $ink = imagecolorallocate($im, mt_rand(15, 70), mt_rand(20, 75), mt_rand(60, 130));
    preg_match_all('/\X/u', $text, $m);
    foreach ($m[0] as $cluster) {
        if (trim($cluster) === '') {
            $x += (int) ($size * 0.45);

            continue;
        }
        $s = $size * (0.9 + mt_rand(0, 20) / 100);
        $angle = mt_rand(-9, 9);
        $box = imagettftext($im, $s, $angle, $x, $y + mt_rand(-3, 3), $ink, $font, $cluster);
        $x = max($box[2], $box[4]) + mt_rand(1, 4);
    }

    return $x;
}

function save(GdImage $im, string $path, int $maxLong): void
{
    // A phone photo is rarely sharp: blur about a third of the images a little.
    if (mt_rand(0, 2) === 0) {
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1.0, $maxLong / max($w, $h));
    if ($scale < 1.0) {
        $small = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagecopyresampled($small, $im, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        $im = $small;
    }
    imagewebp($im, $path, 80);
}

/** A linear equation ax + b = c with an integer answer, as the three reference steps. */
function equation(): array
{
    $a = mt_rand(2, 9);
    $x = mt_rand(2, 12);
    $b = mt_rand(1, 20);
    $c = $a * $x + $b;

    return [$a, $b, $c, $x];
}

/**
 * A show_work answer: correct, a slip in the middle step (carried forward),
 * or right steps with a different number in the final box.
 *
 * @return array{lines: list<string>, valid: list<bool>, final: string, match: list<string>}
 */
function workAnswer(int $a, int $b, int $c, int $x, string $kind): array
{
    return match ($kind) {
        'slip' => [
            'lines' => ["{$a}x + {$b} = {$c}", "{$a}x = ".($c - $b + $a), 'x = '.($x + 1)],
            'valid' => [true, false, true],
            'final' => (string) ($x + 1),
            'match' => ['different', 'partial'],
        ],
        'final' => [
            'lines' => ["{$a}x + {$b} = {$c}", "{$a}x = ".($c - $b), "x = {$x}"],
            'valid' => [true, true, true],
            'final' => (string) ($x + mt_rand(2, 5)),
            'match' => ['different', 'partial'],
        ],
        default => [
            'lines' => ["{$a}x + {$b} = {$c}", "{$a}x = ".($c - $b), "x = {$x}"],
            'valid' => [true, true, true],
            'final' => (string) $x,
            'match' => ['exact', 'equivalent'],
        ],
    };
}

function workQuestion(int $a, int $b, int $c, int $x, int $position = 1): array
{
    return [
        'position' => $position,
        'type' => 'show_work',
        'prompt_text' => "จงหาค่า x จากสมการ {$a}x + {$b} = {$c} แสดงวิธีทำ",
        'max_points' => 3,
        'is_numeric' => true,
        'answer_lines' => 3,
        'answer_key' => [
            'final' => ['accepted' => [(string) $x, "x = {$x}"], 'numeric' => ['value' => $x, 'abs_tol' => 0]],
            'reference_steps' => ["{$a}x + {$b} = {$c}", "{$a}x = ".($c - $b), "x = {$x}"],
        ],
    ];
}

/** @return array{question: array<string, mixed>, written: string, label: array<string, mixed>} */
function shortCase(int $i): array
{
    $words = [
        ['เมืองหลวงของประเทศไทยคือจังหวัดใด', ['กรุงเทพมหานคร', 'กรุงเทพฯ'], ['เชียงใหม่', 'ขอนแก่น']],
        ['สัตว์ที่ออกลูกเป็นไข่และบินได้เรียกว่าอะไร', ['นก'], ['ปลา', 'แมว']],
        ['พืชใช้แสงสร้างอาหารเรียกว่ากระบวนการอะไร', ['การสังเคราะห์ด้วยแสง', 'สังเคราะห์แสง'], ['การหายใจ', 'การคายน้ำ']],
        ['น้ำแข็งละลายกลายเป็นอะไร', ['น้ำ'], ['ไอน้ำ', 'หิมะ']],
    ];
    $correct = mt_rand(0, 99) < 55;
    if ($i % 4 === 3) {
        [$prompt, $accepted, $wrong] = $words[mt_rand(0, count($words) - 1)];
        $written = $correct ? $accepted[mt_rand(0, count($accepted) - 1)] : $wrong[mt_rand(0, count($wrong) - 1)];

        return [
            'question' => ['type' => 'short', 'prompt_text' => $prompt, 'max_points' => 1, 'is_numeric' => false, 'match_mode' => 'flexible', 'answer_key' => ['accepted' => $accepted]],
            'written' => $written,
            'label' => ['answer_text' => $written, 'key_match' => $correct ? ['exact', 'equivalent'] : ['different', 'partial']],
        ];
    }
    $p = mt_rand(12, 98);
    $q = mt_rand(3, 47);
    [$op, $value] = match ($i % 3) {
        0 => ['+', $p + $q],
        1 => ['-', $p - $q],
        default => ['×', $p * ($q % 9 + 2)],
    };
    $prompt = $op === '×' ? "{$p} × ".($q % 9 + 2).' เท่ากับเท่าไร' : "{$p} {$op} {$q} เท่ากับเท่าไร";
    $written = $correct ? (string) $value : (string) ($value + [-10, -1, 1, 9, 10][mt_rand(0, 4)]);

    return [
        'question' => ['type' => 'short', 'prompt_text' => $prompt, 'max_points' => 1, 'is_numeric' => true, 'match_mode' => 'flexible',
            'answer_key' => ['accepted' => [(string) $value], 'numeric' => ['value' => $value, 'abs_tol' => 0]]],
        'written' => $written,
        'label' => ['answer_text' => $written, 'key_match' => $correct ? ['exact', 'equivalent'] : ['different', 'partial']],
    ];
}

$items = [];

// ---- short: answer-box crops (the phone crops about 480 x 80 px of a page, sends <= 768 px) ----
for ($i = 1; $i <= 48; $i++) {
    $case = shortCase($i);
    $im = canvas(768, 128);
    hand($im, $hands, mt_rand(24, 36), mt_rand(20, 120), mt_rand(72, 96), $case['written']);
    $file = sprintf('synthetic/short-%03d.webp', $i);
    save($im, $out.'/'.$file, 768);
    $items[] = ['id' => sprintf('short-%03d', $i), 'kind' => 'short', 'source' => 'synthetic', 'file' => $file,
        'question' => $case['question'], 'label' => $case['label']];
}

// ---- work: working area (3 lines) + final-answer box ----
for ($i = 1; $i <= 42; $i++) {
    [$a, $b, $c, $x] = equation();
    $kind = ['ok', 'ok', 'slip', 'final'][$i % 4];
    $answer = workAnswer($a, $b, $c, $x, $kind);

    $im = canvas(1400, 460);
    $line = imagecolorallocate($im, 205, 215, 230);
    foreach ([140, 270, 400] as $y) {
        imageline($im, 20, $y, 1380, $y, $line);
    }
    foreach ($answer['lines'] as $n => $text) {
        hand($im, $hands, mt_rand(30, 42), mt_rand(40, 140), 125 + 130 * $n, $text);
    }
    $work = sprintf('synthetic/work-%03d.webp', $i);
    save($im, $out.'/'.$work, 768);

    $fb = canvas(480, 90);
    hand($fb, $hands, 36, mt_rand(20, 140), mt_rand(62, 76), $answer['final']);
    $final = sprintf('synthetic/work-%03d-final.webp', $i);
    save($fb, $out.'/'.$final, 768);

    $question = workQuestion($a, $b, $c, $x);
    unset($question['position']);
    $items[] = ['id' => sprintf('work-%03d', $i), 'kind' => 'work', 'source' => 'synthetic', 'file' => $work, 'final_file' => $final,
        'question' => $question,
        'label' => ['final_answer_text' => $answer['final'], 'final_answer_match' => $answer['match'], 'steps_valid' => $answer['valid']]];
}

// ---- page: a photo of a whole worksheet page (the phone sends <= 2000 px) ----
for ($p = 1; $p <= 8; $p++) {
    $im = canvas(1414, 2000);
    printed($im, $printed, 30, 90, 110, 'แบบฝึกหัดคณิตศาสตร์ ชั้นประถมศึกษาปีที่ 5');
    $questions = [];
    $y = 220;
    for ($n = 1; $n <= 5; $n++) {
        if ($n <= 3) {
            $case = shortCase($p * 5 + $n);
            printed($im, $printed, 24, 90, $y, "{$n}. ".$case['question']['prompt_text']);
            $box = imagecolorallocate($im, 120, 120, 120);
            imagerectangle($im, 860, $y - 40, 1320, $y + 40, $box);
            hand($im, $hands, 34, 885 + mt_rand(0, 60), $y + 18, $case['written']);
            $questions[] = ['position' => $n] + $case['question'] + ['label' => $case['label']];
            $y += 170;

            continue;
        }
        [$a, $b, $c, $x] = equation();
        $answer = workAnswer($a, $b, $c, $x, ['ok', 'slip', 'final'][mt_rand(0, 2)]);
        $q = workQuestion($a, $b, $c, $x, $n);
        printed($im, $printed, 24, 90, $y, "{$n}. ".$q['prompt_text']);
        $line = imagecolorallocate($im, 205, 215, 230);
        foreach ($answer['lines'] as $k => $text) {
            imageline($im, 120, $y + 90 + 90 * $k, 1100, $y + 90 + 90 * $k, $line);
            hand($im, $hands, 32, 140 + mt_rand(0, 60), $y + 78 + 90 * $k, $text);
        }
        printed($im, $printed, 22, 1120, $y + 175, 'คำตอบ');
        imagerectangle($im, 1110, $y + 190, 1320, $y + 270, imagecolorallocate($im, 120, 120, 120));
        hand($im, $hands, 34, 1135 + mt_rand(0, 40), $y + 250, $answer['final']);
        $questions[] = $q + ['label' => ['final_answer_text' => $answer['final'], 'final_answer_match' => $answer['match'], 'steps_valid' => $answer['valid']]];
        $y += 420;
    }
    $file = sprintf('synthetic/page-%03d.webp', $p);
    save($im, $out.'/'.$file, 2000);
    $items[] = ['id' => sprintf('page-%03d', $p), 'kind' => 'page', 'source' => 'synthetic', 'file' => $file, 'questions' => $questions];
}

// ---- document: a typed answer key, 20 answers per page (short numbers and mcq letters) ----
for ($d = 1; $d <= 2; $d++) {
    $im = canvas(1414, 2000);
    printed($im, $printed, 30, 90, 110, "เฉลยแบบฝึกหัดคณิตศาสตร์ ชุดที่ {$d}");
    $questions = [];
    for ($n = 1; $n <= 20; $n++) {
        $y = 200 + 85 * $n;
        if ($n % 4 === 0) {
            $letter = ['A', 'B', 'C', 'D'][mt_rand(0, 3)];
            printed($im, $printed, 26, 110, $y, "ข้อ {$n}.  ตอบ {$letter}");
            $questions[] = ['position' => $n, 'type' => 'mcq', 'prompt_text' => "ข้อ {$n} (ปรนัย)", 'label' => ['answer' => $letter]];

            continue;
        }
        $p = mt_rand(10, 99);
        $q = mt_rand(2, 9);
        $value = $n % 2 === 0 ? $p * $q : $p + $q * 7;
        $prompt = $n % 2 === 0 ? "{$p} × {$q} เท่ากับเท่าไร" : "{$p} + ".($q * 7).' เท่ากับเท่าไร';
        printed($im, $printed, 26, 110, $y, "ข้อ {$n}.  {$prompt}   ตอบ  {$value}");
        $questions[] = ['position' => $n, 'type' => 'short', 'prompt_text' => $prompt, 'label' => ['answer' => (string) $value]];
    }
    $file = sprintf('synthetic/document-%03d.webp', $d);
    save($im, $out.'/'.$file, 2000);
    $items[] = ['id' => sprintf('document-%03d', $d), 'kind' => 'document', 'source' => 'synthetic', 'file' => $file, 'questions' => $questions];
}

$manifestPath = $out.'/manifest.json';
$kept = [];
if (is_file($manifestPath)) {
    $old = json_decode((string) file_get_contents($manifestPath), true);
    foreach ((array) ($old['items'] ?? []) as $item) {
        if (($item['source'] ?? '') !== 'synthetic') {
            $kept[] = $item;
        }
    }
}

$manifest = [
    'version' => 1,
    'description' => 'Golden fixtures of the Gemini calibration harness (DESIGN §21.10). Labels are what is written on each image. Synthetic entries come from tools/calibration/make-synthetic-fixtures.php; add the team\'s own handwriting with source "team" (never real students\', §16.2).',
    'subject' => 'คณิตศาสตร์',
    'grade_level' => 5,
    'items' => [...$items, ...$kept],
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$bytes = array_sum(array_map('filesize', glob($dir.'/*') ?: []));
printf("wrote %d synthetic items (%.1f KB of images) and %d kept to %s\n", count($items), $bytes / 1024, count($kept), $manifestPath);
