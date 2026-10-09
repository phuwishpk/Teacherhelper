<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamSheetLayout;
use App\Domain\Exams\ExamSheetPdfRenderer;
use App\Domain\Worksheets\ArucoMarkers;
use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetMpdfFactory;
use App\Domain\Worksheets\WorksheetStudent;
use App\Models\Assignment;
use App\Models\Layout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Tests\Support\PdfStreams;
use Tests\TestCase;

/**
 * DESIGN §22.6–§22.8: answer sheets have 1–2 pages per student with the
 * signed EVX1 QR on every page (student 0 on the key sheet), and every
 * bubble of the layout JSON is drawn exactly where the layout says.
 */
class ExamSheetPdfTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private const KEY = 'testing-qr-signing-key-not-a-secret';

    /** @var list<string> */
    private array $signed = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
        // The factory picks a random room name; a fixed one keeps the PDF bytes the same on every run.
        $this->classroom->update(['name' => 'ป.5/2']);
    }

    private function renderer(): ExamSheetPdfRenderer
    {
        $signed = &$this->signed;
        $signer = new class(self::KEY, $signed) extends QrSigner
        {
            /** @param list<string> $log */
            public function __construct(string $key, private array &$log)
            {
                parent::__construct($key);
            }

            public function signExamSheet(int $assignmentId, int $studentId, int $page, int $layoutVersion): string
            {
                return $this->log[] = parent::signExamSheet($assignmentId, $studentId, $page, $layoutVersion);
            }

            public function signCodeSheet(int $assignmentId, int $page, int $layoutVersion): string
            {
                return $this->log[] = parent::signCodeSheet($assignmentId, $page, $layoutVersion);
            }
        };

        return new ExamSheetPdfRenderer(app(WorksheetMpdfFactory::class), $signer);
    }

    private function layoutOf(Assignment $exam, int $version = 1): Layout
    {
        return new Layout([
            'version' => $version,
            'pages' => ExamSheetLayout::forExam($exam)->toLayoutPages($exam->id, $version, ArucoMarkers::load()),
        ]);
    }

    /**
     * Start points of every circle mPDF drew, per page: Ellipse() begins
     * with "x y m" at (cx + r, cy) followed by a Bézier "c".
     *
     * @return list<list<array{float, float}>> points in PDF units, per page
     */
    private function circleStarts(string $pdf): array
    {
        $pages = [];
        foreach (PdfStreams::decoded($pdf) as $content) {
            if (! str_contains($content, ' re') && ! str_contains($content, ' c')) {
                continue;
            }
            preg_match_all('/(-?\d+\.\d+) (-?\d+\.\d+) m (-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) c/', $content, $m, PREG_SET_ORDER);
            if ($m !== []) {
                $pages[] = array_map(fn (array $r) => [(float) $r[1], (float) $r[2]], $m);
            }
        }

        return $pages;
    }

    public function test_every_student_gets_every_page_with_a_signed_evx1_qr(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 5, 'question_count' => 90]);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 20]);
        $this->fillExam($exam);
        $layout = $this->layoutOf($exam, 3);
        $this->assertCount(2, $layout->pages, '110 bubble rows need two pages');

        $pdf = $this->renderer()->render($exam, $layout, [
            new WorksheetStudent(4001, 'เด็กหญิงสมใจ ใจดี', 1),
            new WorksheetStudent(4002, 'เด็กชายสมชาย รักเรียน', 2),
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(4, PdfStreams::pageCount($pdf));
        $this->assertStringContainsString('Sarabun', $pdf);
        $signer = new QrSigner(self::KEY);
        $this->assertSame([
            $signer->signExamSheet($exam->id, 4001, 1, 3),
            $signer->signExamSheet($exam->id, 4001, 2, 3),
            $signer->signExamSheet($exam->id, 4002, 1, 3),
            $signer->signExamSheet($exam->id, 4002, 2, 3),
        ], $this->signed);
        foreach ($this->signed as $payload) {
            $this->assertStringStartsWith('EVX1.', $payload);
            $this->assertNull($signer->verify($payload), 'an answer-sheet QR is not a worksheet QR');
        }
    }

    public function test_the_key_sheet_is_student_zero_with_the_same_layout(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 10]);

        $pdf = $this->renderer()->render($exam, $this->layoutOf($exam), [null]);

        $this->assertSame(1, PdfStreams::pageCount($pdf));
        $this->assertSame([(new QrSigner(self::KEY))->signExamSheet($exam->id, 0, 1, 1)], $this->signed);
    }

    /** DESIGN §22.19: one sheet for everyone, the student named by the grid on every page. */
    public function test_the_shared_sheet_has_an_evc1_qr_on_every_page_and_no_student(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 70]);
        $this->fillExam($exam);
        $layout = $this->layoutOf($exam, 2);
        $this->assertCount(2, $layout->pages, '70 rows need two pages below the grid');

        $pdf = $this->renderer()->render($exam, $layout, [ExamSheetPdfRenderer::SHARED_SHEET]);

        $this->assertSame(2, PdfStreams::pageCount($pdf));
        $signer = new QrSigner(self::KEY);
        $this->assertSame([$signer->signCodeSheet($exam->id, 1, 2), $signer->signCodeSheet($exam->id, 2, 2)], $this->signed);
        $this->assertNull($signer->verifyExamSheet($this->signed[0]), 'a shared sheet is not a sheet printed for a student');
    }

    public function test_the_student_id_grid_is_drawn_where_the_layout_says(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 13, 'version_count' => 2]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 10]);
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_negative' => false, 'allow_decimal' => false], 'question_count' => 1]);

        // 10 × 4 row bubbles, 2 version bubbles, 13 ID columns × 10 and one block of 2 columns × 10.
        $this->assertSame(40 + 2 + 130 + 20, $this->assertBubblesDrawn($exam));
    }

    public function test_the_key_sheet_of_a_shared_sheet_exam_keeps_the_grid_and_the_evx1_qr(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 6]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 5]);

        $pages = $this->circleStarts($this->renderer()->render($exam, $this->layoutOf($exam), [null]));

        $this->assertSame([(new QrSigner(self::KEY))->signExamSheet($exam->id, 0, 1, 1)], $this->signed);
        // The rows, the grid, and the two example circles of the footer.
        $this->assertCount(5 * 4 + 6 * 10 + 2, $pages[0]);
    }

    public function test_every_bubble_is_drawn_where_the_layout_says(): void
    {
        $exam = $this->createExam(['version_count' => 3]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 6, 'question_count' => 30]);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 5]);
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 3, 'allow_negative' => true, 'allow_decimal' => true], 'question_count' => 5]);

        // 30 × 6 + 5 × 2 row bubbles, 3 version bubbles, 5 blocks × (sign + 4 columns × 11).
        $this->assertSame(180 + 10 + 3 + 5 * (1 + 4 * 11), $this->assertBubblesDrawn($exam));
    }

    public function test_sixty_mcq_fill_page_one_in_order_and_the_numeric_questions_follow(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 60]);
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_negative' => false, 'allow_decimal' => false], 'question_count' => 5]);
        $layout = $this->layoutOf($exam);

        $numbers = fn (array $page, string $kind) => array_values(array_map(
            fn (array $r) => $r['sheet_no'],
            array_filter($page['regions'], fn (array $r) => $r['kind'] === $kind),
        ));
        $this->assertCount(2, $layout->pages);
        $this->assertSame(range(1, 60), $numbers($layout->pages[0], 'omr_row'));
        $this->assertSame([], $numbers($layout->pages[0], 'digit_block'));
        $this->assertSame([], $numbers($layout->pages[1], 'omr_row'));
        $this->assertSame(range(61, 65), $numbers($layout->pages[1], 'digit_block'));

        $this->assertSame(60 * 4 + 5 * 2 * 10, $this->assertBubblesDrawn($exam));
    }

    /**
     * Renders the exam for one student and checks that every bubble of its
     * layout JSON is drawn at the place the layout gives, on the right page.
     *
     * @return int the number of bubbles checked
     */
    private function assertBubblesDrawn(Assignment $exam): int
    {
        $layout = $this->layoutOf($exam);
        $pages = $this->circleStarts($this->renderer()->render($exam, $layout, [new WorksheetStudent(1, 'ทดสอบ', 1)]));
        $this->assertCount(count($layout->pages), $pages);

        $k = Mpdf::SCALE;
        $checked = 0;
        foreach ($layout->pages as $index => $page) {
            $circles = [];
            foreach ($page['regions'] as $region) {
                array_push($circles, ...($region['bubbles'] ?? []));
                if (($region['sign'] ?? null) !== null) {
                    $circles[] = $region['sign'];
                }
                foreach ($region['columns'] ?? [] as $column) {
                    array_push($circles, ...$column['bubbles']);
                }
            }
            foreach ($circles as $c) {
                // Frame-relative (0–1) -> page mm -> PDF points (origin bottom-left); r is relative to the frame width.
                $x = (16 + $c['cx'] * 178 + $c['r'] * 178) * $k;
                $y = (297 - (16 + $c['cy'] * 265)) * $k;
                $hit = collect($pages[$index])->first(fn (array $p) => abs($p[0] - $x) < 0.1 && abs($p[1] - $y) < 0.2);
                $this->assertNotNull($hit, sprintf('bubble at (%.4f, %.4f) on page %d is drawn', $c['cx'], $c['cy'], $index + 1));
                $checked++;
            }
        }

        return $checked;
    }

    public function test_it_refuses_to_print_from_a_layout_the_exam_no_longer_matches(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $layout = $this->layoutOf($exam, 2);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);

        $this->expectException(WorksheetLayoutException::class);
        $this->expectExceptionMessage('เวอร์ชัน 2');
        $this->renderer()->render($exam, $layout, [new WorksheetStudent(1, 'ทดสอบ', 1)]);
    }
}
