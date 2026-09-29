<?php

namespace Tests\Unit\Pages;

use App\Domain\Pages\PdfPageCounter;
use Mpdf\Mpdf;
use Tests\TestCase;

/** DESIGN §19.4 PdfPageCounter: FPDI first, then the object count, else unreadable. */
class PdfPageCounterTest extends TestCase
{
    public function test_a_pdf_with_a_cross_reference_table_is_read_by_fpdi(): void
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        foreach ([1, 2, 3] as $i) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<p>หน้า {$i}</p>");
        }

        $this->assertSame(3, PdfPageCounter::count($mpdf->Output('', 'S')));
    }

    public function test_a_cross_reference_stream_with_object_streams_is_counted_by_its_objects(): void
    {
        $pdf = self::xrefStreamPdf(2);

        $this->assertSame(0, preg_match('#/Type\s*/Page(?![A-Za-z])#', $pdf), 'the pages are only inside the compressed object stream');
        $this->assertSame(2, PdfPageCounter::count($pdf));
    }

    public function test_encrypted_broken_or_non_pdf_files_are_unreadable(): void
    {
        $encrypted = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R >> endobj\ntrailer << /Root 1 0 R /Encrypt 4 0 R >>\n%%EOF";

        $this->assertSame(0, PdfPageCounter::count($encrypted));
        $this->assertSame(0, PdfPageCounter::count("%PDF-1.7\n1 0 obj garbage"));
        $this->assertSame(0, PdfPageCounter::count("\xFF\xD8\xFF\xE0 a jpeg"));
    }

    /**
     * A minimal PDF 1.5 whose catalog, page tree and pages sit in a
     * FlateDecode object stream behind a cross-reference stream (what
     * Word's "Save as PDF" writes): the free FPDI refuses it.
     */
    private static function xrefStreamPdf(int $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 3 0 R >>'];
        $kids = implode(' ', array_map(fn (int $i) => (4 + $i).' 0 R', range(0, $pages - 1)));
        $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";
        for ($i = 0; $i < $pages; $i++) {
            $objects[] = '<< /Type /Page /Parent 3 0 R /MediaBox [0 0 595 842] >>';
        }
        $header = '';
        $body = '';
        foreach ($objects as $i => $object) {
            $header .= (2 + $i).' '.strlen($body).' ';
            $body .= $object."\n";
        }
        $stream = gzcompress($header.$body);

        $pdf = "%PDF-1.5\n";
        $pdf .= '1 0 obj << /Type /ObjStm /N '.count($objects).' /First '.strlen($header).' /Filter /FlateDecode /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n";
        $xrefAt = strlen($pdf);
        $xref = gzcompress(str_repeat("\x01\x00\x00\x00", 3));
        $pdf .= '9 0 obj << /Type /XRef /Size 10 /W [1 2 1] /Root 2 0 R /Filter /FlateDecode /Length '.strlen($xref)." >>\nstream\n{$xref}\nendstream\nendobj\n";

        return $pdf."startxref\n{$xrefAt}\n%%EOF\n";
    }
}
