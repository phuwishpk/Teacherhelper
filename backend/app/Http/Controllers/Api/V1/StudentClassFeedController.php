<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Students\StudentClassrooms;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's class feed (DESIGN §30.3): the posts they receive, the
 * homework open to them and their published results, as one list, newest
 * first. Each item is {type, at, classroom, course, topic, ...}: `post`
 * carries the post, `assignment` and `result` the ids the existing pages
 * open with. The app shows it as a stream and, grouped by topic, as
 * "งานของชั้นเรียน".
 */
class StudentClassFeedController extends Controller
{
    public const POSTS = 100;

    public const WORK = 50;

    /** GET /api/v1/student/class-feed?classroom_id=&course_id= -> {data: [item]} */
    public function index(Request $request): JsonResponse
    {
        $student = $request->user();
        $filters = StudentClassrooms::filters($request);

        $posts = ClassPosts::forStudent($student)
            ->when($filters['classroom_id'] !== null, fn (Builder $q) => $q->where('class_posts.classroom_id', $filters['classroom_id']))
            // A post without a course (the homeroom teacher's) belongs on every course page of its classroom.
            ->when($filters['course_id'] !== null, fn (Builder $q) => $q->where(fn (Builder $c) => $c
                ->where('class_posts.course_id', $filters['course_id'])->orWhereNull('class_posts.course_id')))
            ->with(ClassPosts::RELATIONS)
            ->orderByDesc('class_posts.published_at')->orderByDesc('class_posts.id')
            ->limit(self::POSTS)
            ->get();

        $rooms = ClassroomStudent::query()->select('classroom_id')->where('student_id', $student->id);
        $scope = fn (Builder $q) => $q
            ->whereIn('classroom_id', $rooms)
            ->reaching((int) $student->id)
            // Exams are done on paper only (DESIGN §22.1); their results still show.
            ->when($filters['course_id'] !== null, fn (Builder $a) => $a->where('course_id', $filters['course_id']))
            ->when($filters['classroom_id'] !== null, fn (Builder $a) => $a->where('classroom_id', $filters['classroom_id']));
        $assignments = Assignment::query()
            ->where('status', Assignment::STATUS_READY)
            ->where('kind', Assignment::KIND_HOMEWORK)
            ->tap($scope)
            ->with(['classroom:id,name,academic_year,closed_at', 'course:id,code,name', 'unit:id,title'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::WORK)
            ->get();
        $results = Submission::query()
            ->where('student_id', $student->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->whereIn('assignment_id', Assignment::query()->select('id')->tap($scope))
            ->with(['assignment:id,title,classroom_id,course_id', 'assignment.classroom:id,name,academic_year,closed_at', 'assignment.course:id,code,name'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::WORK)
            ->get();

        $items = [];
        foreach ($posts as $post) {
            $payload = ClassPosts::payload($post, $student);
            $items[] = [
                'type' => 'post',
                'at' => $payload['published_at'],
                'classroom' => $payload['classroom'],
                'course' => $payload['course'],
                'topic' => $payload['topic'],
                'post' => $payload,
            ];
        }
        foreach ($assignments as $assignment) {
            $items[] = [
                'type' => 'assignment',
                'at' => $assignment->created_at?->toIso8601String(),
                'classroom' => StudentClassrooms::label($assignment->classroom),
                'course' => StudentClassrooms::course($assignment->course),
                'topic' => $assignment->topicName(),
                'assignment' => [
                    'id' => $assignment->id,
                    'title' => (string) $assignment->title,
                    'due_at' => $assignment->due_at?->toIso8601String(),
                ],
            ];
        }
        foreach ($results as $submission) {
            $items[] = [
                'type' => 'result',
                'at' => $submission->published_at?->toIso8601String(),
                'classroom' => StudentClassrooms::label($submission->assignment?->classroom),
                'course' => StudentClassrooms::course($submission->assignment?->course),
                'topic' => null,
                'result' => [
                    'submission_id' => $submission->id,
                    'assignment_id' => $submission->assignment_id,
                    'title' => (string) $submission->assignment?->title,
                ],
            ];
        }
        usort($items, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return response()->json(['data' => $items]);
    }

    /** GET /api/v1/student/class-posts/{id} -> {data: post}; 404 unless the post reaches the student. */
    public function show(Request $request, int $id): JsonResponse
    {
        $post = ClassPosts::forStudent($request->user())->with(ClassPosts::RELATIONS)->findOrFail($id);

        return response()->json(['data' => ClassPosts::payload($post, $request->user())]);
    }
}
