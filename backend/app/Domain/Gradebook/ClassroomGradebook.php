<?php

namespace App\Domain\Gradebook;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use App\Models\GradebookSpecialGrade;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The live gradebook of one classroom in one course (DESIGN §23.3, §23.4):
 * loads the course's categories, the classroom's assignments of the course
 * (their published submissions) and items, the typed entries, ร/มส and the
 * roster, and runs GradebookCalculator. Nothing is stored; the teacher
 * always sees values computed "now".
 */
final class ClassroomGradebook
{
    /** @var Collection<int, GradebookCategory> */
    public readonly Collection $categories;

    /** @var list<GradebookColumn> */
    public readonly array $columns;

    /** @var Collection<int, User> roster in student_number order, pivot loaded */
    public readonly Collection $students;

    /** @var array<int, array{special: string, note: string|null}> */
    public readonly array $specials;

    /** @var array{complete: bool, missing_categories: list<int>, counted_weight: float, counted: array<string, bool>, rows: array<int, array<string, mixed>>} */
    public readonly array $result;

    /** @var list<int> */
    public readonly array $cutoffs;

    private function __construct(
        public readonly Course $course,
        public readonly Classroom $classroom,
        public readonly CarbonInterface $now,
    ) {
        $this->categories = $course->gradebookCategories()->get();
        $this->cutoffs = GradeCutoffs::of($course);
        $this->students = $classroom->students()->get();
        $studentIds = $this->students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->specials = GradebookSpecialGrade::query()
            ->where('course_id', $course->id)->where('classroom_id', $classroom->id)->get()
            ->mapWithKeys(fn (GradebookSpecialGrade $g) => [$g->student_id => ['special' => $g->special, 'note' => $g->note]])
            ->all();

        [$columns, $cells] = $this->load($studentIds);
        $this->columns = $columns;

        $calculator = new GradebookCalculator(
            $this->categories->map(fn (GradebookCategory $c) => new GradebookCategorySpec($c->id, $c->name, $c->weight, $c->drop_lowest))->values()->all(),
            $this->cutoffs,
            $now,
        );
        $this->result = $calculator->compute($columns, $studentIds, $cells, array_map(fn (array $s) => $s['special'], $this->specials));
    }

    public static function of(Course $course, Classroom $classroom, ?CarbonInterface $now = null): self
    {
        return new self($course, $classroom, $now ?? now());
    }

    public function configured(): bool
    {
        return $this->categories->isNotEmpty();
    }

    public function complete(): bool
    {
        return $this->result['complete'];
    }

    /** @return list<string> names of the categories without a counted item in the classroom */
    public function missingCategoryNames(): array
    {
        $missing = array_flip($this->result['missing_categories']);

        return $this->categories->filter(fn (GradebookCategory $c) => isset($missing[$c->id]))->pluck('name')->values()->all();
    }

    /**
     * GET /courses/{id}/gradebook?classroom_id= (§23.11).
     *
     * @return array<string, mixed>
     */
    public function grid(): array
    {
        $base = [
            'course' => ['id' => $this->course->id, 'code' => $this->course->code, 'name' => $this->course->name],
            'classroom' => ['id' => $this->classroom->id, 'name' => $this->classroom->name],
            'configured' => $this->configured(),
        ];
        $counted = $this->result['counted'];
        $columns = array_map(fn (GradebookColumn $c) => [
            'key' => $c->key,
            'type' => $c->type,
            'id' => $c->id,
            'name' => $c->name,
            'kind' => $c->kind,
            'category_id' => $c->categoryId,
            'full_marks' => round($c->fullMarks, 2),
            'counted' => $counted[$c->key] ?? false,
            'due_at' => $c->dueAt?->toIso8601String(),
            'excluded_from_grade' => $c->excluded,
            'is_attendance' => $c->isAttendance,
            'editable' => $c->scoreEditable(),
        ], $this->columns);

        if (! $this->configured()) {
            return $base + [
                'complete' => false,
                'missing_categories' => [],
                'counted_weight' => 0.0,
                'categories' => [],
                'columns' => $columns,
                'rows' => $this->students->map(fn (User $s) => $this->studentHead($s))->values()->all(),
                'publication' => null,
            ];
        }

        $missing = array_flip($this->result['missing_categories']);
        $rows = [];
        foreach ($this->students as $student) {
            $row = $this->result['rows'][$student->id];
            $rows[] = $this->studentHead($student) + [
                'cells' => (object) $row['cells'],
                'categories' => (object) $row['categories'],
                'total' => $row['total'],
                'total_rounded' => $row['total_rounded'],
                'grade' => $row['grade'],
                'special' => $row['special'],
                'special_note' => $this->specials[$student->id]['note'] ?? null,
                'attendance_warning' => $row['attendance_warning'],
                'in_progress' => $row['in_progress'],
                'counted_weight' => $row['counted_weight'],
            ];
        }
        $publication = GradebookPublisher::current($this->course->id, $this->classroom->id);

        return $base + [
            'complete' => $this->complete(),
            'missing_categories' => $this->missingCategoryNames(),
            'counted_weight' => $this->result['counted_weight'],
            'categories' => $this->categories->map(fn (GradebookCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'weight' => $c->weight,
                'drop_lowest' => $c->drop_lowest,
                'has_items' => ! isset($missing[$c->id]),
            ])->values()->all(),
            'columns' => $columns,
            'rows' => $rows,
            'publication' => $publication === null ? null : [
                'id' => $publication->id,
                'published_at' => $publication->published_at->toIso8601String(),
                'stale' => GradebookPublisher::stale($publication, $this),
            ],
        ];
    }

    /**
     * The snapshot row of each student (§23.7, `gradebook_published_grades`):
     * breakdown per category with its counted items.
     *
     * @return array<int, array{breakdown: list<array<string, mixed>>, total: float|null, total_rounded: int|null, grade: float|null, special: string|null, attendance_warning: bool}>
     */
    public function snapshotRows(): array
    {
        $counted = $this->result['counted'];
        $out = [];
        foreach ($this->students as $student) {
            $row = $this->result['rows'][$student->id];
            $breakdown = [];
            foreach ($this->categories as $category) {
                $items = [];
                foreach ($this->columns as $column) {
                    if ($column->categoryId !== $category->id || ! ($counted[$column->key] ?? false)) {
                        continue;
                    }
                    $cell = $row['cells'][$column->key];
                    $items[] = [
                        'name' => $column->name,
                        'score' => $cell['score'],
                        'max' => round($column->fullMarks, 2),
                        'percent' => $cell['percent'],
                        'state' => $cell['state'],
                        'dropped' => $cell['dropped'],
                    ];
                }
                $values = $row['categories'][$category->id];
                $breakdown[] = [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'weight' => $category->weight,
                    'percent' => $values['percent'],
                    'points' => $values['points'],
                    'items' => $items,
                ];
            }
            $out[$student->id] = [
                'breakdown' => $breakdown,
                'total' => $row['total'],
                'total_rounded' => $row['total_rounded'],
                'grade' => $row['grade'],
                'special' => $row['special'],
                'attendance_warning' => $row['attendance_warning'],
            ];
        }

        return $out;
    }

    /** @return array{student_id: int, student_number: int, name: string, left_course: bool} */
    private function studentHead(User $student): array
    {
        return [
            'student_id' => $student->id,
            'student_number' => (int) $student->pivot->student_number,
            'name' => $student->name,
            'left_course' => $student->pivot->left_course_at !== null,
        ];
    }

    /**
     * @param  list<int>  $studentIds
     * @return array{0: list<GradebookColumn>, 1: array<int, array<string, GradebookCell>>}
     */
    private function load(array $studentIds): array
    {
        $roster = array_flip($studentIds);
        $positions = $this->categories->mapWithKeys(fn (GradebookCategory $c) => [$c->id => $c->position])->all();
        $assignments = Assignment::query()
            ->where('course_id', $this->course->id)
            ->where('classroom_id', $this->classroom->id)
            ->withSum('questions', 'max_points')
            ->get();
        $items = GradebookItem::query()->where('course_id', $this->course->id)->where('classroom_id', $this->classroom->id)->get();
        $submissions = Submission::query()->whereIn('assignment_id', $assignments->pluck('id'))
            ->get(['id', 'assignment_id', 'student_id', 'status', 'total_score', 'total_override']);
        $entries = GradebookEntry::query()->where('classroom_id', $this->classroom->id)
            ->where(fn ($q) => $q->whereIn('assignment_id', $assignments->pluck('id'))->orWhereIn('gradebook_item_id', $items->pluck('id')))
            ->get();

        $cells = [];
        $anyPublished = [];
        foreach ($submissions as $submission) {
            if (! isset($roster[$submission->student_id])) {
                continue;
            }
            $key = GradebookColumn::assignmentKey($submission->assignment_id);
            $published = $submission->isPublished();
            $anyPublished[$key] = ($anyPublished[$key] ?? false) || $published;
            $cells[$submission->student_id][$key] = new GradebookCell(
                score: $published ? $submission->effectiveTotal() : null,
                hasSubmission: true,
                published: $published,
                submissionId: $submission->id,
            );
        }
        $anyScored = [];
        foreach ($entries as $entry) {
            if (! isset($roster[$entry->student_id])) {
                continue;
            }
            $key = $entry->assignment_id !== null ? GradebookColumn::assignmentKey($entry->assignment_id) : GradebookColumn::itemKey((int) $entry->gradebook_item_id);
            if ($entry->score !== null) {
                $anyScored[$key] = true;
            }
            $existing = $cells[$entry->student_id][$key] ?? new GradebookCell;
            $cells[$entry->student_id][$key] = new GradebookCell(
                score: $existing->hasSubmission ? $existing->score : $entry->score,
                excused: $entry->excused,
                hasSubmission: $existing->hasSubmission,
                published: $existing->published,
                submissionId: $existing->submissionId,
            );
        }

        $columns = [];
        foreach ($assignments as $a) {
            $key = GradebookColumn::assignmentKey($a->id);
            $manual = $a->isManualExam();
            $columns[] = new GradebookColumn(
                key: $key,
                type: $manual ? GradebookColumn::MANUAL_EXAM : GradebookColumn::ASSIGNMENT,
                id: $a->id,
                name: $a->title,
                categoryId: $a->gradebook_category_id,
                fullMarks: $manual ? (float) ($a->manual_full_marks ?? 0) : round((float) ($a->questions_sum_max_points ?? 0), 2),
                excluded: $a->excluded_from_grade,
                dueAt: $a->due_at,
                closed: $a->isClosed(),
                anyPublished: ! $manual && ($anyPublished[$key] ?? false),
                anyScored: $manual && ($anyScored[$key] ?? false),
                kind: $a->kind,
                createdAt: $a->created_at,
            );
        }
        foreach ($items as $item) {
            $key = GradebookColumn::itemKey($item->id);
            $columns[] = new GradebookColumn(
                key: $key,
                type: GradebookColumn::CUSTOM,
                id: $item->id,
                name: $item->name,
                categoryId: $item->category_id,
                fullMarks: $item->max_points,
                isAttendance: $item->is_attendance,
                anyScored: $anyScored[$key] ?? false,
                createdAt: $item->created_at,
            );
        }
        // By category, then date (§23.3); a column without a category goes last.
        usort($columns, function (GradebookColumn $a, GradebookColumn $b) use ($positions) {
            $date = fn (GradebookColumn $c) => ($c->dueAt ?? $c->createdAt)?->getTimestamp() ?? 0;

            return [$positions[$a->categoryId ?? 0] ?? PHP_INT_MAX, $date($a), $a->type === GradebookColumn::CUSTOM ? 1 : 0, $a->id]
                <=> [$positions[$b->categoryId ?? 0] ?? PHP_INT_MAX, $date($b), $b->type === GradebookColumn::CUSTOM ? 1 : 0, $b->id];
        });

        return [$columns, $cells];
    }
}
