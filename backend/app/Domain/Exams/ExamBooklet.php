<?php

namespace App\Domain\Exams;

use App\Domain\Worksheets\PromptHtml;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetMpdfFactory;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * The question booklet of one version (DESIGN §22.6 item 1): A4, one
 * column, Sarabun with Thai dictionary line breaking, no name, QR or
 * markers.
 *
 * - Page 1 opens with the exam title, the course (code and name), the time
 *   allowed, the number of questions, the full marks and the version code in
 *   large type; every section starts with its title and instructions.
 * - Questions are numbered and ordered as on that version's answer sheet,
 *   mcq options in that version's order with the labels ก ข ค … of their
 *   displayed position.
 * - A question is never split across pages: every block is typeset once on
 *   a scratch page to read its real height (as LayoutBuilder does, §5.1)
 *   and moves to the next page whole when it does not fit. A question taller
 *   than a page has its pictures scaled down until it fits.
 * - Pictures keep their aspect ratio: prompts within 120 × 80 mm, options at
 *   most 35 mm high. Options go four to a row when all are short (≤ 12
 *   characters, no picture), two when all are ≤ 35 characters, else one.
 * - Every page ends with "หน้า x/y ชุด ข".
 */
class ExamBooklet
{
    public const LEFT = 20.0;

    public const RIGHT = 190.0;

    public const TOP = 18.0;

    public const BOTTOM = 276.0;

    public const FOOTER_Y = 282.0;

    public const GAP = 4.0;

    /** Hanging indent of the question number (as on worksheets). */
    public const INDENT = 9.0;

    public const PROMPT_IMAGE_MAX_W = 120.0;

    public const PROMPT_IMAGE_MAX_H = 80.0;

    public const OPTION_IMAGE_MAX_H = 35.0;

    public const SHORT_OPTION = 12;

    public const MEDIUM_OPTION = 35;

    /** Picture scale factors tried in turn for a question taller than a page. */
    private const SHRINK = [1.0, 0.8, 0.64, 0.5, 0.4, 0.3, 0.2];

    private const CSS = <<<'CSS'
    div.bk-title { font-size: 18pt; font-weight: bold; }
    div.bk-sub { font-size: 13pt; }
    div.bk-ver { font-size: 26pt; font-weight: bold; text-align: center; }
    div.bk-note { font-size: 11.5pt; color: #333333; padding-top: 2mm; }
    div.bk-sec { font-size: 14pt; font-weight: bold; padding-top: 1mm; }
    div.bk-ins { font-size: 12.5pt; padding-bottom: 1.5mm; }
    div.opts { padding: 1mm 0 0 9mm; }
    table.opts { border-collapse: collapse; }
    table.opts td { font-size: 13pt; vertical-align: top; padding: 0.4mm 2mm 0.4mm 0; }
    CSS;

    public function __construct(private readonly WorksheetMpdfFactory $mpdfFactory) {}

    /**
     * @throws WorksheetLayoutException a question that does not fit a page even with its pictures scaled down
     */
    public function plan(Assignment $exam, ExamVersion $version): ExamBookletPlan
    {
        $exam->loadMissing(['course', 'subject']);
        $sections = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get()->keyBy('id');
        $questions = Question::query()->where('assignment_id', $exam->id)->with('options')->get()->keyBy('id');
        $ordered = collect($version->question_order)->map(fn ($id) => $questions->get((int) $id))->filter()->values();
        $label = $exam->version_count > 1 ? ExamVersions::label($version->version_no) : null;

        $measurer = $this->mpdf();
        $blocks = [[
            'html' => fn (float $f) => self::coverHtml($exam, $ordered, $label),
            'numbers' => [],
        ]];
        $orders = $version->option_orders;
        $seen = [];
        foreach ($ordered as $index => $question) {
            /** @var Question $question */
            $number = $index + 1;
            $section = $sections->get($question->section_id);
            $heading = '';
            if ($section instanceof ExamSection && ! isset($seen[$section->id])) {
                $seen[$section->id] = true;
                $heading = self::sectionHtml($section, $ordered, $index);
            }
            $order = isset($orders[$question->id]) ? array_map('intval', $orders[$question->id]) : null;
            $blocks[] = [
                'html' => fn (float $f) => $heading.self::questionHtml($question, $section, $number, $order, $f),
                'numbers' => [$number],
            ];
        }

        $pages = [];
        $current = [];
        $y = self::TOP;
        foreach ($blocks as $block) {
            [$html, $height] = $this->fit($measurer, $block['html'], $block['numbers'][0] ?? 0);
            if ($current !== [] && $y + $height > self::BOTTOM) {
                $pages[] = $current;
                $current = [];
                $y = self::TOP;
            }
            $current[] = ['top' => round($y, 3), 'height' => $height, 'html' => $html, 'numbers' => $block['numbers']];
            $y += $height + self::GAP;
        }
        $pages[] = $current;

        return new ExamBookletPlan($pages, $label);
    }

    /**
     * @return string PDF bytes
     */
    public function render(Assignment $exam, ExamVersion $version, ?ExamBookletPlan $plan = null): string
    {
        $plan ??= $this->plan($exam, $version);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle($exam->title.($plan->versionLabel !== null ? ' ชุด '.$plan->versionLabel : ''));
        $mpdf->SetCreator('EduVision');

        foreach ($plan->pages as $index => $blocks) {
            $mpdf->AddPage();
            $page = $mpdf->page;
            $this->drawFooter($mpdf, $plan->footer($index + 1));
            foreach ($blocks as $block) {
                $mpdf->SetY($block['top']);
                $mpdf->WriteHTML($block['html'], HTMLParserMode::HTML_BODY);
                if ($mpdf->page !== $page) {
                    throw new RuntimeException(sprintf('Booklet block of question(s) %s ran onto the next page.', implode(', ', $block['numbers'])));
                }
            }
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function mpdf(): Mpdf
    {
        $mpdf = $this->mpdfFactory->create([
            'margin_left' => self::LEFT,
            'margin_right' => 210.0 - self::RIGHT,
            'margin_top' => self::TOP,
            'margin_bottom' => 297.0 - self::BOTTOM,
            'shrink_tables_to_fit' => 0,
        ]);
        $mpdf->WriteHTML(self::CSS, HTMLParserMode::HEADER_CSS);

        return $mpdf;
    }

    /**
     * Typesets a block on a scratch page; a block taller than a page is
     * tried again with smaller pictures.
     *
     * @param  callable(float): string  $html
     * @return array{0: string, 1: float}
     */
    private function fit(Mpdf $measurer, callable $html, int $number): array
    {
        foreach (self::SHRINK as $factor) {
            $markup = $html($factor);
            $measurer->AddPage();
            $page = $measurer->page;
            $measurer->SetY(self::TOP);
            $measurer->WriteHTML($markup, HTMLParserMode::HTML_BODY);
            if ($measurer->page === $page) {
                return [$markup, round($measurer->y - self::TOP, 3)];
            }
        }

        throw new WorksheetLayoutException("ข้อ {$number} ยาวเกินหนึ่งหน้าของเล่มข้อสอบ ทำโจทย์ให้สั้นลง", $number);
    }

    private function drawFooter(Mpdf $mpdf, string $text): void
    {
        $previous = $mpdf->autoPageBreak;
        $mpdf->autoPageBreak = false;
        try {
            $mpdf->SetFont(WorksheetMpdfFactory::FONT, '', 11);
            $mpdf->SetTextColor(0, 0, 0);
            $mpdf->SetDrawColor(120, 120, 120);
            $mpdf->SetLineWidth(0.2);
            $mpdf->Line(self::LEFT, self::FOOTER_Y - 2, self::RIGHT, self::FOOTER_Y - 2);
            $mpdf->SetXY(self::LEFT, self::FOOTER_Y);
            $mpdf->WriteCell(self::RIGHT - self::LEFT, 5, $text, 0, 0, 'C');
        } finally {
            $mpdf->autoPageBreak = $previous;
        }
    }

    /**
     * @param  Collection<int, Question>  $ordered
     */
    private static function coverHtml(Assignment $exam, Collection $ordered, ?string $label): string
    {
        $course = $exam->course !== null
            ? trim(($exam->course->code ?? '').' '.$exam->course->name)
            : ($exam->subject->name ?? '');
        $facts = [];
        if ($exam->duration_minutes !== null) {
            $facts[] = 'เวลา '.$exam->duration_minutes.' นาที';
        }
        $facts[] = $ordered->count().' ข้อ';
        $facts[] = 'คะแนนเต็ม '.PromptHtml::points((float) $ordered->sum(fn (Question $q) => (float) $q->max_points)).' คะแนน';

        $details = '<div class="bk-title">'.self::e($exam->title).'</div>'
            .($course !== '' ? '<div class="bk-sub">รายวิชา '.self::e($course).'</div>' : '')
            .'<div class="bk-sub">'.implode('&nbsp;&nbsp;·&nbsp;&nbsp;', $facts).'</div>';

        $html = $label === null
            ? $details
            : '<table width="100%" style="border-collapse: collapse;"><tr>'
                .'<td style="width: 130mm; vertical-align: top;">'.$details.'</td>'
                .'<td style="width: 40mm; vertical-align: middle; text-align: center; border: 0.6mm solid #000000; padding: 2mm;"><div class="bk-ver">ชุด '.self::e($label).'</div></td>'
                .'</tr></table>';

        return $html.'<div class="bk-note">ฝนคำตอบในกระดาษคำตอบ'.($label !== null ? ' และฝนวงชุดข้อสอบให้ตรงกับเล่มนี้' : '').'</div>';
    }

    /**
     * Title, number range, points per question when they are all equal,
     * and the instructions of a section.
     *
     * @param  Collection<int, Question>  $ordered
     */
    private static function sectionHtml(ExamSection $section, Collection $ordered, int $firstIndex): string
    {
        $last = $firstIndex;
        while ($last + 1 < $ordered->count() && $ordered[$last + 1]->section_id === $section->id) {
            $last++;
        }
        $points = $ordered->slice($firstIndex, $last - $firstIndex + 1)->map(fn (Question $q) => PromptHtml::points((float) $q->max_points))->unique();

        $title = trim((string) $section->title) !== '' ? (string) $section->title : 'ตอนที่ '.$section->position;
        $range = $last > $firstIndex ? 'ข้อ '.($firstIndex + 1).'–'.($last + 1) : 'ข้อ '.($firstIndex + 1);
        $meta = $range.($points->count() === 1 ? ' ข้อละ '.$points->first().' คะแนน' : '');

        $html = '<div class="bk-sec">'.self::e($title).' <span style="font-weight: normal; font-size: 12pt;">('.$meta.')</span></div>';
        if (trim((string) $section->instructions) !== '') {
            $html .= '<div class="bk-ins">'.self::text((string) $section->instructions).'</div>';
        }

        return $html;
    }

    /**
     * @param  list<int>|null  $order  original option position by displayed position, null = not shuffled
     */
    private static function questionHtml(Question $question, ?ExamSection $section, int $number, ?array $order, float $scale): string
    {
        $points = '';
        if ($section !== null && abs((float) $question->max_points - (float) $section->default_points) > 0.001) {
            $points = ' <span class="pts">('.PromptHtml::points((float) $question->max_points).' คะแนน)</span>';
        }
        $html = '<div class="q" lang="th">'.$number.'.&nbsp;&nbsp;'.self::text((string) $question->prompt_text).$points.'</div>';

        $image = self::image($question->prompt_image_path, self::PROMPT_IMAGE_MAX_W * $scale, self::PROMPT_IMAGE_MAX_H * $scale);
        if ($image !== null) {
            $html .= '<div class="qimg">'.$image.'</div>';
        }

        if ($question->type === Question::TYPE_MCQ) {
            $html .= self::optionsHtml($question, $order, $scale);
        }

        return $html;
    }

    /**
     * @param  list<int>|null  $order
     */
    private static function optionsHtml(Question $question, ?array $order, float $scale): string
    {
        $byPosition = $question->options->keyBy('position');
        $count = $order !== null ? count($order) : $byPosition->count();
        if ($count === 0) {
            return '';
        }
        $order ??= range(1, $count);
        $options = array_map(fn (int $p) => $byPosition->get($p), $order);

        $perRow = self::perRow($options);
        $cellWidth = (self::RIGHT - self::LEFT - self::INDENT) / $perRow;
        $cells = [];
        foreach ($options as $index => $option) {
            /** @var QuestionOption|null $option */
            $cell = QuestionOption::label($index + 1).'.&nbsp;'.self::text((string) $option?->text);
            $image = self::image($option?->image_path, $cellWidth - 8, self::OPTION_IMAGE_MAX_H * $scale);
            if ($image !== null) {
                $cell .= '<br />'.$image;
            }
            $cells[] = '<td style="width: '.round($cellWidth, 2).'mm;">'.$cell.'</td>';
        }

        $rows = '';
        foreach (array_chunk($cells, $perRow) as $chunk) {
            $rows .= '<tr>'.implode('', $chunk).str_repeat('<td style="width: '.round($cellWidth, 2).'mm;"></td>', $perRow - count($chunk)).'</tr>';
        }

        return '<div class="opts"><table class="opts">'.$rows.'</table></div>';
    }

    /**
     * 4 to a row when every option is short and has no picture, 2 when all
     * are at most 35 characters, else 1 (DESIGN §22.6).
     *
     * @param  list<QuestionOption|null>  $options
     */
    public static function perRow(array $options): int
    {
        $lengths = array_map(fn (?QuestionOption $o) => mb_strlen(trim((string) $o?->text)), $options);
        $pictures = array_filter($options, fn (?QuestionOption $o) => $o?->image_path !== null);
        $longest = $lengths === [] ? 0 : max($lengths);

        if ($pictures === [] && $longest <= self::SHORT_OPTION) {
            return 4;
        }

        return $longest <= self::MEDIUM_OPTION ? 2 : 1;
    }

    /** A stored picture scaled to fit $maxW × $maxH mm with its aspect ratio kept. */
    private static function image(?string $relative, float $maxW, float $maxH): ?string
    {
        if ($relative === null || $relative === '' || ! Storage::disk('local')->exists($relative)) {
            return null;
        }
        $path = Storage::disk('local')->path($relative);
        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }
        $scale = min($maxW / $size[0], $maxH / $size[1]);

        return '<img src="'.self::e($path).'" width="'.round($size[0] * $scale, 2).'mm" height="'.round($size[1] * $scale, 2).'mm" />';
    }

    private static function text(string $text): string
    {
        return nl2br(self::e(str_replace(["\r\n", "\r"], "\n", trim($text))), false);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
