<?php

namespace Tests\Unit\Gemini;

use App\Domain\Gemini\Calibration\CalibrationSample;
use App\Domain\Gemini\Calibration\CalibrationScorer;
use App\Models\Question;
use Tests\TestCase;

/** DESIGN §21.10: the arithmetic of the calibration harness. */
class CalibrationScorerTest extends TestCase
{
    private const LIMITS = ['min_samples' => 4, 'max_drop' => 0.02, 'max_score_flips' => 0];

    private static function short(string $id, string $written, bool $right, ?array $cnn = null): CalibrationSample
    {
        $question = (new Question)->forceFill([
            'position' => 1, 'type' => 'short', 'prompt_text' => '12 + 8', 'max_points' => 1, 'is_numeric' => true, 'match_mode' => 'flexible',
            'answer_key' => ['accepted' => ['20'], 'numeric' => ['value' => 20, 'abs_tol' => 0]],
        ]);

        return new CalibrationSample($id, $question, [], ['answer_text' => $written, 'key_match' => $right ? ['exact', 'equivalent'] : ['different', 'partial']], $cnn);
    }

    private static function read(string $text, string $match): array
    {
        return ['blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'answer_text' => $text, 'key_match' => $match, 'error_types' => []];
    }

    public function test_a_sample_is_scored_on_its_answer_its_category_and_full_marks(): void
    {
        $row = CalibrationScorer::score(self::short('a#1', '20', true), self::read(' ๒๐ ', 'exact'));
        $this->assertSame([true, true, true, true], [$row['found'], $row['answer'], $row['category'], $row['full']], 'Thai digits and spaces normalised');

        $row = CalibrationScorer::score(self::short('b#1', '21', false), self::read('27', 'different'));
        $this->assertSame([false, true, false], [$row['answer'], $row['category'], $row['full']]);

        $row = CalibrationScorer::score(self::short('c#1', '20', true), null);
        $this->assertSame([false, false, false, null], [$row['found'], $row['answer'], $row['category'], $row['full']], 'a missing answer counts as wrong');
    }

    public function test_show_work_needs_the_final_answer_and_every_step(): void
    {
        $question = (new Question)->forceFill([
            'position' => 1, 'type' => 'show_work', 'prompt_text' => '3x + 5 = 20', 'max_points' => 3, 'is_numeric' => true,
            'answer_key' => ['final' => ['accepted' => ['5'], 'numeric' => ['value' => 5, 'abs_tol' => 0]], 'reference_steps' => ['3x = 15', 'x = 5']],
        ]);
        $sample = new CalibrationSample('w#1', $question, [], ['final_answer_text' => '5', 'final_answer_match' => ['exact', 'equivalent'], 'steps_valid' => [true, true]]);
        $read = fn (array $valid) => ['blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear',
            'steps' => array_map(fn ($v, $i) => ['line' => $i + 1, 'text' => 'x', 'valid' => $v], $valid, array_keys($valid)),
            'final_answer_text' => 'x = 5', 'final_answer_match' => 'exact', 'error_types' => []];

        $this->assertSame([false, true, true], array_values(array_intersect_key(CalibrationScorer::score($sample, $read([true, true])), array_flip(['answer', 'category', 'full']))), '"x = 5" is not the label "5"');
        $this->assertFalse(CalibrationScorer::score($sample, $read([true, false]))['category']);
    }

    public function test_a_document_answer_is_one_of_the_accepted_answers_or_the_value(): void
    {
        $question = (new Question)->forceFill(['position' => 3, 'type' => 'short', 'prompt_text' => '15 × 3']);
        $sample = new CalibrationSample('d#3', $question, [], ['answer' => '45']);
        $row = CalibrationScorer::score($sample, ['question_no' => 3, 'type' => 'short', 'answer_key' => ['accepted' => ['45.0'], 'numeric' => ['value' => 45.0]]], true);
        $this->assertSame([true, true, null], [$row['answer'], $row['category'], $row['full']]);

        $mcq = new CalibrationSample('d#4', (new Question)->forceFill(['position' => 4, 'type' => 'mcq', 'prompt_text' => 'x']), [], ['answer' => 'B']);
        $this->assertFalse(CalibrationScorer::score($mcq, ['type' => 'mcq', 'answer_key' => ['correct' => 'C']], true)['answer']);
    }

    public function test_a_lower_level_passes_only_within_the_drop_the_flips_and_the_sample_count(): void
    {
        $rows = fn (array $answers, array $full) => array_map(fn ($a, $f, $i) => ['id' => "s{$i}", 'found' => true, 'answer' => $a, 'category' => true, 'full' => $f], $answers, $full, array_keys($answers));
        $high = $rows([true, true, true, true, true], [true, true, false, true, false]);

        $same = CalibrationScorer::verdict($high, $high, self::LIMITS);
        $this->assertSame([true, 0.0, 0, []], [$same['pass'], $same['answer_drop'], $same['score_flips'], $same['reasons']]);

        $worse = CalibrationScorer::verdict($high, $rows([true, true, true, true, false], [true, true, false, true, true]), self::LIMITS);
        $this->assertFalse($worse['pass']);
        $this->assertSame([0.2, 1, ['s4']], [$worse['answer_drop'], $worse['score_flips'], $worse['flipped']]);
        $this->assertCount(2, $worse['reasons']);

        $few = CalibrationScorer::verdict(array_slice($high, 0, 3), array_slice($high, 0, 3), self::LIMITS);
        $this->assertSame(['only 3 samples (need 4)'], $few['reasons']);

        // A better reading at the lower level is no drop.
        $this->assertTrue(CalibrationScorer::verdict($rows([false, true, true, true, true], [true, true, false, true, false]), $high, self::LIMITS)['pass']);
    }

    public function test_the_lowest_passing_level_is_recommended(): void
    {
        $this->assertSame('low', CalibrationScorer::recommend(['low' => true, 'medium' => true]));
        $this->assertSame('medium', CalibrationScorer::recommend(['low' => false, 'medium' => true]));
        $this->assertSame('high', CalibrationScorer::recommend(['low' => false, 'medium' => false]));
        $this->assertSame('high', CalibrationScorer::recommend([]));
    }

    public function test_the_cnn_skip_check_counts_full_marks_given_to_wrong_answers(): void
    {
        $samples = [
            self::short('a#1', '20', true, ['text' => '20', 'confidence' => 0.99]),
            self::short('b#1', '26', false, ['text' => '20', 'confidence' => 0.98]),  // misread 26 as 20: wrong full marks
            self::short('c#1', '20', true, ['text' => '20', 'confidence' => 0.90]),  // not sure enough: Gemini decides
            self::short('d#1', '21', false, ['text' => '21', 'confidence' => 0.99]), // not an accepted answer
            self::short('e#1', '20', true),                                           // no reading
        ];
        $check = CalibrationScorer::cnnSkip($samples, 0.97, self::LIMITS);
        $this->assertSame([4, 2, 1, ['b#1'], false], [$check['samples'], $check['decided'], $check['wrong'], $check['wrong_ids'], $check['pass']]);

        $this->assertTrue(CalibrationScorer::cnnSkip(array_slice($samples, 2, 2) + [2 => $samples[0], 3 => $samples[0]], 0.97, self::LIMITS)['pass']);
    }
}
