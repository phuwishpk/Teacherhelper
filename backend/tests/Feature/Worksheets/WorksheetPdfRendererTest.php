<?php

namespace Tests\Feature\Worksheets;

use App\Domain\Worksheets\LayoutBuilder;
use App\Domain\Worksheets\PdfMerger;
use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetMpdfFactory;
use App\Domain\Worksheets\WorksheetPdfRenderer;
use App\Domain\Worksheets\WorksheetStudent;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Layout;
use App\Models\Subject;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * DESIGN §5.2, §5.5: the PDF has one copy per student with every page of the
 * layout, the signed QR per page, Sarabun, and answer areas drawn exactly at
 * the layout coordinates the phone will crop with.
 */
class WorksheetPdfRendererTest extends TestCase
{
    use WorksheetFixtures;

    /** @var list<string> */
    private array $signed = [];

    private function renderer(): WorksheetPdfRenderer
    {
        $signed = &$this->signed;
        $signer = new class('testing-qr-signing-key-not-a-secret', $signed) extends QrSigner
        {
            /** @param list<string> $log */
            public function __construct(string $key, private array &$log)
            {
                parent::__construct($key);
            }

            public function sign(int $assignmentId, int $studentId, int $page, int $layoutVersion): string
            {
                return $this->log[] = parent::sign($assignmentId, $studentId, $page, $layoutVersion);
            }
        };

        return new WorksheetPdfRenderer(app(WorksheetMpdfFactory::class), app(LayoutBuilder::class), $signer);
    }

    private function withHeaderRelations(Assignment $assignment): Assignment
    {
        $assignment->setRelation('classroom', new Classroom(['name' => 'ป.5/2']));
        $assignment->setRelation('subject', new Subject(['name' => 'คณิตศาสตร์']));

        return $assignment;
    }

    private function pageCount(string $pdf): int
    {
        $path = tempnam(sys_get_temp_dir(), 'ws').'.pdf';
        file_put_contents($path, $pdf);
        try {
            return PdfMerger::pageCount($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return list<array{float, float, float, float}> every `re` operator in the page streams, in points */
    private function rectangles(string $pdf): array
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);
        $rects = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false) {
                $content = $stream;
            }
            preg_match_all('/(-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) (-?\d+\.\d+) re/', $content, $m, PREG_SET_ORDER);
            foreach ($m as $r) {
                $rects[] = [(float) $r[1], (float) $r[2], (float) $r[3], (float) $r[4]];
            }
        }

        return $rects;
    }

    public function test_it_renders_every_layout_page_for_every_student_with_signed_qrs(): void
    {
        $questions = [];
        for ($i = 0; $i < 6; $i++) {
            $questions[] = $this->question('show_work', ['answer_lines' => 5]);
        }
        $assignment = $this->withHeaderRelations($this->assignmentWith($questions, 77));
        $layout = new Layout(['version' => 3, 'pages' => app(LayoutBuilder::class)->build($assignment, 3)]);
        $pageCount = count($layout->pages);
        $this->assertGreaterThan(1, $pageCount);

        $pdf = $this->renderer()->render($assignment, $layout, [
            new WorksheetStudent(4001, 'เด็กหญิงสมใจ ใจดี', 1),
            new WorksheetStudent(4002, 'เด็กชายสมชาย รักเรียน', 2),
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2 * $pageCount, $this->pageCount($pdf));
        $this->assertStringContainsString('Sarabun', $pdf, 'the Sarabun font is embedded');

        $expected = [];
        foreach ([4001, 4002] as $student) {
            for ($page = 1; $page <= $pageCount; $page++) {
                $expected[] = (new QrSigner('testing-qr-signing-key-not-a-secret'))->sign(77, $student, $page, 3);
            }
        }
        $this->assertSame($expected, $this->signed);
    }

    public function test_answer_boxes_are_drawn_exactly_where_the_layout_says(): void
    {
        $short = $this->question('short');
        $work = $this->question('show_work');
        $assignment = $this->withHeaderRelations($this->assignmentWith([$this->question('mcq'), $short, $work]));
        $layout = new Layout(['version' => 1, 'pages' => app(LayoutBuilder::class)->build($assignment, 1)]);

        $rects = $this->rectangles($this->renderer()->render($assignment, $layout, [new WorksheetStudent(1, 'ทดสอบ', 1)]));

        $regions = collect($layout->pages[0]['regions'])->keyBy('question_id');
        $targets = [
            'short box' => $regions[$short->id]['rect'],
            'show_work lines' => $regions[$work->id]['rect'],
            'show_work final' => $regions[$work->id]['final_answer']['rect'],
        ];
        $k = Mpdf::SCALE;
        foreach ($targets as $name => $rect) {
            // Frame-relative (0–1) -> page mm -> PDF points (origin bottom-left).
            $x = (16 + $rect['x'] * 178) * $k;
            $y = (297 - (16 + $rect['y'] * 265)) * $k;
            $w = $rect['w'] * 178 * $k;
            $h = -$rect['h'] * 265 * $k;
            $match = collect($rects)->first(fn (array $r) => abs($r[0] - $x) < 0.1 && abs($r[1] - $y) < 0.1
                && abs($r[2] - $w) < 0.1 && abs($r[3] - $h) < 0.1);
            $this->assertNotNull($match, "{$name} is drawn at the layout rectangle");
        }
    }

    public function test_a_null_student_prints_the_anonymous_spare_sheet(): void
    {
        $assignment = $this->withHeaderRelations($this->assignmentWith([$this->question('mcq')], 9));
        $layout = new Layout(['version' => 1, 'pages' => app(LayoutBuilder::class)->build($assignment, 1)]);

        $this->renderer()->render($assignment, $layout, [null]);

        $this->assertSame([(new QrSigner('testing-qr-signing-key-not-a-secret'))->sign(9, 0, 1, 1)], $this->signed);
    }

    public function test_it_refuses_to_print_when_the_questions_no_longer_match_the_layout(): void
    {
        $question = $this->question('open', ['answer_lines' => 3]);
        $assignment = $this->withHeaderRelations($this->assignmentWith([$question]));
        $layout = new Layout(['version' => 4, 'pages' => app(LayoutBuilder::class)->build($assignment, 4)]);

        $question->answer_lines = 6; // edited after the layout was built

        $this->expectException(WorksheetLayoutException::class);
        $this->expectExceptionMessage('เวอร์ชัน 4');
        $this->renderer()->render($assignment, $layout, [new WorksheetStudent(1, 'ทดสอบ', 1)]);
    }
}
