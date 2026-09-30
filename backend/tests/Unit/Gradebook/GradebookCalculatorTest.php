<?php

namespace Tests\Unit\Gradebook;

use App\Domain\Gradebook\GradebookCalculator;
use App\Domain\Gradebook\GradebookCategorySpec;
use App\Domain\Gradebook\GradebookCell;
use App\Domain\Gradebook\GradebookColumn;
use App\Domain\Gradebook\GradeCutoffs;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Golden tests of the gradebook formula (DESIGN §23.4) with the worked
 * examples of §23.5 and the edge cases listed in §23.13.
 */
class GradebookCalculatorTest extends TestCase
{
    private const HW = 1;

    private const MID = 2;

    private const FINAL = 3;

    private const AFF = 4;

    private const S = 12;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-12-01T00:00:00Z');
    }

    public function test_the_worked_example_of_section_23_5(): void
    {
        [$columns, $cells] = $this->worldOf235();

        $result = $this->calc($this->template())->compute($columns, [self::S], [self::S => $cells]);
        $row = $result['rows'][self::S];

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_categories']);
        $this->assertSame(['percent' => 73.3333, 'points' => 22.0], $row['categories'][self::HW]);
        $this->assertSame(['percent' => 65.0, 'points' => 13.0], $row['categories'][self::MID]);
        $this->assertSame(['percent' => 66.0, 'points' => 19.8], $row['categories'][self::FINAL]);
        $this->assertSame(['percent' => 95.0, 'points' => 19.0], $row['categories'][self::AFF]);
        $this->assertSame(73.8, $row['total']);
        $this->assertSame(74, $row['total_rounded']);
        $this->assertSame(3.0, $row['grade']);
        $this->assertFalse($row['attendance_warning']);
        $this->assertFalse($row['in_progress']);

        // การบ้าน 4 (ไม่ส่ง = 0) is the dropped one; การบ้าน 5 excused; งานฝึก not counted.
        $this->assertSame(['score' => null, 'percent' => 0.0, 'state' => 'missing', 'dropped' => true, 'submission_id' => null], $row['cells']['a4']);
        $this->assertSame('excused', $row['cells']['a5']['state']);
        $this->assertNull($row['cells']['a5']['percent']);
        $this->assertSame('not_counted', $row['cells']['a6']['state']);
        $this->assertFalse($result['counted']['a6']);
        $this->assertSame(['score' => 8.0, 'percent' => 80.0, 'state' => 'scored', 'dropped' => false, 'submission_id' => 101], $row['cells']['a1']);
    }

    public function test_in_progress_renormalises_over_the_categories_with_values(): void
    {
        [$columns, $cells] = $this->worldOf235();
        // Before the final exam: its date is ahead and no score is typed yet.
        $columns = array_map(fn (GradebookColumn $c) => $c->key === 'a8'
            ? new GradebookColumn('a8', GradebookColumn::MANUAL_EXAM, 8, 'ปลายภาค', self::FINAL, 50, dueAt: $this->now->addWeek())
            : $c, $columns);
        unset($cells['a8']);

        $result = $this->calc($this->template())->compute($columns, [self::S], [self::S => $cells]);
        $row = $result['rows'][self::S];

        $this->assertFalse($result['complete']);
        $this->assertSame([self::FINAL], $result['missing_categories']);
        $this->assertSame(70.0, $result['counted_weight']);
        $this->assertSame(77.1429, $row['total']); // (22 + 13 + 19) × 100 / 70
        $this->assertNull($row['total_rounded']);
        $this->assertNull($row['grade']);
        $this->assertTrue($row['in_progress']);
        $this->assertSame(70.0, $row['counted_weight']);
        $this->assertSame('not_counted', $row['cells']['a8']['state']);
    }

    public function test_rounds_half_up_at_the_cutoff_after_four_decimals(): void
    {
        $this->assertSame([80, 4.0], $this->single(79.5));
        $this->assertSame([79, 3.5], $this->single(79.4999));
        $this->assertSame([80, 4.0], $this->single(79.49999999));
        $this->assertSame(80, GradebookCalculator::roundTotal(79.5));
        $this->assertSame(0.0, GradeCutoffs::grade(49, GradeCutoffs::defaults()));
        $this->assertSame(1.0, GradeCutoffs::grade(50, GradeCutoffs::defaults()));
    }

    public function test_the_teachers_cutoffs_decide_the_grade(): void
    {
        $this->assertSame([74, 4.0], $this->single(74, [70, 65, 60, 55, 50, 45, 40]));
        $this->assertSame([44, 1.5], $this->single(44, [70, 65, 60, 55, 50, 44, 40]));
    }

    public function test_drop_lowest_always_keeps_one_value(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100, 2)];
        $columns = [$this->item('i1', 1, 10), $this->item('i2', 1, 10)];
        $cells = ['i1' => new GradebookCell(score: 4), 'i2' => new GradebookCell(score: 9)];

        $row = $this->calc($cats)->compute($columns, [self::S], [self::S => $cells])['rows'][self::S];

        $this->assertSame(90.0, $row['categories'][1]['percent']);
        $this->assertTrue($row['cells']['i1']['dropped']);
        $this->assertFalse($row['cells']['i2']['dropped']);

        // A single value is never dropped.
        $row = $this->calc($cats)->compute([$this->item('i1', 1, 10)], [self::S], [self::S => ['i1' => new GradebookCell(score: 4)]])['rows'][self::S];
        $this->assertSame(40.0, $row['categories'][1]['percent']);
        $this->assertFalse($row['cells']['i1']['dropped']);
    }

    public function test_excused_cells_stay_out_of_the_mean_and_a_fully_excused_category_renormalises(): void
    {
        $cats = [new GradebookCategorySpec(1, 'เก็บ', 70), new GradebookCategorySpec(2, 'จิตพิสัย', 30)];
        $columns = [$this->item('i1', 1, 10), $this->item('i2', 1, 10), $this->item('i3', 2, 10)];
        $other = 99;
        $cells = [
            self::S => ['i1' => new GradebookCell(score: 6), 'i2' => new GradebookCell(score: 10, excused: true), 'i3' => new GradebookCell(excused: true)],
            // Another student makes the classroom complete (i3 has a score).
            $other => ['i1' => new GradebookCell(score: 10), 'i2' => new GradebookCell(score: 10), 'i3' => new GradebookCell(score: 10)],
        ];

        $result = $this->calc($cats)->compute($columns, [self::S, $other], $cells);
        $row = $result['rows'][self::S];

        $this->assertTrue($result['complete']);
        $this->assertSame(['percent' => 60.0, 'points' => 42.0], $row['categories'][1]);
        $this->assertSame(['percent' => null, 'points' => null], $row['categories'][2]);
        $this->assertSame(60.0, $row['total']); // 42 × 100 / 70
        $this->assertSame(60, $row['total_rounded']);
        $this->assertSame(2.0, $row['grade']);
        $this->assertSame(['score' => 10.0, 'percent' => null, 'state' => 'excused', 'dropped' => false], $row['cells']['i2']);
    }

    public function test_missing_counts_zero_only_after_the_due_date_even_when_others_were_published_early(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100)];
        $dueSoon = new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'การบ้าน 1', 1, 10, dueAt: $this->now->addDay(), anyPublished: true);
        $a = 1;
        $b = 2;
        $cells = [$a => ['a1' => new GradebookCell(score: 7, hasSubmission: true, published: true, submissionId: 5)]];

        $result = $this->calc($cats)->compute([$dueSoon], [$a, $b], $cells);
        $this->assertTrue($result['counted']['a1']);
        $this->assertSame('not_due', $result['rows'][$b]['cells']['a1']['state']);
        $this->assertNull($result['rows'][$b]['total']);

        $later = $this->calcAt($cats, $this->now->addDays(2))->compute([$dueSoon], [$a, $b], $cells);
        $this->assertSame('missing', $later['rows'][$b]['cells']['a1']['state']);
        $this->assertSame(0.0, $later['rows'][$b]['total']);
        $this->assertSame(70.0, $later['rows'][$a]['total']);
    }

    public function test_homework_without_a_due_date_is_zero_only_once_closed(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100)];
        $open = new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'การบ้าน', 1, 10, anyPublished: true);
        $closed = new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'การบ้าน', 1, 10, closed: true);

        $this->assertSame('not_due', $this->calc($cats)->compute([$open], [self::S], [])['rows'][self::S]['cells']['a1']['state']);
        $row = $this->calc($cats)->compute([$closed], [self::S], [])['rows'][self::S];
        $this->assertSame('missing', $row['cells']['a1']['state']);
        $this->assertSame(0.0, $row['total']);
    }

    public function test_a_pending_submission_stays_out_of_the_mean(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100)];
        $columns = [
            new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'การบ้าน 1', 1, 10, dueAt: $this->now->subDay()),
            new GradebookColumn('a2', GradebookColumn::ASSIGNMENT, 2, 'การบ้าน 2', 1, 10, dueAt: $this->now->subDay(), anyPublished: true),
        ];
        $cells = ['a1' => new GradebookCell(hasSubmission: true, submissionId: 3), 'a2' => new GradebookCell(score: 5, hasSubmission: true, published: true)];

        $row = $this->calc($cats)->compute($columns, [self::S], [self::S => $cells])['rows'][self::S];

        $this->assertSame('pending', $row['cells']['a1']['state']);
        $this->assertSame(3, $row['cells']['a1']['submission_id']);
        $this->assertSame(50.0, $row['total']);
    }

    public function test_a_manual_exam_is_blank_before_and_zero_after_its_date(): void
    {
        $cats = [new GradebookCategorySpec(1, 'ปลายภาค', 100)];
        $exam = fn (CarbonImmutable $due, bool $scored) => new GradebookColumn('a1', GradebookColumn::MANUAL_EXAM, 1, 'สอบ', 1, 50, dueAt: $due, anyScored: $scored);
        $a = 1;
        $b = 2;
        $cells = [$a => ['a1' => new GradebookCell(score: 40)]];

        // Before the exam date with a typed score: counted, the blank one is not due.
        $before = $this->calc($cats)->compute([$exam($this->now->addDay(), true)], [$a, $b], $cells);
        $this->assertTrue($before['counted']['a1']);
        $this->assertSame('not_due', $before['rows'][$b]['cells']['a1']['state']);
        $this->assertSame(80.0, $before['rows'][$a]['total']);

        // Before the date and nothing typed: not counted yet.
        $this->assertFalse($this->calc($cats)->compute([$exam($this->now->addDay(), false)], [$a, $b], [])['counted']['a1']);

        // After the date: blank = 0 ("ไม่มีคะแนน").
        $after = $this->calc($cats)->compute([$exam($this->now->subDay(), false)], [$b], []);
        $this->assertTrue($after['counted']['a1']);
        $this->assertSame('missing', $after['rows'][$b]['cells']['a1']['state']);
        $this->assertSame(0.0, $after['rows'][$b]['total']);
    }

    public function test_excluded_zero_full_marks_and_uncategorised_columns_are_not_counted(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100)];
        $columns = [
            new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'งานฝึก', 1, 10, excluded: true, dueAt: $this->now->subDay()),
            new GradebookColumn('a2', GradebookColumn::ASSIGNMENT, 2, 'mirror', 1, 0, dueAt: $this->now->subDay()),
            new GradebookColumn('a3', GradebookColumn::ASSIGNMENT, 3, 'ไม่มีหมวด', null, 10, dueAt: $this->now->subDay()),
            new GradebookColumn('a4', GradebookColumn::ASSIGNMENT, 4, 'นับ', 1, 10, dueAt: $this->now->subDay()),
        ];
        $cells = [
            'a1' => new GradebookCell(score: 0, hasSubmission: true, published: true),
            'a2' => new GradebookCell(score: 3, hasSubmission: true, published: true),
            'a4' => new GradebookCell(score: 9, hasSubmission: true, published: true),
        ];

        $result = $this->calc($cats)->compute($columns, [self::S], [self::S => $cells]);

        $this->assertSame(['a1' => false, 'a2' => false, 'a3' => false, 'a4' => true], $result['counted']);
        $this->assertSame(90.0, $result['rows'][self::S]['total']);
        $this->assertSame(['score' => 3.0, 'percent' => null, 'state' => 'not_counted', 'dropped' => false, 'submission_id' => null], $result['rows'][self::S]['cells']['a2']);
    }

    public function test_an_override_above_full_marks_is_clamped_to_100(): void
    {
        $cats = [new GradebookCategorySpec(1, 'การบ้าน', 100)];
        $column = new GradebookColumn('a1', GradebookColumn::ASSIGNMENT, 1, 'การบ้าน', 1, 10, anyPublished: true);
        $row = $this->calc($cats)->compute([$column], [self::S], [self::S => ['a1' => new GradebookCell(score: 12, hasSubmission: true, published: true)]])['rows'][self::S];

        $this->assertSame(100.0, $row['cells']['a1']['percent']);
    }

    public function test_special_grades_replace_the_numeric_grade(): void
    {
        $cats = [new GradebookCategorySpec(1, 'ทั้งหมด', 100)];
        $row = $this->calc($cats)->compute([$this->item('i1', 1, 100)], [self::S], [self::S => ['i1' => new GradebookCell(score: 90)]], [self::S => 'ms'])['rows'][self::S];

        $this->assertSame(90.0, $row['total']);
        $this->assertSame(90, $row['total_rounded']);
        $this->assertNull($row['grade']);
        $this->assertSame('ms', $row['special']);
        $this->assertSame('มส', GradeCutoffs::label($row['grade'], $row['special']));
        $this->assertSame('ร', GradeCutoffs::label(null, 'r'));
        $this->assertSame('3.5', GradeCutoffs::label(3.5, null));
        $this->assertSame('0', GradeCutoffs::label(0.0, null));
    }

    public function test_attendance_warning_below_80_percent(): void
    {
        $cats = [new GradebookCategorySpec(1, 'จิตพิสัย', 100)];
        $att = fn (string $key, bool $scored) => new GradebookColumn($key, GradebookColumn::CUSTOM, (int) substr($key, 1), 'เข้าเรียน', 1, 10, isAttendance: true, anyScored: $scored);

        // §23.5: 15/20 = 75 % warns; 16/20 = 80 % does not.
        $warn = fn (array $cells, array $columns) => $this->calc($cats)->compute($columns, [self::S], [self::S => $cells])['rows'][self::S]['attendance_warning'];
        $one = [new GradebookColumn('i1', GradebookColumn::CUSTOM, 1, 'การเข้าเรียน', 1, 20, isAttendance: true, anyScored: true)];
        $this->assertTrue($warn(['i1' => new GradebookCell(score: 15)], $one));
        $this->assertFalse($warn(['i1' => new GradebookCell(score: 16)], $one));

        // Several items add up; a blank cell counts 0; an excused one is left out.
        $two = [$att('i1', true), $att('i2', true)];
        $this->assertTrue($warn(['i1' => new GradebookCell(score: 9), 'i2' => new GradebookCell(score: 6)], $two));
        $this->assertTrue($warn(['i1' => new GradebookCell(score: 10)], $two));
        $this->assertFalse($warn(['i1' => new GradebookCell(score: 9), 'i2' => new GradebookCell(excused: true)], $two));

        // A new attendance item without any score in the classroom warns no one.
        $this->assertFalse($warn(['i1' => new GradebookCell(score: 10)], [$att('i1', true), $att('i2', false)]));
        $this->assertFalse($warn([], [$att('i1', false)]));
    }

    public function test_cutoffs_validation(): void
    {
        $this->assertNull(GradeCutoffs::problem([80, 75, 70, 65, 60, 55, 50]));
        $this->assertNotNull(GradeCutoffs::problem([80, 75, 70, 65, 60, 55]));
        $this->assertNotNull(GradeCutoffs::problem([80, 80, 70, 65, 60, 55, 50]));
        $this->assertNotNull(GradeCutoffs::problem([101, 75, 70, 65, 60, 55, 50]));
        $this->assertNotNull(GradeCutoffs::problem([80, 75, 70, 65, 60, 55, 0]));
        $this->assertNotNull(GradeCutoffs::problem([80, 75.5, 70, 65, 60, 55, 50]));
        $this->assertNotNull(GradeCutoffs::problem(['a' => 80]));
    }

    /**
     * §23.5: template "การบ้าน 30 (ตัดต่ำสุด 1), กลางภาค 20, ปลายภาค 30, จิตพิสัย 20".
     *
     * @return list<GradebookCategorySpec>
     */
    private function template(): array
    {
        return [
            new GradebookCategorySpec(self::HW, 'การบ้าน', 30, 1),
            new GradebookCategorySpec(self::MID, 'กลางภาค', 20),
            new GradebookCategorySpec(self::FINAL, 'ปลายภาค', 30),
            new GradebookCategorySpec(self::AFF, 'จิตพิสัย', 20),
        ];
    }

    /**
     * Student 12 of §23.5.
     *
     * @return array{0: list<GradebookColumn>, 1: array<string, GradebookCell>}
     */
    private function worldOf235(): array
    {
        $past = $this->now->subWeek();
        $hw = fn (int $id, float $full) => new GradebookColumn('a'.$id, GradebookColumn::ASSIGNMENT, $id, 'การบ้าน '.$id, self::HW, $full, dueAt: $past, anyPublished: true);
        $columns = [
            $hw(1, 10), $hw(2, 10), $hw(3, 20), $hw(4, 10), $hw(5, 10),
            new GradebookColumn('a6', GradebookColumn::ASSIGNMENT, 6, 'งานฝึก', self::HW, 10, excluded: true, dueAt: $past, anyPublished: true),
            new GradebookColumn('a7', GradebookColumn::ASSIGNMENT, 7, 'กลางภาค', self::MID, 40, dueAt: $past, anyPublished: true, kind: 'exam'),
            new GradebookColumn('a8', GradebookColumn::MANUAL_EXAM, 8, 'ปลายภาค', self::FINAL, 50, dueAt: $past, anyScored: true, kind: 'exam'),
            new GradebookColumn('i1', GradebookColumn::CUSTOM, 1, 'การแต่งกาย', self::AFF, 10, anyScored: true),
            new GradebookColumn('i2', GradebookColumn::CUSTOM, 2, 'การเข้าเรียน', self::AFF, 20, isAttendance: true, anyScored: true),
        ];
        $published = fn (float $score, int $id) => new GradebookCell(score: $score, hasSubmission: true, published: true, submissionId: $id);
        $cells = [
            'a1' => $published(8, 101),
            'a2' => $published(5, 102),
            'a3' => $published(18, 103),
            'a5' => new GradebookCell(excused: true),
            'a6' => $published(2, 106),
            'a7' => $published(26, 107),
            'a8' => new GradebookCell(score: 33),
            'i1' => new GradebookCell(score: 10),
            'i2' => new GradebookCell(score: 18),
        ];

        return [$columns, $cells];
    }

    /**
     * One category of weight 100 with one item scored $score / 100.
     *
     * @param  list<int>|null  $cutoffs
     * @return array{0: int|null, 1: float|null}
     */
    private function single(float $score, ?array $cutoffs = null): array
    {
        $calc = new GradebookCalculator([new GradebookCategorySpec(1, 'ทั้งหมด', 100)], $cutoffs ?? GradeCutoffs::defaults(), $this->now);
        $row = $calc->compute([$this->item('i1', 1, 100)], [self::S], [self::S => ['i1' => new GradebookCell(score: $score)]])['rows'][self::S];

        return [$row['total_rounded'], $row['grade']];
    }

    private function item(string $key, int $category, float $full): GradebookColumn
    {
        return new GradebookColumn($key, GradebookColumn::CUSTOM, (int) substr($key, 1), $key, $category, $full, anyScored: true);
    }

    /** @param list<GradebookCategorySpec> $categories */
    private function calc(array $categories): GradebookCalculator
    {
        return $this->calcAt($categories, $this->now);
    }

    /** @param list<GradebookCategorySpec> $categories */
    private function calcAt(array $categories, CarbonImmutable $now): GradebookCalculator
    {
        return new GradebookCalculator($categories, GradeCutoffs::defaults(), $now);
    }
}
