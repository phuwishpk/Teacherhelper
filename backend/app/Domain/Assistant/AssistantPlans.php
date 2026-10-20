<?php

namespace App\Domain\Assistant;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Exams\NumericAnswer;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\User;

/**
 * The plan card of an assistant answer (DESIGN §37.6): questions to create
 * in an assignment. Gemini's plan is checked here before it is stored, and
 * again against the real target when the teacher applies it:
 *
 *   target    {assignment_id?, new?: {classroom_id, course_id, title, kind},
 *              title, kind: worksheet|freeform|exam, classroom, status?, error?}
 *   questions [{type, prompt_text, options[], correct_options[] (1-based;
 *              true_false 1 = ถูก 2 = ผิด), accepted_answers[], numeric_value?,
 *              reference_steps[], model_answer?, points, explanation,
 *              indicator_codes[], needs_figure, flags[]}]
 *
 * Flags: needs_figure (a picture to add by hand), incomplete_key (no usable
 * key; open questions never need one), unsupported (the type, or more than
 * four homework options, does not fit the target). Only questions without
 * incomplete_key or unsupported are ticked at first.
 */
final class AssistantPlans
{
    public const MAX_QUESTIONS = 20;

    /** The kinds of card (DESIGN §37.6, §37.7). */
    public const CARD_QUESTIONS = 'questions';

    public const CARD_RUBRIC = 'rubric';

    public const CARD_INDICATORS = 'indicators';

    public const CARD_GRADEBOOK = 'gradebook';

    public const CARD_PRACTICE = 'practice';

    public const CARD_FILE = 'file';

    public const KIND_WORKSHEET = 'worksheet';

    public const KIND_FREEFORM = 'freeform';

    public const KIND_EXAM = 'exam';

    public const KINDS = [self::KIND_WORKSHEET, self::KIND_FREEFORM, self::KIND_EXAM];

    public const FLAG_FIGURE = 'needs_figure';

    public const FLAG_INCOMPLETE = 'incomplete_key';

    public const FLAG_UNSUPPORTED = 'unsupported';

    public const HOMEWORK_TYPES = [Question::TYPE_MCQ, Question::TYPE_SHORT, Question::TYPE_SHOW_WORK, Question::TYPE_OPEN];

    /** Homework mcq bubbles are A–D. */
    public const HOMEWORK_OPTIONS = 4;

    private const ALL_TYPES = [...self::HOMEWORK_TYPES, Question::TYPE_TRUE_FALSE, Question::TYPE_NUMERIC];

    /**
     * The card to store, or null when Gemini's plan has no usable question.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalise(array $raw, User $teacher, string $message = ''): ?array
    {
        return match ($raw['kind'] ?? self::CARD_QUESTIONS) {
            self::CARD_RUBRIC => RubricCards::normalise($raw, $teacher),
            self::CARD_INDICATORS => IndicatorCards::normalise($raw, $teacher),
            self::CARD_GRADEBOOK => GradebookCards::normalise($raw, $teacher),
            self::CARD_PRACTICE => PracticeCards::normalise($raw, $teacher),
            self::CARD_FILE => FileCards::normalise($raw, $teacher, $message),
            default => self::questionCard($raw, $teacher),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function questionCard(array $raw, User $teacher): ?array
    {
        $target = self::target(is_array($raw['target'] ?? null) ? $raw['target'] : [], $teacher);
        $questions = [];
        foreach (array_slice(is_array($raw['questions'] ?? null) ? $raw['questions'] : [], 0, self::MAX_QUESTIONS) as $q) {
            if (is_array($q) && ($question = self::question($q)) !== null) {
                $questions[] = $question;
            }
        }
        if ($questions === []) {
            return null;
        }
        $kind = $target['kind'] ?? null;
        foreach ($questions as &$question) {
            $question['flags'] = self::flags($question, $kind);
        }

        return ['kind' => self::CARD_QUESTIONS, 'target' => $target, 'questions' => $questions];
    }

    /**
     * The target of a card: an assignment the teacher manages and that is
     * not closed, or a new one in a classroom of theirs with a course of
     * theirs bound to it. Anything else keeps `error` so the card asks the
     * teacher to pick one.
     *
     * @param  array<string, mixed>  $raw  {assignment_id?} or {new_classroom_id, new_course_id?, new_title?, new_kind?} (Gemini) or {new: {…}} (the app)
     * @return array<string, mixed>
     */
    public static function target(array $raw, User $teacher): array
    {
        $id = filter_var($raw['assignment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id !== false) {
            $assignment = ClassroomAccess::managedAssignments($teacher)->with('classroom')->find($id);
            if ($assignment === null) {
                return ['error' => 'ไม่พบงานนี้ในงานที่ครูแก้ได้ เลือกงานเอง'];
            }

            return self::describe($assignment);
        }

        $new = is_array($raw['new'] ?? null) ? $raw['new'] : [
            'classroom_id' => $raw['new_classroom_id'] ?? null,
            'course_id' => $raw['new_course_id'] ?? null,
            'title' => $raw['new_title'] ?? null,
            'kind' => $raw['new_kind'] ?? null,
        ];
        $classroomId = filter_var($new['classroom_id'] ?? null, FILTER_VALIDATE_INT);
        if ($classroomId === false) {
            return ['error' => 'ยังไม่ได้เลือกงาน'];
        }
        $classroom = ClassroomAccess::classrooms($teacher)->open()->find($classroomId);
        if (! $classroom instanceof Classroom) {
            return ['error' => 'ไม่พบห้องนี้ในห้องที่ครูสอน เลือกงานเอง'];
        }
        $course = self::course($teacher, $classroom, $new['course_id'] ?? null);
        $kind = in_array($new['kind'] ?? null, self::KINDS, true) ? $new['kind'] : self::KIND_WORKSHEET;
        $title = mb_substr(trim((string) ($new['title'] ?? '')), 0, 255);

        return [
            'new' => ['classroom_id' => $classroom->id, 'course_id' => $course?->id, 'title' => $title === '' ? 'ชุดข้อใหม่' : $title, 'kind' => $kind],
            'title' => $title === '' ? 'ชุดข้อใหม่' : $title,
            'kind' => $kind,
            'classroom' => $classroom->name,
            'course' => $course === null ? null : trim($course->code.' '.$course->name),
            ...($course === null ? ['error' => 'ห้องนี้ยังไม่มีรายวิชาของครู ผูกรายวิชากับห้องก่อน หรือเลือกงานอื่น'] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function describe(Assignment $assignment): array
    {
        return [
            'assignment_id' => $assignment->id,
            'title' => $assignment->title,
            'kind' => self::kindOf($assignment),
            'classroom' => $assignment->classroom?->name,
            'status' => $assignment->status,
            ...($assignment->status === Assignment::STATUS_CLOSED ? ['error' => 'งานนี้ปิดแล้ว เพิ่มข้อไม่ได้'] : []),
        ];
    }

    public static function kindOf(Assignment $assignment): string
    {
        return match (true) {
            $assignment->isExam() => self::KIND_EXAM,
            $assignment->isFreeform() => self::KIND_FREEFORM,
            default => self::KIND_WORKSHEET,
        };
    }

    /**
     * @param  array<string, mixed>  $question  a normalised question
     * @return list<string>
     */
    public static function flags(array $question, ?string $kind): array
    {
        $flags = [];
        if ($question['needs_figure']) {
            $flags[] = self::FLAG_FIGURE;
        }
        if ($kind !== null && self::unsupported($question, $kind) !== null) {
            $flags[] = self::FLAG_UNSUPPORTED;
        }
        if (! self::keyComplete($question)) {
            $flags[] = self::FLAG_INCOMPLETE;
        }

        return $flags;
    }

    /** Why the question cannot go into an assignment of $kind, or null. */
    public static function unsupported(array $question, string $kind): ?string
    {
        $type = $question['type'];
        if ($kind === self::KIND_EXAM) {
            return in_array($type, Question::EXAM_TYPES, true) ? null : 'ข้อสอบกระดาษรับเฉพาะปรนัย ถูก/ผิด และเติมตัวเลข';
        }
        if (! in_array($type, self::HOMEWORK_TYPES, true)) {
            return 'การบ้านไม่มีข้อถูก/ผิดหรือเติมตัวเลขแบบข้อสอบ';
        }
        if ($type === Question::TYPE_MCQ && count($question['options']) > self::HOMEWORK_OPTIONS) {
            return 'ข้อปรนัยของการบ้านมีได้ไม่เกิน 4 ตัวเลือก (A–D)';
        }

        return null;
    }

    /** @param  array<string, mixed>  $question */
    public static function keyComplete(array $question): bool
    {
        $correct = $question['correct_options'];

        return match ($question['type']) {
            Question::TYPE_MCQ => count($question['options']) >= ExamSection::MIN_OPTIONS && $correct !== []
                && max($correct) <= count($question['options']),
            Question::TYPE_TRUE_FALSE => count($correct) === 1 && in_array($correct[0], [1, 2], true),
            Question::TYPE_NUMERIC => $question['numeric_value'] !== null && self::numericNeeds($question['numeric_value']) !== null,
            Question::TYPE_SHORT, Question::TYPE_SHOW_WORK => $question['accepted_answers'] !== [] || $question['numeric_value'] !== null,
            default => true,
        };
    }

    /**
     * The digit block a canonical number needs: digits (without the "." and
     * a leading "0" of a fraction), sign and decimal point; null when it
     * does not fit ExamSection::MAX_DIGITS.
     *
     * @return array{digits: int, negative: bool, decimal: bool}|null
     */
    public static function numericNeeds(string $canonical): ?array
    {
        $negative = str_starts_with($canonical, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($canonical, '-'), 2), 2, '');
        if ($fraction !== '' && $integer === '0') {
            $integer = '';
        }
        $digits = max(1, strlen($integer) + strlen($fraction));

        return $digits > ExamSection::MAX_DIGITS ? null : ['digits' => $digits, 'negative' => $negative, 'decimal' => $fraction !== ''];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>|null
     */
    private static function question(array $q): ?array
    {
        $type = $q['type'] ?? null;
        $prompt = mb_substr(trim((string) ($q['prompt_text'] ?? '')), 0, 2000);
        if (! in_array($type, self::ALL_TYPES, true) || $prompt === '') {
            return null;
        }
        $options = $type === Question::TYPE_MCQ ? array_slice(self::strings($q['options'] ?? [], 500), 0, ExamSection::MAX_OPTIONS) : [];
        $limit = $type === Question::TYPE_TRUE_FALSE ? 2 : count($options);
        $correct = [];
        foreach (is_array($q['correct_options'] ?? null) ? $q['correct_options'] : [] as $c) {
            $c = filter_var($c, FILTER_VALIDATE_INT);
            if ($c !== false && $c >= 1 && $c <= $limit && ! in_array($c, $correct, true)) {
                $correct[] = $c;
            }
        }
        sort($correct);
        $numeric = is_scalar($q['numeric_value'] ?? null) ? NumericAnswer::canonical(str_replace(',', '', trim((string) $q['numeric_value']))) : null;
        $points = filter_var($q['points'] ?? null, FILTER_VALIDATE_FLOAT);

        return [
            'type' => $type,
            'prompt_text' => $prompt,
            'options' => $options,
            'correct_options' => in_array($type, [Question::TYPE_MCQ, Question::TYPE_TRUE_FALSE], true) ? $correct : [],
            'accepted_answers' => in_array($type, [Question::TYPE_SHORT, Question::TYPE_SHOW_WORK], true) ? array_slice(self::strings($q['accepted_answers'] ?? [], 255), 0, 20) : [],
            'numeric_value' => in_array($type, [Question::TYPE_SHORT, Question::TYPE_SHOW_WORK, Question::TYPE_NUMERIC], true) ? $numeric : null,
            'reference_steps' => $type === Question::TYPE_SHOW_WORK ? array_slice(self::strings($q['reference_steps'] ?? [], 500), 0, 10) : [],
            'model_answer' => $type === Question::TYPE_OPEN ? (mb_substr(trim((string) ($q['model_answer'] ?? '')), 0, 4000) ?: null) : null,
            'points' => $points === false ? 1.0 : round(min(100, max(0.25, $points)), 2),
            'explanation' => mb_substr(trim((string) ($q['explanation'] ?? '')), 0, 600),
            'indicator_codes' => array_slice(self::strings($q['indicator_codes'] ?? [], 40), 0, 5),
            'needs_figure' => (bool) ($q['needs_figure'] ?? false),
        ];
    }

    private static function course(User $teacher, Classroom $classroom, mixed $courseId): ?Course
    {
        $courses = Course::query()
            ->where('created_by', $teacher->id)
            ->whereHas('classrooms', fn ($q) => $q->whereKey($classroom->id))
            ->orderByDesc('id');
        $id = filter_var($courseId, FILTER_VALIDATE_INT);

        return ($id === false ? null : (clone $courses)->find($id)) ?? $courses->first();
    }

    /** @return list<string> */
    private static function strings(mixed $values, int $max): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $v) {
            $v = is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @throws ApiException 422 plan_no_target */
    public static function assertTarget(array $target): void
    {
        if (isset($target['error'])) {
            throw new ApiException((string) $target['error'], 'plan_no_target', 422);
        }
    }
}
