<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\NumericAnswer;
use App\Models\ExamSection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** DESIGN §22.3: the canonical form of a numeric answer and whether it fits the digit block (§22.7). */
class NumericAnswerTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function canonicalForms(): array
    {
        return [
            'integer' => ['12', '12'],
            'leading zeros' => ['007', '7'],
            'zero' => ['0', '0'],
            'zeros' => ['000', '0'],
            'dot five' => ['.5', '0.5'],
            'padded decimal' => ['00.50', '0.5'],
            'trailing dot' => ['5.', '5'],
            'trailing zero fraction' => ['12.000', '12'],
            'negative' => ['-3', '-3'],
            'negative decimal' => ['-0.25', '-0.25'],
            'negative dot' => ['-.5', '-0.5'],
            'minus zero' => ['-0', '0'],
            'minus zero decimal' => ['-0.00', '0'],
            'spaces trimmed' => [' 42 ', '42'],
            'only sign' => ['-', null],
            'only dot' => ['.', null],
            'sign and dot' => ['-.', null],
            'two dots' => ['1.2.3', null],
            'empty' => ['', null],
            'blank digit inside' => ['1 2', null],
            'letters' => ['12a', null],
            'plus sign' => ['+3', null],
            'comma' => ['1,5', null],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function test_canonical_form(string $input, ?string $expected): void
    {
        $this->assertSame($expected, NumericAnswer::canonical($input));
    }

    /** @return array<string, array{string, int, bool, bool, bool}> */
    public static function fits(): array
    {
        return [
            'three digits in three' => ['123', 3, false, false, true],
            'four digits in three' => ['1234', 3, false, false, false],
            'four digits in three plus the decimal column' => ['1234', 3, false, true, true],
            'negative without sign column' => ['-5', 3, false, false, false],
            'negative with sign column' => ['-5', 3, true, false, true],
            'decimal without decimal column' => ['0.5', 3, false, false, false],
            'decimal drops the leading zero' => ['0.25', 2, false, true, true],
            'decimal too long' => ['12.25', 3, false, true, false],
            'decimal fits' => ['1.25', 3, false, true, true],
            'zero' => ['0', 1, false, false, true],
            'five digits' => ['99999', 5, false, false, true],
        ];
    }

    #[DataProvider('fits')]
    public function test_fits_the_digit_block(string $value, int $digits, bool $negative, bool $decimal, bool $expected): void
    {
        $section = new ExamSection([
            'type' => ExamSection::TYPE_NUMERIC,
            'numeric_digits' => $digits,
            'numeric_allow_negative' => $negative,
            'numeric_allow_decimal' => $decimal,
        ]);

        $this->assertSame($expected, NumericAnswer::fits($value, $section));
    }
}
