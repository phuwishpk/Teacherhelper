<?php

namespace Tests\Unit\Classrooms;

use App\Domain\Classrooms\ThaiNameSorter;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §19.2 / §19.12 ThaiNameSorterTest: leading vowels, Thai and English
 * titles, Thai before English, letter case and equal names.
 */
class ThaiNameSorterTest extends TestCase
{
    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $rows = array_map(fn (string $n, int $i) => ['name' => $n, 'id' => 'g'.$i], $names, array_keys($names));

        return array_column(ThaiNameSorter::sort($rows, 'name', 'id'), 'name');
    }

    public function test_a_leading_vowel_sorts_after_the_consonant_that_follows_it(): void
    {
        $this->assertSame(
            ['กมล สุขใจ', 'เกษม ดีมาก', 'ไก่ ขันดี', 'ขวัญ ใจงาม', 'โสภา ศรีสุข'],
            self::sorted(['โสภา ศรีสุข', 'ไก่ ขันดี', 'ขวัญ ใจงาม', 'เกษม ดีมาก', 'กมล สุขใจ']),
        );
    }

    public function test_thai_and_english_titles_are_ignored_but_kept_in_the_name(): void
    {
        $this->assertSame(
            ['นาย กมล ใจดี', 'ด.ญ.ขนิษฐา ศรีสุข', 'เด็กชายจักรพันธ์ ทองดี', 'Miss Alice Wong', 'Mr. Bob Lee'],
            self::sorted(['Mr. Bob Lee', 'เด็กชายจักรพันธ์ ทองดี', 'Miss Alice Wong', 'ด.ญ.ขนิษฐา ศรีสุข', 'นาย กมล ใจดี']),
        );
    }

    public function test_thai_names_come_before_english_ones_and_anything_else_is_last(): void
    {
        $this->assertSame(
            ['ฮานะ ยามาดะ', 'Aaron Smith', 'Zoe Tan', '123 test account', '_bot'],
            self::sorted(['Zoe Tan', '_bot', 'Aaron Smith', '123 test account', 'ฮานะ ยามาดะ']),
        );
    }

    public function test_english_names_ignore_letter_case(): void
    {
        $this->assertSame(['adam b', 'Alice c', 'bob d', 'Carl e'], self::sorted(['Carl e', 'bob d', 'Alice c', 'adam b']));
    }

    public function test_equal_first_names_compare_the_surname_by_the_same_rules(): void
    {
        $this->assertSame(
            ['สมชาย', 'สมชาย กุลดี', 'สมชาย เกตุแก้ว', 'สมชาย ใจดี'],
            self::sorted(['สมชาย ใจดี', 'สมชาย เกตุแก้ว', 'สมชาย กุลดี', 'สมชาย']),
        );
    }

    public function test_the_same_name_twice_is_ordered_by_the_tie_breaker_every_time(): void
    {
        $rows = [
            ['name' => 'สมศรี มีสุข', 'id' => 'g-2'],
            ['name' => 'ด.ญ. สมศรี มีสุข', 'id' => 'g-3'],
            ['name' => 'สมศรี มีสุข', 'id' => 'g-1'],
        ];

        $this->assertSame(['g-3', 'g-1', 'g-2'], array_column(ThaiNameSorter::sort($rows, 'name', 'id'), 'id'));
        $this->assertSame(['g-3', 'g-1', 'g-2'], array_column(ThaiNameSorter::sort(array_reverse($rows), 'name', 'id'), 'id'));
        $this->assertSame(0, ThaiNameSorter::compare('ด.ญ. สมศรี มีสุข', 'สมศรี มีสุข'));
        $this->assertLessThan(0, ThaiNameSorter::compare('กมล', 'เกษม'));
    }
}
