<?php

namespace Tests\Support;

use App\Domain\Worksheets\PdfMerger;
use RuntimeException;

/**
 * Reads the streams of a PDF that mPDF wrote, for tests that check what was
 * drawn. Each stream is cut by its `/Length`, never by searching for
 * "endstream": the data is binary and varies with the text on the page, so
 * a delimiter search breaks on some byte sequences and made tests flaky.
 */
final class PdfStreams
{
    /**
     * Every stream in file order, inflated when it is Flate-compressed.
     *
     * @return list<string>
     */
    public static function decoded(string $pdf): array
    {
        $out = [];
        $offset = 0;
        // mPDF writes "<<…/Length n…>>\nstream\n<n bytes>\nendstream"; the dictionary may span lines.
        while (preg_match('/\/Length (\d+)\b.{0,400}?>>\s*stream\n/s', $pdf, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $length = (int) $m[1][0];
            $data = substr($pdf, $start, $length);
            if (substr($pdf, $start + $length, 10) !== "\nendstream") {
                throw new RuntimeException("PDF stream at byte {$start} does not end after its /Length {$length}.");
            }
            $inflated = @gzuncompress($data);
            $out[] = $inflated === false ? $data : $inflated;
            $offset = $start + $length;
        }

        return $out;
    }

    /** Page count of PDF bytes, through a temp file that is always removed. */
    public static function pageCount(string $pdf): int
    {
        $base = tempnam(sys_get_temp_dir(), 'pdf');
        if ($base === false) {
            throw new RuntimeException('No temp file for the PDF.');
        }
        $path = $base.'.pdf';
        file_put_contents($path, $pdf);
        try {
            return PdfMerger::pageCount($path);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }
}
