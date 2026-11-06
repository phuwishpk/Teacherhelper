<?php

namespace App\Domain\Exams;

use App\Models\ExamSection;
use App\Models\QuestionOption;

/**
 * One numbered answer on an exam answer sheet (DESIGN §22.7): a bubble row
 * (mcq with 2–6 options, or ถ/ผ) or a digit block (numeric). The shape of
 * number n is the same in every version, because questions are shuffled
 * only within their section and a section has one type and option count.
 */
final readonly class ExamSheetItem
{
    public function __construct(
        public int $sheetNo,
        public string $type,
        public int $choices = 0,
        public int $digits = 0,
        public bool $allowNegative = false,
        public bool $allowDecimal = false,
    ) {}

    public static function forSection(ExamSection $section, int $sheetNo): self
    {
        return new self(
            $sheetNo,
            $section->type,
            $section->choiceCount(),
            $section->type === ExamSection::TYPE_NUMERIC ? (int) $section->numeric_digits : 0,
            $section->type === ExamSection::TYPE_NUMERIC && $section->numeric_allow_negative,
            $section->type === ExamSection::TYPE_NUMERIC && $section->numeric_allow_decimal,
        );
    }

    public function isNumeric(): bool
    {
        return $this->type === ExamSection::TYPE_NUMERIC;
    }

    /**
     * Labels of the bubbles of a row: ก ข ค … for mcq, ถ ผ for true_false.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        if ($this->type === ExamSection::TYPE_TRUE_FALSE) {
            return ['ถ', 'ผ'];
        }

        return array_map(fn (int $i) => QuestionOption::label($i), $this->choices > 0 ? range(1, $this->choices) : []);
    }

    /** Digit columns of a block: the digits plus one column for the decimal point (§22.7). */
    public function digitColumns(): int
    {
        return $this->digits + ($this->allowDecimal ? 1 : 0);
    }
}
