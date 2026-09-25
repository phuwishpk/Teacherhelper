<?php

namespace Tests\Unit\Grading;

use App\Domain\Grading\AnswerMatcher;
use App\Domain\Grading\CategoryScale;
use App\Domain\Grading\PrioritySignals;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DESIGN §11.2 (categories) and §11.4 (answer matching, input M). */
class AnswerMatcherTest extends TestCase
{
    public function test_normalize_trims_maps_thai_digits_and_lowercases(): void
    {
        $this->assertSame('12.5', AnswerMatcher::normalize('  ๑๒.๕ '));
        $this->assertSame('bangkok', AnswerMatcher::normalize('Bangkok'));
        $this->assertSame('กรุงเทพฯ', AnswerMatcher::normalize("\tกรุงเทพฯ\n"));
        $this->assertSame('ÀB', mb_strtoupper(AnswerMatcher::normalize("\u{00A0}àb\u{3000}")), 'unicode spaces and letters');
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function distances(): array
    {
        return [['', '', 0], ['abc', '', 3], ['', 'abc', 3], ['kitten', 'sitting', 3], ['แมว', 'แมว', 0], ['แมว', 'แมง', 1], ['กรุงเทพ', 'กรุงเทพฯ', 1]];
    }

    #[DataProvider('distances')]
    public function test_levenshtein_counts_code_points_not_bytes(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, AnswerMatcher::levenshtein($a, $b));
    }

    public function test_flexible_text_match_takes_the_best_of_similarity_and_key_match(): void
    {
        $accepted = ['กรุงเทพมหานคร', 'กรุงเทพฯ'];
        $this->assertSame(1.0, AnswerMatcher::textMatchFlexible('กรุงเทพฯ', $accepted));
        $this->assertEqualsWithDelta(1 - 1 / 8, AnswerMatcher::textMatchFlexible('กรุงเทพ', $accepted), 1e-12);
        $this->assertEqualsWithDelta(0.6, AnswerMatcher::textMatchFlexible('xyz', $accepted, 'partial'), 1e-12);
        $this->assertSame(1.0, AnswerMatcher::textMatchFlexible('xyz', $accepted, 'equivalent'));
    }

    public function test_exact_mode_ignores_gemini_and_needs_identity(): void
    {
        $this->assertSame(1.0, AnswerMatcher::shortMatch(' Apple ', ['accepted' => ['apple']], 'exact', 'different'));
        $this->assertSame(0.0, AnswerMatcher::shortMatch('aple', ['accepted' => ['apple']], 'exact', 'exact'));
        $this->assertSame(1.0, AnswerMatcher::textMatchExact('๑๐', ['10']));
    }

    public function test_numeric_near_miss_is_capped_at_0_6(): void
    {
        foreach ([12.5001, 12.51, 12.6, 13.0] as $answer) {
            $this->assertLessThanOrEqual(0.6, AnswerMatcher::numericMatch($answer, 12.5));
        }
        $this->assertSame(1.0, AnswerMatcher::numericMatch(12.51, 12.5, 0.01));
        $this->assertSame(0.0, AnswerMatcher::numericMatch(20, 12.5));
        $this->assertEqualsWithDelta(0.3, AnswerMatcher::numericMatch(0.05, 0.0), 1e-12, 'rel_err uses max(|k|, 1)');
    }

    public function test_numeric_key_falls_back_to_text_when_the_answer_is_not_a_number(): void
    {
        $key = ['accepted' => ['12.5'], 'numeric' => ['value' => 12.5, 'abs_tol' => 0.01]];
        $this->assertSame(1.0, AnswerMatcher::shortMatch('๑๒.๕๑', $key));
        $this->assertEqualsWithDelta(AnswerMatcher::similarity('สิบสอง', '12.5'), AnswerMatcher::shortMatch('สิบสอง', $key), 1e-12);
    }

    public function test_parse_number(): void
    {
        $this->assertSame(0.75, AnswerMatcher::parseNumber('3/4'));
        $this->assertSame(3.5, AnswerMatcher::parseNumber('3,5'));
        $this->assertSame(-7.0, AnswerMatcher::parseNumber('−7'));
        $this->assertNull(AnswerMatcher::parseNumber('1/0'));
        $this->assertNull(AnswerMatcher::parseNumber('[?]'));
        $this->assertNull(AnswerMatcher::parseNumber('12 คน'));
    }

    public function test_final_answer_value_lifts_gemini_with_the_numeric_rule(): void
    {
        $key = ['accepted' => ['x = 5'], 'numeric' => ['value' => 5, 'abs_tol' => 0]];
        $this->assertSame(1.0, AnswerMatcher::finalAnswerValue('different', '5', $key));
        $this->assertSame(0.0, AnswerMatcher::finalAnswerValue('different', 'x = 5', $key));
        $this->assertSame(0.5, AnswerMatcher::finalAnswerValue('partial'));
    }

    public function test_category_scale(): void
    {
        $this->assertSame(0.6, CategoryScale::matchValue('partial', 'short'));
        $this->assertSame(0.5, CategoryScale::matchValue('partial', 'show_work'));
        $this->assertSame(0.5, CategoryScale::criteriaLevelValue('partially_met'));
        $this->assertSame(0.4, CategoryScale::legibilityValue('readable'));

        $this->expectException(InvalidArgumentException::class);
        CategoryScale::matchValue('close');
    }

    public function test_priority_signals(): void
    {
        $this->assertSame(0.5, PrioritySignals::readerDisagreement(null, '125', true), 'CNN abstained');
        $this->assertSame(0.0, PrioritySignals::readerDisagreement('5', '5.0', true));
        $this->assertSame(1.0, PrioritySignals::readerDisagreement('125', '126', true));
        $this->assertSame(0.0, PrioritySignals::readerDisagreement(null, 'x', false));
        $this->assertSame(1.0, PrioritySignals::disagreement(blank: true, inkRatio: 0.05));
        $this->assertSame(0.6, PrioritySignals::disagreement(mcqAmbiguity: 0.6));
        $this->assertGreaterThan(0.0, PrioritySignals::illegibility('clear', 'ab[?]'));
        $this->assertSame(1.0, PrioritySignals::illegibility('hard'));
    }
}
