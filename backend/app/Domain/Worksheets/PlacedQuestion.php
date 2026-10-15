<?php

namespace App\Domain\Worksheets;

use App\Models\Question;

/** One question positioned on a worksheet page (millimetres from the page top). */
final readonly class PlacedQuestion
{
    public function __construct(
        public Question $question,
        public int $number,
        public string $promptHtml,
        public float $top,
        public float $promptHeight,
    ) {}

    public function promptBottom(): float
    {
        return $this->top + $this->promptHeight;
    }

    public function region(): RegionGeometry
    {
        return RegionGeometry::at($this->question, $this->promptBottom() + WorksheetGeometry::PROMPT_GAP);
    }

    public function height(): float
    {
        return self::blockHeight($this->question, $this->promptHeight);
    }

    public static function blockHeight(Question $question, float $promptHeight): float
    {
        return $promptHeight + WorksheetGeometry::PROMPT_GAP + RegionGeometry::heightFor($question);
    }
}
