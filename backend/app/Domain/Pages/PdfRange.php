<?php

namespace App\Domain\Pages;

use App\Domain\Students\LoginCardRenderer;
use App\Exceptions\ApiException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Pages from..to of a PDF as a new PDF (DESIGN §19.5, a document longer
 * than 30 pages): mPDF + FPDI importPage, light work. The free FPDI cannot
 * parse a cross-reference stream (PDF 1.5+, e.g. Word's "Save as PDF"), so
 * such a file cannot be cut: 422 document_split_unsupported, and the
 * teacher saves only the pages needed as a new PDF.
 */
final class PdfRange
{
    public const UNSUPPORTED = 'ไฟล์นี้ตัดช่วงหน้าไม่ได้ บันทึกเฉพาะหน้าที่ต้องใช้เป็น PDF ใหม่ (ไม่เกิน 30 หน้า) แล้วแนบใหม่';

    /**
     * @throws ApiException 422 document_split_unsupported
     */
    public static function cut(string $bytes, int $from, int $to): string
    {
        try {
            $mpdf = new Mpdf(['tempDir' => LoginCardRenderer::tempDir()]);
            $count = $mpdf->setSourceFile(StreamReader::createByString($bytes));
            if ($from < 1 || $to < $from || $to > $count) {
                throw new ApiException('ช่วงหน้าไม่ถูกต้อง', 'validation_failed', 422, ['page_to' => ["ไฟล์นี้มี {$count} หน้า"]]);
            }
            for ($page = $from; $page <= $to; $page++) {
                $template = $mpdf->importPage($page);
                $size = $mpdf->getTemplateSize($template);
                $mpdf->AddPageByArray([
                    'orientation' => $size['width'] > $size['height'] ? 'L' : 'P',
                    'sheet-size' => [$size['width'], $size['height']],
                ]);
                $mpdf->useTemplate($template);
            }

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException(self::UNSUPPORTED, 'document_split_unsupported', 422);
        }
    }
}
