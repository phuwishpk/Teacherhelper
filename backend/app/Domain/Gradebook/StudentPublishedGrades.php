<?php

namespace App\Domain\Gradebook;

use App\Models\GradebookPublishedGrade;
use Illuminate\Support\Collection;

/**
 * A student's own published grades (DESIGN §23.12, §24.11): their row of
 * the current (latest, not withdrawn) publication of each (course,
 * classroom), in every classroom they are or were in.
 */
final class StudentPublishedGrades
{
    /**
     * Newest first, with publication.course and publication.classroom loaded.
     *
     * @return Collection<int, GradebookPublishedGrade>
     */
    public static function current(int $studentId, ?int $courseId = null, ?int $classroomId = null): Collection
    {
        $rows = GradebookPublishedGrade::query()
            ->where('student_id', $studentId)
            ->whereHas('publication', function ($q) use ($courseId, $classroomId) {
                $q->whereNull('withdrawn_at');
                if ($courseId !== null) {
                    $q->where('course_id', $courseId);
                }
                if ($classroomId !== null) {
                    $q->where('classroom_id', $classroomId);
                }
            })
            ->with(['publication.course', 'publication.classroom'])
            ->get();

        $current = [];

        return $rows
            ->filter(function (GradebookPublishedGrade $row) use (&$current) {
                $p = $row->publication;
                $key = $p->course_id.':'.$p->classroom_id;
                $current[$key] ??= GradebookPublisher::current($p->course_id, $p->classroom_id)?->id;

                return $current[$key] === $p->id;
            })
            ->sortByDesc(fn (GradebookPublishedGrade $row) => $row->publication->published_at->getTimestamp())
            ->values();
    }
}
