<?php

namespace App\Domain\Gradebook;

use App\Events\GradesPublished;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "ประกาศเกรด" of one classroom (DESIGN §23.7): a snapshot of the live
 * values (categories, weights, cutoffs, one row per student) that the
 * students see until the teacher publishes again or withdraws it. Later
 * score changes never touch a snapshot; the teacher's grid shows `stale`.
 */
final class GradebookPublisher
{
    /**
     * @return array{publication_id: int, published_at: string, student_count: int}
     *
     * @throws ApiException 409 gradebook_not_configured, 422 gradebook_incomplete
     */
    public static function publish(Course $course, Classroom $classroom, User $teacher): array
    {
        $publication = DB::transaction(function () use ($course, $classroom, $teacher) {
            // One publish at a time per course: settings and scores are read under the lock.
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            GradebookAccess::assertConfigured($course);
            $book = ClassroomGradebook::of($course, $classroom);
            if (! $book->complete()) {
                $names = $book->missingCategoryNames();
                $message = 'ยังประกาศเกรดไม่ได้ หมวดต่อไปนี้ยังไม่มีรายการที่นับคะแนน: '.implode(', ', $names);

                throw new ApiException($message, 'gradebook_incomplete', 422, ['categories' => $names]);
            }

            $publication = GradebookPublication::create([
                'course_id' => $course->id,
                'classroom_id' => $classroom->id,
                'categories' => $book->categories->map(fn (GradebookCategory $c) => [
                    'id' => $c->id, 'name' => $c->name, 'weight' => $c->weight, 'drop_lowest' => $c->drop_lowest,
                ])->values()->all(),
                'cutoffs' => $book->cutoffs,
                'published_by' => $teacher->id,
                'published_at' => now(),
            ]);
            foreach ($book->snapshotRows() as $studentId => $row) {
                GradebookPublishedGrade::create(['publication_id' => $publication->id, 'student_id' => $studentId] + $row);
            }

            return $publication;
        });

        $studentIds = GradebookPublishedGrade::query()->where('publication_id', $publication->id)->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        GradesPublished::dispatch($publication->id);

        return [
            'publication_id' => $publication->id,
            'published_at' => $publication->published_at->toIso8601String(),
            'student_count' => count($studentIds),
        ];
    }

    /**
     * Withdraws the latest publication that is still up; the students see
     * the one before (if any).
     *
     * @throws ApiException 404 when nothing is published
     */
    public static function withdraw(Course $course, Classroom $classroom): void
    {
        DB::transaction(function () use ($course, $classroom) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            $publication = self::current($course->id, $classroom->id);
            if ($publication === null) {
                throw new ApiException('ห้องนี้ยังไม่มีเกรดที่ประกาศอยู่', 'not_found', 404);
            }
            $publication->forceFill(['withdrawn_at' => now()])->save();
        });
    }

    /** The latest publication of the classroom that was not withdrawn. */
    public static function current(int $courseId, int $classroomId): ?GradebookPublication
    {
        return GradebookPublication::query()
            ->where('course_id', $courseId)->where('classroom_id', $classroomId)->whereNull('withdrawn_at')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->first();
    }

    /** "มีการเปลี่ยนแปลงหลังประกาศ": the live values differ from the snapshot (§23.7). */
    public static function stale(GradebookPublication $publication, ClassroomGradebook $book): bool
    {
        if (! $book->complete()) {
            return true;
        }
        $stored = GradebookPublishedGrade::query()->where('publication_id', $publication->id)->get()
            ->mapWithKeys(fn (GradebookPublishedGrade $g) => [$g->student_id => [
                'breakdown' => $g->breakdown,
                'total' => $g->total,
                'total_rounded' => $g->total_rounded,
                'grade' => $g->grade,
                'special' => $g->special,
                'attendance_warning' => $g->attendance_warning,
            ]])->all();
        $live = $book->snapshotRows();
        ksort($stored);
        ksort($live);

        return self::normalise($stored) !== self::normalise($live)
            || self::normalise($publication->cutoffs) !== self::normalise($book->cutoffs);
    }

    /** Numbers as 4-decimal floats and bools as bools, so JSON round trips compare equal. */
    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => self::normalise($v), $value);
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 4);
        }
        if (is_string($value) && is_numeric($value)) {
            return round((float) $value, 4);
        }

        return $value;
    }
}
