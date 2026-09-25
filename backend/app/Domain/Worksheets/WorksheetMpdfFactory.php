<?php

namespace App\Domain\Worksheets;

use App\Domain\Students\LoginCardRenderer;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

/**
 * One mPDF configuration for everything that measures or draws a worksheet,
 * so the layout measured by LayoutBuilder is exactly what the renderer prints.
 *
 * Font: Sarabun (OFL, backend/resources/fonts) with OpenType layout, which is
 * also what enables mPDF's dictionary-based Thai line breaking
 * (useDictionaryLBR, DESIGN §5.2). autoLangToFont is off so Thai text is not
 * swapped to mPDF's Garuda.
 */
class WorksheetMpdfFactory
{
    public const FONT = 'sarabun';

    public const FONT_SIZE_PT = 13;

    private const CSS = <<<'CSS'
    body { font-family: sarabun; font-size: 13pt; line-height: 1.4; color: #000000; }
    div.q { margin: 0; padding: 0 0 0 9mm; text-indent: -9mm; }
    span.pts { color: #555555; font-size: 10.5pt; }
    div.qimg { margin: 1.5mm 0 0 9mm; padding: 0; }
    CSS;

    public function create(): Mpdf
    {
        $config = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => LoginCardRenderer::tempDir(),
            'fontDir' => [...$config['fontDir'], resource_path('fonts')],
            'fontdata' => $fonts['fontdata'] + [
                self::FONT => [
                    'R' => 'Sarabun-Regular.ttf',
                    'B' => 'Sarabun-Bold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => self::FONT,
            'default_font_size' => self::FONT_SIZE_PT,
            'margin_left' => WorksheetGeometry::CONTENT_LEFT,
            'margin_right' => WorksheetGeometry::PAGE_W - WorksheetGeometry::CONTENT_RIGHT,
            'margin_top' => WorksheetGeometry::CONTENT_TOP,
            'margin_bottom' => WorksheetGeometry::PAGE_H - WorksheetGeometry::CONTENT_BOTTOM,
            'margin_header' => 0,
            'margin_footer' => 0,
            'useDictionaryLBR' => true,
        ]);
        $mpdf->autoScriptToLang = false;
        $mpdf->autoLangToFont = false;
        $mpdf->WriteHTML(self::CSS, HTMLParserMode::HEADER_CSS);

        return $mpdf;
    }
}
