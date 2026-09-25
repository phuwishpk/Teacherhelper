<?php

namespace App\Domain\Worksheets;

use App\Models\Assignment;
use App\Models\Layout;
use Carbon\CarbonImmutable;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\QrCode\Output\Mpdf as QrToMpdf;
use Mpdf\QrCode\QrCode;
use RuntimeException;

/**
 * Renders per-student worksheets (DESIGN §5.2, §5.5): A4, Sarabun with Thai
 * dictionary line breaking, the ArUco markers 0–3 centred on the frame
 * corners, a header with title, name, number and page x/y, the signed QR
 * `EV1.{assignment}.{student}.{page}.{layout_version}.{sig}`, and the answer
 * areas (mcq bubbles A–D, short-answer boxes, numbered show_work lines with a
 * final-answer box, open lines).
 *
 * Every student's copy is laid out from the same plan, and the plan is
 * checked against the stored layout version first: the PDF can never
 * disagree with the layout JSON the phone crops with. A null student prints
 * the anonymous spare sheet of DESIGN §18.3 (student_id 0, blank name).
 */
class WorksheetPdfRenderer
{
    /** Largest allowed difference (mm) between the measured and the rendered prompt bottom. */
    private const DRIFT_TOLERANCE_MM = 0.05;

    public function __construct(
        private readonly WorksheetMpdfFactory $mpdfFactory,
        private readonly LayoutBuilder $layoutBuilder,
        private readonly QrSigner $qrSigner,
    ) {}

    /**
     * @param  list<WorksheetStudent|null>  $students
     * @return string PDF bytes
     *
     * @throws WorksheetLayoutException when the assignment no longer matches $layout
     */
    public function render(Assignment $assignment, Layout $layout, array $students): string
    {
        if ($students === []) {
            throw new RuntimeException('No students to render.');
        }

        $assignment->loadMissing(['classroom', 'subject']);
        $markers = ArucoMarkers::load();
        $plan = $this->layoutBuilder->plan($assignment);

        if ($plan->toLayoutPages($assignment->id, $layout->version, $markers) != $layout->pages) {
            throw new WorksheetLayoutException(
                "การบ้านถูกแก้ไขหลังสร้าง layout เวอร์ชัน {$layout->version} กรุณาสร้าง layout ใหม่แล้วสั่งพิมพ์อีกครั้ง",
            );
        }

        $mpdf = $this->mpdfFactory->create();
        $mpdf->SetTitle($assignment->title);
        $mpdf->SetCreator('EduVision');

        foreach ($students as $student) {
            foreach ($plan->pages as $index => $placedQuestions) {
                $page = $index + 1;
                $mpdf->AddPage();
                $this->withoutPageBreaks($mpdf, function () use ($mpdf, $markers, $assignment, $student, $page, $plan, $layout) {
                    $this->drawMarkers($mpdf, $markers);
                    $this->drawHeader($mpdf, $assignment, $student, $page, $plan->pageCount());
                    $this->drawQr($mpdf, $this->qrSigner->sign($assignment->id, $student?->id ?? 0, $page, $layout->version));
                    $this->drawFooter($mpdf, $layout->version);
                });

                foreach ($placedQuestions as $placed) {
                    $this->drawQuestion($mpdf, $placed);
                }
            }
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function drawMarkers(Mpdf $mpdf, ArucoMarkers $markers): void
    {
        $size = $markers->imageSizeMm;
        foreach ($markers->markers as $marker) {
            $mpdf->Image($marker['file'], $marker['cx'] - $size / 2, $marker['cy'] - $size / 2, $size, $size, 'png', '', true, false);
        }
    }

    private function drawHeader(Mpdf $mpdf, Assignment $assignment, ?WorksheetStudent $student, int $page, int $pageCount): void
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $mpdf->WriteFixedPosHTML(
            '<div style="font-size: 14pt; font-weight: bold;">การบ้าน: '.$e($assignment->title).'</div>',
            WorksheetGeometry::TITLE_X, WorksheetGeometry::TITLE_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::TITLE_H, 'auto',
        );

        $name = $student !== null
            ? '<b>'.$e($student->name).'</b>'
            : '..............................................................';
        $number = $student !== null ? '<b>'.$student->number.'</b>' : '..........';
        $mpdf->WriteFixedPosHTML(
            '<div style="font-size: 12pt;">ชื่อ '.$name.'&nbsp;&nbsp;&nbsp;เลขที่ '.$number
                .'&nbsp;&nbsp;&nbsp;หน้า '.$page.'/'.$pageCount.'</div>',
            WorksheetGeometry::TITLE_X, WorksheetGeometry::STUDENT_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::STUDENT_H, 'auto',
        );

        $meta = [];
        if ($assignment->classroom !== null) {
            $meta[] = 'ห้อง '.$e($assignment->classroom->name);
        }
        if ($assignment->subject !== null) {
            $meta[] = $e($assignment->subject->name);
        }
        if ($assignment->due_at !== null) {
            $meta[] = 'ส่ง '.$e(self::thaiDate(CarbonImmutable::parse($assignment->due_at)));
        }
        if ($meta !== []) {
            $mpdf->WriteFixedPosHTML(
                '<div style="font-size: 10.5pt; color: #444444;">'.implode(' · ', $meta).'</div>',
                WorksheetGeometry::TITLE_X, WorksheetGeometry::META_Y, WorksheetGeometry::TITLE_W, WorksheetGeometry::META_H, 'auto',
            );
        }

        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.3);
        $mpdf->Line(WorksheetGeometry::CONTENT_LEFT, WorksheetGeometry::HEADER_RULE_Y, WorksheetGeometry::CONTENT_RIGHT, WorksheetGeometry::HEADER_RULE_Y);
    }

    private function drawQr(Mpdf $mpdf, string $payload): void
    {
        $qr = new QrCode($payload, 'M');
        $qr->disableBorder(); // the blank paper around it is the quiet zone
        (new QrToMpdf)->output($qr, $mpdf, WorksheetGeometry::QR_X, WorksheetGeometry::QR_Y, WorksheetGeometry::QR_SIZE);
        $mpdf->SetFillColor(255, 255, 255);
        $mpdf->SetDrawColor(0, 0, 0);
    }

    private function drawFooter(Mpdf $mpdf, int $version): void
    {
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 8);
        $mpdf->SetTextColor(120, 120, 120);
        $mpdf->SetXY(WorksheetGeometry::CONTENT_LEFT + 12, WorksheetGeometry::FOOTER_Y);
        $mpdf->WriteCell(WorksheetGeometry::contentWidth() - 24, 4, 'EduVision · ใบงานเวอร์ชัน '.$version.' · เขียนคำตอบในกรอบเท่านั้น', 0, 0, 'C');
        $mpdf->SetTextColor(0, 0, 0);
    }

    private function drawQuestion(Mpdf $mpdf, PlacedQuestion $placed): void
    {
        $page = $mpdf->page;
        $mpdf->SetY($placed->top);
        $mpdf->WriteHTML($placed->promptHtml, HTMLParserMode::HTML_BODY);

        if ($mpdf->page !== $page || abs($mpdf->y - $placed->promptBottom()) > self::DRIFT_TOLERANCE_MM) {
            throw new RuntimeException(sprintf(
                'Worksheet render drifted from the layout at question %d (expected y %.3f, got %.3f on page %d/%d).',
                $placed->number, $placed->promptBottom(), $mpdf->y, $mpdf->page, $page,
            ));
        }

        $region = $placed->region();
        $this->withoutPageBreaks($mpdf, fn () => match ($region->kind) {
            'mcq' => $this->drawMcq($mpdf, $region),
            'box' => $this->drawBox($mpdf, $region->rect, 'ตอบ'),
            default => $this->drawLines($mpdf, $region),
        });
    }

    /**
     * Header, footer, labels and answer areas are drawn at fixed positions,
     * some of them below mPDF's page-break trigger (the footer, a label next
     * to a box that ends at the bottom of the flow area). Only the prompt text
     * flows; everything else must never start a new page.
     */
    private function withoutPageBreaks(Mpdf $mpdf, callable $draw): void
    {
        $previous = $mpdf->autoPageBreak;
        $mpdf->autoPageBreak = false;
        try {
            $draw();
        } finally {
            $mpdf->autoPageBreak = $previous;
        }
    }

    private function drawMcq(Mpdf $mpdf, RegionGeometry $region): void
    {
        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.3);
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, 'B', 12);
        foreach ($region->bubbles as $bubble) {
            $mpdf->SetXY($bubble['cx'] - WorksheetGeometry::MCQ_LABEL_TO_CENTRE - 2.5, $bubble['cy'] - 3);
            $mpdf->WriteCell(5, 6, $bubble['option'], 0, 0, 'C');
            $mpdf->Circle($bubble['cx'], $bubble['cy'], $bubble['r'], 'D');
        }
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     */
    private function drawBox(Mpdf $mpdf, array $rect, string $label): void
    {
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 12);
        $mpdf->SetXY($rect['x'] - 26, $rect['y']);
        $mpdf->WriteCell(24, $rect['h'], $label, 0, 0, 'R');

        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.35);
        $mpdf->Rect($rect['x'], $rect['y'], $rect['w'], $rect['h'], 'D');
    }

    private function drawLines(Mpdf $mpdf, RegionGeometry $region): void
    {
        $rect = $region->rect;
        $mpdf->SetDrawColor(0, 0, 0);
        $mpdf->SetLineWidth(0.35);
        $mpdf->Rect($rect['x'], $rect['y'], $rect['w'], $rect['h'], 'D');

        $ruleLeft = $rect['x'] + ($region->numbered ? 8.0 : 3.0);
        $ruleRight = $rect['x'] + $rect['w'] - 3.0;
        $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 10);
        $mpdf->SetTextColor(110, 110, 110);
        for ($i = 0; $i < (int) $region->lineCount; $i++) {
            $rowTop = $rect['y'] + $i * WorksheetGeometry::LINE_H;
            $baseline = $rowTop + WorksheetGeometry::LINE_H - 2.0;
            if ($region->numbered) {
                $mpdf->SetXY($rect['x'] + 1.0, $rowTop + 2.0);
                $mpdf->WriteCell(6, WorksheetGeometry::LINE_H - 4.0, (string) ($i + 1), 0, 0, 'C');
            }
            $mpdf->SetDrawColor(150, 150, 150);
            $mpdf->SetLineWidth(0.2);
            $mpdf->Line($ruleLeft, $baseline, $ruleRight, $baseline);
        }
        $mpdf->SetTextColor(0, 0, 0);
        $mpdf->SetDrawColor(0, 0, 0);

        if ($region->final !== null) {
            $this->drawBox($mpdf, $region->final, 'คำตอบ');
        }
    }

    private static function thaiDate(CarbonImmutable $date): string
    {
        $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $local = $date->setTimezone('Asia/Bangkok');

        return $local->day.' '.$months[$local->month - 1].' '.($local->year + 543);
    }
}
