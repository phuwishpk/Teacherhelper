<?php

namespace App\Domain\Students;

use App\Domain\Gradebook\StudentPublishedGrades;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookPublishedGrade;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The student's one-page view (DESIGN §24.11, GET /student/overview): every
 * classroom of the account and one group per (course, classroom), each with
 * its classroom label, what is left to hand in, the published results and
 * the published grade.
 *
 * A group exists for every course bound to a classroom of the student
 * (course_classroom), even before any work, and for older work without a
 * course one group per (subject, classroom) that has something to show
 * ("อื่นๆ ({ชื่อวิชา})" in the app). Each classroom keeps its own rules:
 * nothing is to do in a closed classroom (read-only, §24.6), results count
 * only once published (§13) and the grade is the current publication only
 * (§23.12). Never anything of a classmate.
 */
final class StudentOverview
{
    /**
     * @return array{classrooms: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public static function for(User $student): array
    {
        $rooms = StudentClassrooms::of($student)->keyBy('id');
        $roomIds = $rooms->keys()->all();
        if ($roomIds === []) {
            return ['classrooms' => [], 'groups' => []];
        }

        /** @var array<string, array{course_id: int|null, subject_id: int|null, classroom_id: int, todo: int, results: int, latest: Carbon|null, grade: GradebookPublishedGrade|null}> $groups */
        $groups = [];
        $group = function (?int $courseId, ?int $subjectId, int $classroomId) use (&$groups): string {
            $key = $courseId !== null ? "c{$courseId}:{$classroomId}" : "s{$subjectId}:{$classroomId}";
            $groups[$key] ??= ['course_id' => $courseId, 'subject_id' => $courseId === null ? $subjectId : null, 'classroom_id' => $classroomId, 'todo' => 0, 'results' => 0, 'latest' => null, 'grade' => null];

            return $key;
        };

        // Every course taught in the student's classrooms.
        foreach (DB::table('course_classroom')->whereIn('classroom_id', $roomIds)->orderBy('course_id')->get(['course_id', 'classroom_id']) as $bound) {
            $group((int) $bound->course_id, null, (int) $bound->classroom_id);
        }

        // To hand in: ready homework without a hand-in yet that still takes one.
        $handedIn = Submission::query()->where('student_id', $student->id)->select('assignment_id');
        $todo = Assignment::query()
            ->whereIn('classroom_id', $roomIds)
            ->where('status', Assignment::STATUS_READY)
            ->where('kind', Assignment::KIND_HOMEWORK)
            ->whereNotIn('id', $handedIn)
            ->get(['id', 'classroom_id', 'course_id', 'subject_id', 'due_at', 'accept_late']);
        foreach ($todo as $assignment) {
            $assignment->setRelation('classroom', $rooms->get($assignment->classroom_id));
            if (StudentHandIn::canSubmit($assignment)) {
                $groups[$group($assignment->course_id, $assignment->subject_id, (int) $assignment->classroom_id)]['todo']++;
            }
        }

        // Published results, in the student's classrooms.
        $results = Submission::query()
            ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->where('submissions.student_id', $student->id)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->whereIn('assignments.classroom_id', $roomIds)
            ->get(['assignments.classroom_id', 'assignments.course_id', 'assignments.subject_id', 'submissions.published_at']);
        foreach ($results as $row) {
            $key = $group($row->course_id === null ? null : (int) $row->course_id, $row->subject_id === null ? null : (int) $row->subject_id, (int) $row->classroom_id);
            $groups[$key]['results']++;
            $at = $row->published_at === null ? null : Carbon::parse($row->published_at);
            if ($at !== null && ($groups[$key]['latest'] === null || $at->greaterThan($groups[$key]['latest']))) {
                $groups[$key]['latest'] = $at;
            }
        }

        // The current published grade of each (course, classroom).
        foreach (StudentPublishedGrades::current($student->id) as $grade) {
            $publication = $grade->publication;
            if ($rooms->has($publication->classroom_id)) {
                $key = $group($publication->course_id, null, $publication->classroom_id);
                $groups[$key]['grade'] ??= $grade;
            }
        }

        $courses = Course::query()
            ->whereIn('id', array_filter(array_column($groups, 'course_id')))
            ->with(['subject:id,code,name', 'creator:id,name'])
            ->get()
            ->keyBy('id');
        $subjects = Subject::query()
            ->whereIn('id', array_filter(array_column($groups, 'subject_id')))
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $homeroomNames = User::query()->whereIn('id', $rooms->pluck('teacher_id')->unique()->all())->pluck('name', 'id');

        $out = collect($groups)->map(function (array $g) use ($rooms, $courses, $subjects, $homeroomNames) {
            /** @var Classroom $room */
            $room = $rooms->get($g['classroom_id']);
            $course = $g['course_id'] === null ? null : $courses->get($g['course_id']);
            $subject = $course !== null ? $course->subject : ($g['subject_id'] === null ? null : $subjects->get($g['subject_id']));
            $teacherName = $course !== null ? $course->creator?->name : $homeroomNames->get($room->teacher_id);
            $grade = $g['grade'];

            return [
                'course' => StudentClassrooms::course($course),
                'subject' => $subject === null ? null : ['id' => $subject->id, 'code' => (string) $subject->code, 'name' => (string) $subject->name],
                'classroom' => StudentClassrooms::label($room),
                'teacher_name' => $teacherName,
                'todo_count' => $g['todo'],
                'results_count' => $g['results'],
                'latest_published_at' => $g['latest']?->toIso8601String(),
                'grade' => $grade === null ? null : ['grade' => $grade->grade, 'special' => $grade->special],
                // Sort keys, dropped below.
                '_sort' => [
                    $room->isClosed() ? 1 : 0,
                    $course === null ? 1 : 0,
                    $course !== null ? (string) $course->code : (string) ($subject->name ?? ''),
                    -(int) $room->academic_year,
                    (string) $room->name,
                    $room->id,
                    $course->id ?? 0,
                ],
            ];
        })
            ->sortBy('_sort')
            ->map(function (array $row) {
                unset($row['_sort']);

                return $row;
            })
            ->values()
            ->all();

        return [
            'classrooms' => $rooms->values()->map(fn (Classroom $room) => StudentClassrooms::label($room))->all(),
            'groups' => $out,
        ];
    }
}
