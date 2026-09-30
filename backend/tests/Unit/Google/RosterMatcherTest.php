<?php

namespace Tests\Unit\Google;

use App\Domain\Google\NameNormalizer;
use App\Domain\Google\RosterMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RosterMatcherTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameNames(): array
    {
        return [
            'Thai title abbreviation' => ['ด.ช. สมชาย ใจดี', 'สมชาย ใจดี'],
            'Thai title in full, no space' => ['เด็กหญิงสมศรี  มีสุข', 'ด.ญ.สมศรี มีสุข'],
            'นาย / นางสาว' => ['นายกล้า หาญ', 'กล้า หาญ'],
            'English title and case' => ['Mr. Somchai Jaidee', 'somchai JAIDEE'],
            'punctuation and spaces' => ['Anna-Marie  O\'Neil', 'annamarie oneil'],
            'Thai digits' => ['ห้อง ๕', 'ห้อง 5'],
        ];
    }

    #[DataProvider('sameNames')]
    public function test_names_normalise_to_the_same_key(string $a, string $b): void
    {
        $this->assertSame(NameNormalizer::key($b), NameNormalizer::key($a));
        $this->assertNotSame('', NameNormalizer::key($a));
    }

    public function test_an_english_title_only_counts_at_a_word_boundary(): void
    {
        $this->assertSame('mrinalsen', NameNormalizer::key('Mrinal Sen'));
        $this->assertSame('missy', NameNormalizer::key('Missy'));
    }

    public function test_suggestions_go_from_exact_to_word_order_to_unique_first_name(): void
    {
        $accounts = [
            ['google_user_id' => 'g1', 'name' => 'สมชาย ใจดี'],
            ['google_user_id' => 'g2', 'name' => 'Jaidee Suda'],
            ['google_user_id' => 'g3', 'name' => 'มานี นามสกุลใหม่'],
            ['google_user_id' => 'g4', 'name' => 'Nobody Here'],
        ];
        $students = [
            ['id' => 11, 'name' => 'ด.ช. สมชาย ใจดี'],
            ['id' => 12, 'name' => 'Suda Jaidee'],
            ['id' => 13, 'name' => 'ด.ญ. มานี มีนา'],
            ['id' => 14, 'name' => 'ด.ญ. ปิติ ชูใจ'],
        ];

        $this->assertSame(['g1' => 11, 'g2' => 12, 'g3' => 13], RosterMatcher::suggest($accounts, $students));
    }

    public function test_an_ambiguous_name_is_not_suggested_and_no_student_is_offered_twice(): void
    {
        $accounts = [
            ['google_user_id' => 'g1', 'name' => 'สมชาย ใจดี'],
            ['google_user_id' => 'g2', 'name' => 'สมชาย ใจดี'],
            ['google_user_id' => 'g3', 'name' => 'มานี แก้ว'],
            ['google_user_id' => 'g4', 'name' => 'มานี ทอง'],
        ];
        $students = [
            ['id' => 11, 'name' => 'สมชาย ใจดี'],
            ['id' => 12, 'name' => 'สมชาย ใจดี'],
            ['id' => 13, 'name' => 'มานี ศรี'],
        ];

        // Two identical names on each side, and a first name two accounts share:
        // guessing could pair the wrong child, so nothing is suggested.
        $this->assertSame([], RosterMatcher::suggest($accounts, $students));

        // Two accounts with one student's name: still no guess.
        $this->assertSame([], RosterMatcher::suggest($accounts, [['id' => 11, 'name' => 'สมชาย ใจดี']]));
        $this->assertSame(['g1' => 11], RosterMatcher::suggest([$accounts[0]], [['id' => 11, 'name' => 'สมชาย ใจดี']]));
    }

    public function test_without_first_names_only_full_names_are_suggested(): void
    {
        $accounts = [
            ['google_user_id' => 'g1', 'name' => 'Jaidee Somchai'],
            ['google_user_id' => 'g2', 'name' => 'สมศรี คนอื่น'],
        ];
        $students = [['id' => 1, 'name' => 'Somchai Jaidee'], ['id' => 2, 'name' => 'สมศรี มีสุข']];

        $this->assertSame(['g1' => 1, 'g2' => 2], RosterMatcher::suggest($accounts, $students));
        $this->assertSame(['g1' => 1], RosterMatcher::suggest($accounts, $students, false));
    }
}
