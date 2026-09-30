<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamBooklet;
use App\Domain\Exams\ExamVersions;
use App\Domain\Worksheets\PdfMerger;
use App\Models\Assignment;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.6 item 1: one booklet per version, numbered and ordered as that
 * version, "หน้า x/y ชุด ข" on every page, no question split across pages,
 * pictures scaled (a question taller than a page shrinks its pictures), and
 * options four, two or one to a row.
 */
class ExamBookletTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
    }

    private function version(Assignment $exam, int $no): ExamVersion
    {
        ExamVersions::sync($exam->refresh());

        return ExamVersion::query()->where('assignment_id', $exam->id)->where('version_no', $no)->firstOrFail();
    }

    private function pageCount(string $pdf): int
    {
        $path = tempnam(sys_get_temp_dir(), 'bk').'.pdf';
        file_put_contents($path, $pdf);
        try {
            return PdfMerger::pageCount($path);
        } finally {
            @unlink($path);
        }
    }

    /** A PNG of $w × $h pixels on the fake disk. */
    private function picture(string $path, int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        ob_start();
        imagepng($image);
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return $path;
    }

    public function test_every_page_names_the_version_and_no_question_is_split(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 45, 'title' => 'ตอนที่ 1 ปรนัย', 'instructions' => 'เลือกคำตอบที่ถูกที่สุด']);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 10]);
        $this->fillExam($exam);
        Question::query()->where('assignment_id', $exam->id)->where('position', 7)
            ->update(['prompt_text' => str_repeat('โจทย์ยาวมากเพื่อให้ขึ้นหลายบรรทัด ', 30)]);
        $version = $this->version($exam, 2);

        $booklet = app(ExamBooklet::class);
        $plan = $booklet->plan($exam->refresh(), $version);

        $this->assertGreaterThan(2, $plan->pageCount());
        $this->assertSame('ข', $plan->versionLabel);
        for ($page = 1; $page <= $plan->pageCount(); $page++) {
            $this->assertSame("หน้า {$page}/{$plan->pageCount()}   ชุด ข", $plan->footer($page));
        }
        // Every number of the version exactly once, in order, each block whole inside one page.
        $numbers = [];
        foreach ($plan->pages as $blocks) {
            $bottom = ExamBooklet::TOP;
            foreach ($blocks as $block) {
                $this->assertGreaterThanOrEqual($bottom, $block['top'] + 0.001, 'blocks do not overlap');
                $this->assertLessThanOrEqual(ExamBooklet::BOTTOM, $block['top'] + $block['height']);
                $bottom = $block['top'] + $block['height'];
                array_push($numbers, ...$block['numbers']);
            }
        }
        $this->assertSame(range(1, 55), $numbers);
        // The booklet follows the version's order: number n shows the prompt of question_order[n - 1].
        $questions = Question::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
        $first = $questions[$version->question_order[0]];
        $this->assertStringContainsString('1.&nbsp;&nbsp;ข้อใดถูกต้องสำหรับโจทย์ข้อที่ '.$first->position.'<', $plan->pages[0][1]['html']);
        $this->assertStringContainsString('ตอนที่ 1 ปรนัย', $plan->pages[0][1]['html'], 'the section heading stays with its first question');
        $this->assertStringContainsString('ชุด ข', $plan->pages[0][0]['html'], 'the cover names the version');

        $pdf = $booklet->render($exam, $version, $plan);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame($plan->pageCount(), $this->pageCount($pdf));
    }

    public function test_options_follow_the_version_order_with_labels_of_their_displayed_position(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $section = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);
        $this->fillExam($exam);
        $id = $section['questions'][0]['id'];
        $version = $this->version($exam, 2);
        $order = $version->option_orders[$id];

        $html = app(ExamBooklet::class)->plan($exam->refresh(), $version)->pages[0][1]['html'];

        foreach ($order as $displayed => $original) {
            $this->assertStringContainsString(QuestionOption::label($displayed + 1).'.&nbsp;ตัวเลือก '.$original.'<', $html);
        }
    }

    public function test_one_version_has_no_version_code(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2], 'question_count' => 2]);
        $this->fillExam($exam);

        $plan = app(ExamBooklet::class)->plan($exam->refresh(), $this->version($exam, 1));

        $this->assertNull($plan->versionLabel);
        $this->assertSame('หน้า 1/1', $plan->footer(1));
        $this->assertStringNotContainsString('ชุด ', $plan->pages[0][0]['html']);
    }

    public function test_pictures_are_scaled_and_a_question_taller_than_a_page_shrinks_them(): void
    {
        $exam = $this->createExam();
        $section = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 6, 'question_count' => 2]);
        $this->fillExam($exam);
        [$small, $tall] = array_column($section['questions'], 'id');

        // Question 1: a wide prompt picture fits 120 × 80 mm.
        Question::query()->whereKey($small)->update(['prompt_image_path' => $this->picture('exams/q1.png', 1200, 300)]);
        // Question 2: six long options with tall pictures (6 × 35 mm) and a tall prompt picture exceed a page.
        Question::query()->whereKey($tall)->update(['prompt_image_path' => $this->picture('exams/q2.png', 300, 900)]);
        foreach (QuestionOption::query()->where('question_id', $tall)->get() as $option) {
            $option->update([
                'text' => 'ตัวเลือกที่ยาวกว่าสามสิบห้าตัวอักษรเพื่อให้วางแถวละหนึ่งตัวเลือก '.$option->position,
                'image_path' => $this->picture("exams/o{$option->id}.png", 400, 400),
            ]);
        }

        $plan = app(ExamBooklet::class)->plan($exam->refresh(), $this->version($exam, 1));

        $html = collect($plan->pages)->flatten(1)->keyBy(fn (array $b) => $b['numbers'][0] ?? 0);
        $this->assertStringContainsString('width="120mm" height="30mm"', $html[1]['html']);
        preg_match_all('/height="([\d.]+)mm"/', $html[2]['html'], $heights);
        $this->assertCount(7, $heights[1]);
        $this->assertLessThan(80.0, (float) $heights[1][0], 'the prompt picture was scaled down');
        foreach (array_slice($heights[1], 1) as $h) {
            $this->assertLessThan(35.0, (float) $h, 'option pictures were scaled down');
        }
        $this->assertLessThanOrEqual(ExamBooklet::BOTTOM - ExamBooklet::TOP, $html[2]['height']);

        $this->assertSame($plan->pageCount(), $this->pageCount(app(ExamBooklet::class)->render($exam, $this->version($exam, 1), $plan)));
    }

    public function test_options_go_four_two_or_one_to_a_row(): void
    {
        $options = fn (array $texts, bool $picture = false) => array_map(
            fn (string $t) => new QuestionOption(['text' => $t, 'image_path' => $picture ? 'x.jpg' : null]),
            $texts,
        );

        $this->assertSame(4, ExamBooklet::perRow($options(['12', 'สิบห้า', '17', 'ยี่สิบเอ็ด'])));
        $this->assertSame(4, ExamBooklet::perRow($options([str_repeat('ก', 12), '', '', ''])));
        $this->assertSame(2, ExamBooklet::perRow($options([str_repeat('ก', 13), 'ข'])));
        $this->assertSame(2, ExamBooklet::perRow($options(['สั้น', 'สั้น'], true)), 'a picture rules out four to a row');
        $this->assertSame(2, ExamBooklet::perRow($options([str_repeat('ก', 35)])));
        $this->assertSame(1, ExamBooklet::perRow($options([str_repeat('ก', 36), 'ข'])));
    }
}
