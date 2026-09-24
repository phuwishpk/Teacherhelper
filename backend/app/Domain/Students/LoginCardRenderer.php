<?php

namespace App\Domain\Students;

use Mpdf\Mpdf;

/**
 * Renders student QR login cards (DESIGN §9.2) as an A4 PDF with mPDF:
 * 2 x 5 cards per page, each with the classroom, student number and name, the
 * class_code for the PIN fallback and a QR with `EVL1.{token}`. The PIN is
 * never printed: the teacher tells it to the student.
 *
 * Thai text uses mPDF's bundled Garuda font, so no font files ship with the repo.
 */
class LoginCardRenderer
{
    public const CARDS_PER_PAGE = 10;

    /**
     * @param  array<int, array{student_number: int, name: string, classroom_name: string, class_code: string, qr_payload: string}>  $cards
     * @return string PDF bytes
     */
    public function render(string $schoolName, array $cards): string
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'garuda',
            'default_font_size' => 11,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'tempDir' => self::tempDir(),
        ]);
        $mpdf->SetTitle('บัตรเข้าสู่ระบบ EduVision');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $mpdf->WriteHTML(self::css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->html($schoolName, $cards), \Mpdf\HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** mPDF caches font data here; vendor/ is read-only on the Plesk deploy. */
    public static function tempDir(): string
    {
        $dir = storage_path('app/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function css(): string
    {
        return <<<'CSS'
        table.cards { width: 100%; border-collapse: collapse; }
        table.cards td { width: 50%; height: 54mm; border: 0.3mm dashed #888; padding: 3mm; vertical-align: top; }
        .school { font-size: 9pt; color: #555; }
        .title { font-size: 9pt; color: #555; }
        .name { font-size: 13pt; font-weight: bold; margin-top: 1mm; }
        .meta { font-size: 10pt; margin-top: 1mm; }
        .code { font-family: dejavusansmono; font-size: 12pt; letter-spacing: 1pt; }
        .hint { font-size: 8pt; color: #777; margin-top: 1mm; }
        .qr { text-align: right; vertical-align: top; }
        table.inner { width: 100%; border-collapse: collapse; }
        table.inner td { border: none; padding: 0; height: auto; }
        CSS;
    }

    /**
     * @param  array<int, array{student_number: int, name: string, classroom_name: string, class_code: string, qr_payload: string}>  $cards
     */
    private function html(string $schoolName, array $cards): string
    {
        $pages = array_chunk($cards, self::CARDS_PER_PAGE);
        $html = '';

        foreach ($pages as $pageIndex => $pageCards) {
            if ($pageIndex > 0) {
                $html .= '<pagebreak />';
            }
            $html .= '<table class="cards">';
            foreach (array_chunk($pageCards, 2) as $pair) {
                $html .= '<tr>';
                foreach ($pair as $card) {
                    $html .= '<td>'.$this->card($schoolName, $card).'</td>';
                }
                if (count($pair) === 1) {
                    $html .= '<td></td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    /**
     * @param  array{student_number: int, name: string, classroom_name: string, class_code: string, qr_payload: string}  $card
     */
    private function card(string $schoolName, array $card): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<table class="inner"><tr>'
            .'<td style="width: 58%;">'
            .'<div class="school">'.$e($schoolName).'</div>'
            .'<div class="title">บัตรเข้าสู่ระบบ EduVision</div>'
            .'<div class="name">เลขที่ '.$e((string) $card['student_number']).' '.$e($card['name']).'</div>'
            .'<div class="meta">ห้อง '.$e($card['classroom_name']).'</div>'
            .'<div class="meta">รหัสห้อง <span class="code">'.$e($card['class_code']).'</span></div>'
            .'<div class="hint">สแกน QR เพื่อเข้าสู่ระบบ หรือใช้รหัสห้อง + เลขที่ + PIN ที่ครูแจ้ง</div>'
            .'</td>'
            .'<td class="qr" style="width: 42%;">'
            .'<barcode code="'.$e($card['qr_payload']).'" type="QR" size="1.05" error="M" disableborder="1" />'
            .'</td>'
            .'</tr></table>';
    }
}
