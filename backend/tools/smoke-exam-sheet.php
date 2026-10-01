<?php

/*
 * Builds POST /exam-sheets payloads from an exam scan kit for tools/smoke.sh
 * (local only).
 *
 * It stands in for the phone (DESIGN §22.9): the app reads the signed EVX1
 * QR printed on the answer sheet and measures the fill of every bubble, so
 * this helper signs the same QR with QrSigner (QR_SIGNING_KEY from .env)
 * and writes the fills of a sheet marked as asked (0.9 for a mark, 0.02
 * elsewhere) plus a small WebP page image.
 *
 * Usage:
 *   php tools/smoke-exam-sheet.php <kit.json> <student_id> <version_no> <marks.json> <out_dir>
 *
 * kit.json   = the `data` object of GET /exams/{id}/scan-kit
 * marks.json = {"<sheet_no>": 2 | [1, 2] | "12"}: a displayed position, several
 *              positions (a double mark) or the digits of a numeric answer
 * Writes <out_dir>/page<N>/meta.json and page.webp, and prints one page
 * directory per line.
 */

use App\Domain\Worksheets\QrSigner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

[$script, $kitPath, $studentId, $versionNo, $marksPath, $outDir] = array_pad($argv, 6, null);
if ($outDir === null) {
    fwrite(STDERR, "usage: php tools/smoke-exam-sheet.php <kit.json> <student_id> <version_no> <marks.json> <out_dir>\n");
    exit(2);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$kit = json_decode((string) file_get_contents($kitPath), true, flags: JSON_THROW_ON_ERROR);
$marks = json_decode((string) file_get_contents($marksPath), true, flags: JSON_THROW_ON_ERROR);
$signer = QrSigner::fromConfig();
$version = (int) $versionNo;

foreach ($kit['layouts'] as $index => $page) {
    $pageNo = $index + 1;
    $versionFill = null;
    $rows = [];
    $digits = [];
    foreach ($page['regions'] as $region) {
        if ($region['kind'] === 'version_bubbles') {
            foreach ($region['bubbles'] as $bubble) {
                $versionFill[(string) $bubble['value']] = (int) $bubble['value'] === $version ? 0.92 : 0.02;
            }
        } elseif ($region['kind'] === 'omr_row') {
            $mark = array_map('intval', (array) ($marks[(string) $region['sheet_no']] ?? []));
            foreach ($region['bubbles'] as $bubble) {
                $rows[(string) $region['sheet_no']][(string) $bubble['value']] = in_array((int) $bubble['value'], $mark, true) ? 0.9 : 0.02;
            }
        } elseif (isset($region['columns'])) {
            $text = (string) ($marks[(string) $region['sheet_no']] ?? '');
            $columns = [];
            foreach ($region['columns'] as $i => $column) {
                $fill = [];
                foreach ($column['bubbles'] as $bubble) {
                    $fill[(string) $bubble['value']] = ($text[$i] ?? null) === (string) $bubble['value'] ? 0.9 : 0.02;
                }
                $columns[] = $fill;
            }
            $digits[(string) $region['sheet_no']] = ['sign' => ($region['sign'] ?? null) === null ? null : 0.02, 'columns' => $columns];
        }
    }

    $dir = rtrim($outDir, '/')."/page{$pageNo}";
    @mkdir($dir, 0775, true);
    $img = imagecreatetruecolor(620, 877);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagestring($img, 5, 40, 40, "EduVision smoke answer sheet {$studentId} p{$pageNo}", imagecolorallocate($img, 20, 20, 60));
    imagewebp($img, "{$dir}/page.webp", 80);
    imagedestroy($img);

    $meta = [
        'client_scan_id' => (string) Str::uuid(),
        'qr' => $signer->signExamSheet((int) $kit['assignment_id'], (int) $studentId, $pageNo, (int) $kit['layout_version']),
        'scanned_at' => now()->utc()->toIso8601ZuluString(),
        'blur_score' => 150.5,
        'version_fill' => $versionFill,
        'rows' => (object) $rows,
        'digits' => (object) $digits,
    ];
    file_put_contents("{$dir}/meta.json", json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    echo $dir, "\n";
}
