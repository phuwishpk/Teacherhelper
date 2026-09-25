<?php

namespace App\Domain\Worksheets;

use App\Models\Question;
use InvalidArgumentException;

/**
 * The answer area of one question in page millimetres: what the renderer
 * draws and, normalised, what the layout JSON gives the phone to crop
 * (DESIGN §5.3). kinds: `mcq` (bubbles A–D), `box` (short answer),
 * `lines` (show_work numbered lines + final answer box, or open lines).
 */
final readonly class RegionGeometry
{
    /**
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @param  list<array{option: string, cx: float, cy: float, r: float}>  $bubbles
     * @param  array{x: float, y: float, w: float, h: float}|null  $final
     */
    private function __construct(
        public string $kind,
        public array $rect,
        public array $bubbles = [],
        public ?int $lineCount = null,
        public bool $numbered = false,
        public ?array $final = null,
        public bool $numeric = false,
    ) {}

    /** Height of the answer area below the prompt. */
    public static function heightFor(Question $question): float
    {
        return match ($question->type) {
            Question::TYPE_MCQ => WorksheetGeometry::MCQ_ROW_H,
            Question::TYPE_SHORT => WorksheetGeometry::BOX_H,
            Question::TYPE_SHOW_WORK => self::linesHeight($question) + WorksheetGeometry::FINAL_GAP + WorksheetGeometry::BOX_H,
            Question::TYPE_OPEN => self::linesHeight($question),
            default => throw new InvalidArgumentException("Unknown question type {$question->type}"),
        };
    }

    public static function at(Question $question, float $top): self
    {
        $left = WorksheetGeometry::regionLeft();
        $right = WorksheetGeometry::CONTENT_RIGHT;

        switch ($question->type) {
            case Question::TYPE_MCQ:
                $bubbles = [];
                $cy = $top + WorksheetGeometry::MCQ_ROW_H / 2;
                foreach (Question::MCQ_OPTIONS as $i => $option) {
                    $bubbles[] = [
                        'option' => $option,
                        'cx' => $left + $i * WorksheetGeometry::MCQ_SPACING + WorksheetGeometry::MCQ_LABEL_TO_CENTRE,
                        'cy' => $cy,
                        'r' => WorksheetGeometry::MCQ_BUBBLE_R,
                    ];
                }
                $last = end($bubbles);
                $x = $left - 1.0;
                $w = $last['cx'] + WorksheetGeometry::MCQ_BUBBLE_R + 2.0 - $x;

                return new self('mcq', self::r($x, $top, $w, WorksheetGeometry::MCQ_ROW_H), $bubbles);

            case Question::TYPE_SHORT:
                return new self(
                    'box',
                    self::r(WorksheetGeometry::boxLeft(), $top, WorksheetGeometry::BOX_W, WorksheetGeometry::BOX_H),
                    numeric: $question->is_numeric,
                );

            case Question::TYPE_SHOW_WORK:
                $h = self::linesHeight($question);
                $finalTop = $top + $h + WorksheetGeometry::FINAL_GAP;

                return new self(
                    'lines',
                    self::r($left, $top, $right - $left, $h),
                    lineCount: (int) $question->answer_lines,
                    numbered: true,
                    final: self::r(WorksheetGeometry::boxLeft(), $finalTop, WorksheetGeometry::BOX_W, WorksheetGeometry::BOX_H),
                    numeric: $question->is_numeric,
                );

            case Question::TYPE_OPEN:
                return new self(
                    'lines',
                    self::r($left, $top, $right - $left, self::linesHeight($question)),
                    lineCount: (int) $question->answer_lines,
                );
        }

        throw new InvalidArgumentException("Unknown question type {$question->type}");
    }

    /**
     * The region entry of DESIGN §5.3 (frame-relative 0–1 coordinates).
     *
     * @return array<string, mixed>
     */
    public function toLayoutJson(Question $question): array
    {
        $region = [
            'region_id' => 'q'.$question->id,
            'question_id' => $question->id,
            'kind' => $this->kind,
            'rect' => self::normalise($this->rect),
        ];

        if ($this->kind === 'mcq') {
            $region['bubbles'] = array_map(fn (array $b) => [
                'option' => $b['option'],
                'cx' => WorksheetGeometry::nx($b['cx']),
                'cy' => WorksheetGeometry::ny($b['cy']),
                'r' => WorksheetGeometry::nr($b['r']),
            ], $this->bubbles);
        } elseif ($this->kind === 'box') {
            $region['numeric'] = $this->numeric;
        } else {
            $region['line_count'] = $this->lineCount;
            if ($this->final !== null) {
                $region['final_answer'] = ['rect' => self::normalise($this->final), 'numeric' => $this->numeric];
            }
        }

        return $region;
    }

    private static function linesHeight(Question $question): float
    {
        return max(1, (int) $question->answer_lines) * WorksheetGeometry::LINE_H;
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    private static function r(float $x, float $y, float $w, float $h): array
    {
        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function normalise(array $rect): array
    {
        return WorksheetGeometry::rect($rect['x'], $rect['y'], $rect['w'], $rect['h']);
    }
}
