<?php

namespace App\Domain\Gradebook;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use App\Models\GradebookSpecialGrade;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * "ตัดเกรด" (DESIGN §23.9, §23.11 GET /gradebook/overview): where each
 * classroom of each of the teacher's courses stands in the grading flow.
 * Every classroom runs through ClassroomGradebook (the grid's own
 * calculation), fed from one query per table for all courses at once, so
 * the request makes a fixed number of queries however many courses,
 * classrooms and students the teacher has.
 */
final class GradebookOverview
{
    public const NOT_CONFIGURED = 'not_configured';

    public const MISSING_SCORES = 'missing_scores';

    public const READY = 'ready';

    public const PUBLISHED = 'published';

    public const PUBLISHED_STALE = 'published_stale';

    /**
     * @param  Builder<Course>  $courses  the teacher's own courses, already filtered
     * @return list<array<string, mixed>>
     */
    public static function of(User $teacher, Builder $courses, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $courses = $courses
            ->with([
                'gradebookCategories',
                // Only the teacher's own classrooms, as GradebookAccess::classroom() requires.
                'classrooms' => fn ($q) => $q->where('classrooms.teacher_id', $teacher->id)
                    ->where('classrooms.school_id', $teacher->school_id)
                    ->orderBy('classrooms.name')->orderBy('classrooms.id'),
            ])
            ->orderByDesc('academic_year')->orderByDesc('semester')->orderBy('code')->orderBy('id')
            ->get();
        if ($courses->isEmpty()) {
            return [];
        }

        $courseIds = $courses->pluck('id')->all();
        $rooms = new EloquentCollection($courses->flatMap(fn (Course $c) => $c->classrooms->all())->unique('id')->values()->all());
        $classroomIds = $rooms->pluck('id')->all();
        $rosters = $rooms->load('students')->mapWithKeys(fn (Classroom $c) => [$c->id => $c->students]);

        $pair = fn (int $courseId, int $classroomId) => $courseId.':'.$classroomId;
        $specials = GradebookSpecialGrade::query()->whereIn('course_id', $courseIds)->whereIn('classroom_id', $classroomIds)->get()
            ->groupBy(fn (GradebookSpecialGrade $g) => $pair($g->course_id, $g->classroom_id));
        $assignments = Assignment::query()->whereIn('course_id', $courseIds)->whereIn('classroom_id', $classroomIds)
            ->withSum('questions', 'max_points')->get();
        $items = GradebookItem::query()->whereIn('course_id', $courseIds)->whereIn('classroom_id', $classroomIds)->get();
        $submissions = Submission::query()->whereIn('assignment_id', $assignments->pluck('id'))->get(ClassroomGradebook::SUBMISSION_COLUMNS)
            ->groupBy(fn (Submission $s) => (int) $s->assignment_id);
        $entries = GradebookEntry::query()->whereIn('classroom_id', $classroomIds)
            ->where(fn ($q) => $q->whereIn('assignment_id', $assignments->pluck('id'))->orWhereIn('gradebook_item_id', $items->pluck('id')))
            ->get();
        $entriesByAssignment = $entries->whereNotNull('assignment_id')->groupBy(fn (GradebookEntry $e) => (int) $e->assignment_id);
        $entriesByItem = $entries->whereNotNull('gradebook_item_id')->groupBy(fn (GradebookEntry $e) => (int) $e->gradebook_item_id);
        $assignmentsByPair = $assignments->groupBy(fn (Assignment $a) => $pair((int) $a->course_id, (int) $a->classroom_id));
        $itemsByPair = $items->groupBy(fn (GradebookItem $i) => $pair($i->course_id, $i->classroom_id));

        // The latest publication that is still up per classroom, as GradebookPublisher::current().
        $publications = GradebookPublication::query()->whereIn('course_id', $courseIds)->whereIn('classroom_id', $classroomIds)
            ->whereNull('withdrawn_at')->orderByDesc('published_at')->orderByDesc('id')->get()
            ->unique(fn (GradebookPublication $p) => $pair($p->course_id, $p->classroom_id))
            ->keyBy(fn (GradebookPublication $p) => $pair($p->course_id, $p->classroom_id));
        $publishedGrades = GradebookPublishedGrade::query()->whereIn('publication_id', $publications->pluck('id'))->get()
            ->groupBy(fn (GradebookPublishedGrade $g) => $g->publication_id);

        $out = [];
        foreach ($courses as $course) {
            $categories = $course->gradebookCategories;
            $configured = self::configured($categories);
            $classrooms = [];
            foreach ($course->classrooms as $classroom) {
                $key = $pair($course->id, $classroom->id);
                $roomAssignments = $assignmentsByPair->get($key, new EloquentCollection);
                $roomItems = $itemsByPair->get($key, new EloquentCollection);
                $book = ClassroomGradebook::fromLoaded(
                    $course,
                    $classroom,
                    $now,
                    $categories,
                    $rosters->get($classroom->id, new EloquentCollection),
                    $specials->get($key, new EloquentCollection),
                    $roomAssignments,
                    $roomItems,
                    self::pick($submissions, $roomAssignments->pluck('id')),
                    self::pick($entriesByAssignment, $roomAssignments->pluck('id'))
                        ->concat(self::pick($entriesByItem, $roomItems->pluck('id')))
                        ->filter(fn (GradebookEntry $e) => $e->classroom_id === $classroom->id)->values(),
                );
                $classrooms[] = self::classroom($book, $configured, $publications->get($key), $publishedGrades);
            }
            $out[] = [
                'id' => $course->id,
                'code' => $course->code,
                'name' => $course->name,
                'grade_level' => $course->grade_level,
                'semester' => $course->semester,
                'academic_year' => $course->academic_year,
                'configured' => $configured,
                'category_count' => $categories->count(),
                'classrooms' => $classrooms,
            ];
        }

        return $out;
    }

    /** Categories exist and their weights total 100 (§23.2). @param Collection<int, GradebookCategory> $categories */
    public static function configured(Collection $categories): bool
    {
        return $categories->isNotEmpty() && abs($categories->sum(fn (GradebookCategory $c) => $c->weight) - 100) < 0.005;
    }

    /**
     * @param  Collection<int, Collection<int, GradebookPublishedGrade>>  $publishedGrades  by publication id
     * @return array<string, mixed>
     */
    private static function classroom(ClassroomGradebook $book, bool $configured, ?GradebookPublication $publication, Collection $publishedGrades): array
    {
        $atRisk = 0;
        $specials = ['ร' => 0, 'มส' => 0];
        foreach ($book->result['rows'] as $row) {
            if ($configured && $row['attendance_warning']) {
                $atRisk++;
            }
            match ($row['special']) {
                'r' => $specials['ร']++,
                'ms' => $specials['มส']++,
                default => null,
            };
        }
        $stale = $configured && $publication !== null
            && GradebookPublisher::stale($publication, $book, $publishedGrades->get($publication->id, new EloquentCollection));
        $status = match (true) {
            ! $configured => self::NOT_CONFIGURED,
            $publication !== null => $stale ? self::PUBLISHED_STALE : self::PUBLISHED,
            ! $book->complete() => self::MISSING_SCORES,
            default => self::READY,
        };

        return [
            'id' => $book->classroom->id,
            'name' => $book->classroom->name,
            'student_count' => $book->students->count(),
            'status' => $status,
            'empty_categories' => $configured ? $book->missingCategoryNames() : [],
            'published_at' => $configured ? $publication?->published_at->toIso8601String() : null,
            'stale' => $stale,
            'at_risk_ms_count' => $atRisk,
            'special_counts' => $specials,
        ];
    }

    /**
     * The rows of the given keys from a grouped collection.
     *
     * @template T
     *
     * @param  Collection<int, Collection<int, T>>  $grouped
     * @param  Collection<int, mixed>  $keys
     * @return Collection<int, T>
     */
    private static function pick(Collection $grouped, Collection $keys): Collection
    {
        $out = new EloquentCollection;
        foreach ($keys as $key) {
            foreach ($grouped->get((int) $key, []) as $row) {
                $out->push($row);
            }
        }

        return $out;
    }
}
