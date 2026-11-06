<?php

namespace App\Domain\Gradebook;

use Carbon\CarbonInterface;

/**
 * One column of a classroom's gradebook (DESIGN §23.3): an assignment graded
 * by the app, a manual exam, or an item the teacher added. The class-level
 * facts decide whether it is counted yet (§23.4).
 */
final readonly class GradebookColumn
{
    public const ASSIGNMENT = 'assignment';

    public const MANUAL_EXAM = 'manual_exam';

    public const CUSTOM = 'custom';

    /**
     * @param  string  $type  assignment|manual_exam|custom
     * @param  float  $fullMarks  Σ questions.max_points, manual_full_marks or max_points
     * @param  bool  $anyPublished  an app-graded assignment has a published submission in the classroom
     * @param  bool  $anyScored  a manual exam or item has a typed score in the classroom
     */
    public function __construct(
        public string $key,
        public string $type,
        public int $id,
        public string $name,
        public ?int $categoryId,
        public float $fullMarks,
        public bool $excluded = false,
        public ?CarbonInterface $dueAt = null,
        public bool $closed = false,
        public bool $isAttendance = false,
        public bool $anyPublished = false,
        public bool $anyScored = false,
        public ?string $kind = null,
        public ?CarbonInterface $createdAt = null,
        public bool $autoAttendance = false,
    ) {}

    public static function assignmentKey(int $id): string
    {
        return 'a'.$id;
    }

    public static function itemKey(int $id): string
    {
        return 'i'.$id;
    }

    /** "เลยกำหนด": due_at passed or the assignment was closed (§23.4). Items have no due date. */
    public function overdue(CarbonInterface $now): bool
    {
        if ($this->type === self::CUSTOM) {
            return false;
        }

        return ($this->dueAt !== null && $now->greaterThan($this->dueAt)) || $this->closed;
    }

    /** Counted in the formula of the classroom (§23.4 "รายการนับแล้วในห้อง"). */
    public function counted(CarbonInterface $now): bool
    {
        if ($this->excluded || $this->categoryId === null || $this->fullMarks <= 0) {
            return false;
        }

        return match ($this->type) {
            self::ASSIGNMENT => $this->overdue($now) || $this->anyPublished,
            self::MANUAL_EXAM => ($this->dueAt !== null && $now->greaterThan($this->dueAt)) || $this->anyScored,
            default => $this->anyScored,
        };
    }

    public function scoreEditable(): bool
    {
        // The "การเข้าเรียน" item is written from the attendance records (§29.5).
        return $this->type !== self::ASSIGNMENT && ! $this->autoAttendance;
    }
}
