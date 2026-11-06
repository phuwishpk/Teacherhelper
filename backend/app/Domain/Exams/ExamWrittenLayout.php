<?php

namespace App\Domain\Exams;

use App\Domain\Worksheets\RegionGeometry;
use App\Domain\Worksheets\WorksheetGeometry;
use App\Models\Assignment;
use App\Models\Question;

/**
 * Where the written questions of an exam sit on the written pages of its
 * answer sheet (DESIGN §22.20.3). One block per question: a label "ข้อ n
 * (x คะแนน)" of LABEL_H, then the answer area of the worksheet
 * (RegionGeometry: lines for `open`, lines and a final-answer box for
 * `show_work`), blocks QUESTION_GAP apart, top-down in the content area. A
 * block that does not fit the rest of the page starts the next one; it is
 * never split. The prompt is not printed (it is in the booklet).
 */
final class ExamWrittenLayout
{
    /** Height of the "ข้อ n (x คะแนน)" label above a box, mm. */
    public const LABEL_H = 7.0;

    /**
     * @return list<array{question: Question, number: int, points: float, label_top: float, region: RegionGeometry, page_index: int}>
     */
    public static function blocks(Assignment $exam): array
    {
        $questions = Question::query()
            ->where('assignment_id', $exam->id)
            ->whereIn('type', [Question::TYPE_OPEN, Question::TYPE_SHOW_WORK])
            ->orderBy('position')
            ->get();

        $blocks = [];
        $page = 0;
        $top = WorksheetGeometry::CONTENT_TOP;
        foreach ($questions as $question) {
            $height = self::LABEL_H + RegionGeometry::heightFor($question);
            if ($top > WorksheetGeometry::CONTENT_TOP && $top + $height > WorksheetGeometry::CONTENT_BOTTOM) {
                $page++;
                $top = WorksheetGeometry::CONTENT_TOP;
            }
            $blocks[] = [
                'question' => $question,
                'number' => (int) $question->position,
                'points' => (float) $question->max_points,
                'label_top' => $top,
                'region' => RegionGeometry::at($question, $top + self::LABEL_H),
                'page_index' => $page,
            ];
            $top += $height + WorksheetGeometry::QUESTION_GAP;
        }

        return $blocks;
    }

    /** Written pages the exam needs, 0 without written questions. */
    public static function pageCount(Assignment $exam): int
    {
        $blocks = self::blocks($exam);

        return $blocks === [] ? 0 : $blocks[array_key_last($blocks)]['page_index'] + 1;
    }
}
