<?php

namespace App\Domain\Assistant;

use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\AnswerKeys\AnswerKeyService;
use App\Domain\Assignments\AssignmentCreator;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Courses\CourseDocuments;
use App\Domain\Exams\ExamDocuments;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A file card (DESIGN §37.10): the teacher's attached files go to one of
 * the existing readers, which run in the queue and leave their result for
 * review in the usual screen:
 *
 * - answer_key: the key of a homework (an existing one, or a new
 *   "ไม่ใช้ใบงาน" draft, the worksheet mode (ข)), AnswerKeyService::request;
 * - exam: the questions of an exam (existing or a new draft exam),
 *   ExamDocuments::request;
 * - course / lesson_plan: a course or lesson plans, CourseDocuments::request,
 *   imported by the teacher in the import screen.
 *
 *   {kind: file, action, documents: [{id, name, page_count}], target:
 *    describe()|{new…}|{course_id, title}|{}, error?}
 */
final class FileCards
{
    public const ANSWER_KEY = 'answer_key';

    public const EXAM = 'exam';

    public const COURSE = 'course';

    public const LESSON_PLAN = 'lesson_plan';

    public const ACTIONS = [self::ANSWER_KEY, self::EXAM, self::COURSE, self::LESSON_PLAN];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalise(array $raw, User $teacher, string $message = ''): ?array
    {
        $action = in_array($raw['file_action'] ?? null, self::ACTIONS, true) ? $raw['file_action'] : null;
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT), is_array($raw['document_ids'] ?? null) ? $raw['document_ids'] : []))));
        $documents = SourceDocument::query()->where('uploaded_by', $teacher->id)->whereIn('id', $ids)->orderBy('id')->get(['id', 'original_name', 'page_count']);
        if ($action === null || $documents->isEmpty()) {
            return null;
        }
        $rawTarget = is_array($raw['target'] ?? null) ? $raw['target'] : [];
        // Gemini may leave the target out: the assignment the message names.
        if (in_array($action, [self::ANSWER_KEY, self::EXAM], true) && ! isset($rawTarget['assignment_id']) && ! isset($rawTarget['new_classroom_id'])) {
            $named = AssistantContext::namedAssignment($teacher, $message);
            if ($named !== null) {
                $rawTarget['assignment_id'] = $named;
            }
        }
        $target = match ($action) {
            self::ANSWER_KEY, self::EXAM => self::assignmentTarget($rawTarget, $teacher, $action),
            self::LESSON_PLAN => self::courseTarget($raw['course_id'] ?? null, $teacher),
            default => [],
        };

        return [
            'kind' => AssistantPlans::CARD_FILE,
            'action' => $action,
            'documents' => $documents->map(fn (SourceDocument $d) => ['id' => $d->id, 'name' => $d->original_name, 'page_count' => (int) $d->page_count])->values()->all(),
            'target' => $target,
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed> the result
     */
    public static function apply(User $teacher, array $card): array
    {
        AssistantPlans::assertTarget($card['target']);
        $input = ['document_ids' => array_column($card['documents'], 'id')];

        return match ($card['action']) {
            self::ANSWER_KEY => (function () use ($teacher, $card, $input) {
                $assignment = self::assignment($teacher, $card['target'], false);
                $outcome = app(AnswerKeyService::class)->request($teacher, $assignment, AnswerKeyResult::KIND_READ, $input);

                return ['assignment_id' => $assignment->id, 'title' => $assignment->title, 'cached' => $outcome['cached']];
            })(),
            self::EXAM => (function () use ($teacher, $card, $input) {
                $exam = self::assignment($teacher, $card['target'], true);
                $outcome = app(ExamDocuments::class)->request($exam, $teacher, $input);

                return ['assignment_id' => $exam->id, 'title' => $exam->title, 'cached' => $outcome['cached']];
            })(),
            default => (function () use ($teacher, $card, $input) {
                Gate::forUser($teacher)->authorize('create', Course::class);
                $outcome = app(CourseDocuments::class)->request($teacher, $input + ['purpose' => $card['action']]);

                return ['extraction_id' => $outcome['extraction']->id, 'course_id' => $card['target']['course_id'] ?? null, 'cached' => $outcome['cached']];
            })(),
        };
    }

    /**
     * An existing homework (answer key) or exam the teacher manages, or a
     * new draft one (a "ไม่ใช้ใบงาน" homework for an answer key).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function assignmentTarget(array $raw, User $teacher, string $action): array
    {
        if (! isset($raw['assignment_id']) && isset($raw['new_classroom_id'])) {
            $raw['new_kind'] = $action === self::EXAM ? AssistantPlans::KIND_EXAM : AssistantPlans::KIND_FREEFORM;
        }
        $target = AssistantPlans::target($raw, $teacher);
        $exam = ($target['kind'] ?? null) === AssistantPlans::KIND_EXAM;
        if (! isset($target['error']) && $exam !== ($action === self::EXAM)) {
            $target['error'] = $action === self::EXAM ? 'งานนี้ไม่ใช่ข้อสอบ' : 'อ่านเฉลยจากไฟล์ใช้กับการบ้าน ไม่ใช่ข้อสอบ';
        }

        return $target;
    }

    /** @return array<string, mixed> */
    private static function courseTarget(mixed $id, User $teacher): array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        $course = $id === false ? null : Course::query()->where('school_id', $teacher->school_id)->where('created_by', $teacher->id)->find($id);

        return $course === null
            ? ['error' => 'เลือกรายวิชาที่จะเพิ่มแผนการสอน']
            : ['course_id' => $course->id, 'title' => trim($course->code.' '.$course->name)];
    }

    /** @param  array<string, mixed>  $target */
    private static function assignment(User $teacher, array $target, bool $exam): Assignment
    {
        if (isset($target['new'])) {
            $new = $target['new'];

            return app(AssignmentCreator::class)->create($teacher, [
                'classroom_id' => $new['classroom_id'],
                'course_id' => $new['course_id'],
                'title' => $new['title'],
                'kind' => $exam ? Assignment::KIND_EXAM : Assignment::KIND_HOMEWORK,
                'mode' => $exam ? Assignment::MODE_WORKSHEET : Assignment::MODE_FREEFORM,
                'send_to_google' => false,
            ]);
        }
        $assignment = ClassroomAccess::managedAssignments($teacher)->with('classroom')->findOrFail($target['assignment_id']);
        Gate::forUser($teacher)->authorize('update', $assignment);
        ClosedClassrooms::assertOpen($assignment->classroom);
        if ($assignment->isExam() !== $exam) {
            throw new ApiException($exam ? 'งานนี้ไม่ใช่ข้อสอบ' : 'อ่านเฉลยจากไฟล์ใช้กับการบ้าน', 'plan_no_target', 422);
        }

        return $assignment;
    }
}
