<?php

namespace Tests\Unit\Exams;

use App\Domain\Exams\LockOptionsDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** DESIGN §22.5: "ห้ามสลับตัวเลือก" is suggested for options that only make sense in their place. */
class LockOptionsDetectorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function suggested(): array
    {
        return [
            'all correct' => ['ถูกทุกข้อ'],
            'all wrong' => ['ผิดทุกข้อ'],
            'none correct' => ['ไม่มีข้อใดถูก'],
            'none correct spaced' => ['ไม่มี ข้อใด ถูก'],
            'both correct' => ['ถูกทั้งข้อ ก และ ข'],
            'both letters' => ['ทั้ง ก และ ข'],
            'items letters' => ['ข้อ ก และ ข'],
            'items letters twice' => ['ข้อ ก และ ข้อ ค'],
            'letters correct' => ['ก และ ค ถูก'],
            'three letters' => ['ก, ข และ ค'],
            'letters or' => ['ข หรือ ง'],
            'all of the above' => ['All of the above'],
            'none of the above' => ['none of the above.'],
            'both latin' => ['Both A and B'],
            'latin are correct' => ['A and C are correct'],
        ];
    }

    #[DataProvider('suggested')]
    public function test_an_option_that_names_the_others_is_suggested(string $text): void
    {
        $this->assertTrue(LockOptionsDetector::refersToOthers($text));
        $this->assertTrue(LockOptionsDetector::suggests(['12', null, $text, '15']));
    }

    /** @return array<string, array{string}> */
    public static function notSuggested(): array
    {
        return [
            'number' => ['12'],
            'formula' => ['x^2 + 3x = 10'],
            'word ending in a letter' => ['นก และ ไก่'],
            'two letters joined' => ['กข'],
            'one letter' => ['ก'],
            'correct alone' => ['ถูก'],
            'thai sentence' => ['แมวเป็นสัตว์เลี้ยงลูกด้วยนม'],
            'thai sentence with correct' => ['ถูกต้องที่สุด'],
            'latin sentence' => ['A and b are equal numbers'],
            'blank' => ['  '],
        ];
    }

    #[DataProvider('notSuggested')]
    public function test_ordinary_options_are_not_suggested(string $text): void
    {
        $this->assertFalse(LockOptionsDetector::refersToOthers($text));
    }

    public function test_options_without_text_are_not_suggested(): void
    {
        $this->assertFalse(LockOptionsDetector::suggests([null, null, '', '4']));
    }
}
