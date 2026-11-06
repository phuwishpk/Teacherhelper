<?php

namespace App\Domain\Worksheets;

use App\Domain\Students\LoginCardRenderer;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Concatenates PDFs page by page with mPDF's FPDI import (setasign/fpdi,
 * already required by mpdf/mpdf). Used by MergeWorksheetsJob (DESIGN §5.5).
 */
class PdfMerger
{
    /**
     * @param  list<string>  $sources  absolute paths, in order
     * @return int pages written
     */
    public function merge(array $sources, string $target): int
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => LoginCardRenderer::tempDir(),
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);

        $pages = 0;
        foreach ($sources as $source) {
            $count = $mpdf->setSourceFile($source);
            for ($i = 1; $i <= $count; $i++) {
                $template = $mpdf->importPage($i);
                $size = $mpdf->getTemplateSize($template);
                $mpdf->AddPageByArray([
                    'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                    'sheet-size' => [$size['width'], $size['height']],
                ]);
                $mpdf->useTemplate($template);
                $pages++;
            }
        }

        $directory = dirname($target);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $mpdf->Output($target, Destination::FILE);

        return $pages;
    }

    public static function pageCount(string $path): int
    {
        $mpdf = new Mpdf(['tempDir' => LoginCardRenderer::tempDir()]);

        return $mpdf->setSourceFile($path);
    }
}
