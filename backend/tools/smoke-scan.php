<?php

/*
 * Builds POST /scans payloads from a layout for tools/smoke.sh (local only).
 *
 * It stands in for the phone: the app reads the signed QR printed on the
 * worksheet, so this helper signs the same QR with QrSigner (QR_SIGNING_KEY
 * from .env), then renders one WebP crop per answer region with PHP GD
 * (typed answers in Sarabun so a real model can read them) plus a page image.
 *
 * Usage:
 *   php tools/smoke-scan.php <layout.json> <student_id> <answers.json> <out_dir>
 *
 * layout.json  = the `data` object of GET /assignments/{id}/layouts?version=
 * answers.json = {"<question_id>": {"text": "...", "lines": ["..."], "final": "...",
 *                 "option": "B", "cnn": "125", "cnn_confidence": 0.95, "ink": 0.1}}
 * Writes <out_dir>/page<N>/meta.json and the files named in it, and prints
 * one page directory per line.
 */

use App\Domain\Worksheets\QrSigner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

[$script, $layoutPath, $studentId, $answersPath, $outDir] = array_pad($argv, 5, null);
if ($outDir === null) {
    fwrite(STDERR, "usage: php tools/smoke-scan.php <layout.json> <student_id> <answers.json> <out_dir>\n");
    exit(2);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$layout = json_decode((string) file_get_contents($layoutPath), true, flags: JSON_THROW_ON_ERROR);
$answers = json_decode((string) file_get_contents($answersPath), true, flags: JSON_THROW_ON_ERROR);
$font = __DIR__.'/../resources/fonts/Sarabun-Regular.ttf';
$signer = QrSigner::fromConfig();

/** A white crop with the given lines of text drawn in dark ink. */
$render = function (array $lines, int $w, int $h, string $path) use ($font): void {
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    $ink = imagecolorallocate($img, 20, 30, 90);
    $rule = imagecolorallocate($img, 200, 200, 200);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);
    $rows = max(1, count($lines));
    $step = intdiv($h, $rows);
    $size = max(12, min(34, (int) ($step * 0.5)));
    foreach (array_values($lines) as $i => $text) {
        $baseline = ($i + 1) * $step - (int) ($step * 0.25);
        imageline($img, 6, $baseline + 6, $w - 6, $baseline + 6, $rule);
        if ($text !== '') {
            imagettftext($img, $size, 0, 14, $baseline, $ink, $font, (string) $text);
        }
    }
    imagewebp($img, $path, 90);
    imagedestroy($img);
};

/** An mcq strip: four bubbles, the chosen one filled. */
$bubbles = function (?string $chosen, string $path) use ($font): void {
    $img = imagecreatetruecolor(480, 60);
    $white = imagecolorallocate($img, 255, 255, 255);
    $ink = imagecolorallocate($img, 20, 20, 20);
    imagefilledrectangle($img, 0, 0, 479, 59, $white);
    foreach (['A', 'B', 'C', 'D'] as $i => $opt) {
        $cx = 60 + $i * 110;
        imagettftext($img, 16, 0, $cx - 45, 38, $ink, $font, $opt);
        $opt === $chosen
            ? imagefilledellipse($img, $cx, 30, 30, 30, $ink)
            : imageellipse($img, $cx, 30, 30, 30, $ink);
    }
    imagewebp($img, $path, 90);
    imagedestroy($img);
};

foreach ($layout['pages'] as $page) {
    $dir = rtrim($outDir, '/').'/page'.$page['page'];
    @mkdir($dir, 0777, true);
    $render(['EduVision smoke page '.$page['page']], 600, 840, $dir.'/page.webp');

    $regions = [];
    foreach ($page['regions'] as $region) {
        $qid = (int) $region['question_id'];
        $a = $answers[(string) $qid] ?? [];
        $file = 'crop_'.$region['region_id'];
        $entry = ['region_id' => $region['region_id'], 'question_id' => $qid, 'file' => $file];

        if ($region['kind'] === 'mcq') {
            $chosen = $a['option'] ?? null;
            $fill = [];
            foreach ($region['bubbles'] as $b) {
                $fill[$b['option']] = $b['option'] === $chosen ? 0.86 : 0.04;
            }
            $entry['mcq_fill'] = $fill;
            $bubbles($chosen, $dir.'/'.$file.'.webp');
        } else {
            $lines = $a['lines'] ?? [$a['text'] ?? ''];
            $lineCount = (int) ($region['line_count'] ?? 1);
            $lines = array_pad($lines, max(count($lines), $lineCount), '');
            $render($lines, 900, max(90, 70 * count($lines)), $dir.'/'.$file.'.webp');
            $entry['ink_ratio'] = (float) ($a['ink'] ?? (($a['text'] ?? '') === '' && ($a['lines'] ?? []) === [] ? 0.0 : 0.1));
            if (isset($region['final_answer'])) {
                $entry['final_file'] = $file.'_final';
                $render([$a['final'] ?? ''], 360, 90, $dir.'/'.$file.'_final.webp');
            }
            if (isset($a['cnn'])) {
                $entry['cnn'] = ['text' => (string) $a['cnn'], 'confidence' => (float) ($a['cnn_confidence'] ?? 0.95)];
            }
        }
        $regions[] = $entry;
    }

    $meta = [
        'client_scan_id' => (string) Str::uuid(),
        'qr' => $signer->sign((int) $layout['assignment_id'], (int) $studentId, (int) $page['page'], (int) $layout['version']),
        'scanned_at' => now('Asia/Bangkok')->toIso8601String(),
        'blur_score' => 182.4,
        'regions' => $regions,
    ];
    file_put_contents($dir.'/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo $dir, PHP_EOL;
}
