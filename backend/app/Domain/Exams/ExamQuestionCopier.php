<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\AssignmentLocked;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Copying questions from the teacher's earlier exams (DESIGN §22.4 item 3).
 *
 * - library(): GET /teacher/exam-questions: questions of exams this teacher
 *   created (created_by, same school) only, never another teacher's,
 *   filtered by course, exam or text.
 * - copy(): POST /exams/{id}/copy-questions {question_ids[], section_id?}:
 *   the text, options, images (the files are copied), key, "ห้ามสลับ
 *   ตัวเลือก", points and the indicators the school still sees (of the
 *   exam's subject) are copied; a copy keeps the source's approval
 *   (origin copied, copied_from_question_id). Into a section: a question of
 *   another type, or an mcq with another number of options, is skipped and
 *   reported; numbers of the key that do not fit the section are dropped.
 *   Without section_id every source section becomes a new section with the
 *   source's settings. Structural: 409 exam_structure_locked once printed.
 */
final class ExamQuestionCopier
{
    public const MAX_COPY = 200;

    public const TYPE_MISMATCH = 'type_mismatch';

    public const OPTION_COUNT_MISMATCH = 'option_count_mismatch';

    private const REASONS_TH = [
        self::TYPE_MISMATCH => 'ชนิดของข้อไม่ตรงกับตอนปลายทาง',
        self::OPTION_COUNT_MISMATCH => 'จำนวนตัวเลือกไม่ตรงกับตอนปลายทาง',
    ];

    /**
     * Questions of the teacher's own exams, newest exam first, then by number.
     *
     * @param  array<string, mixed>  $filters  {course_id?, exam_id?, q?, exclude_exam?}
     * @return Builder<Question>
     *
     * @throws ValidationException
     */
    public static function library(User $teacher, array $filters): Builder
    {
        $v = Validator::make($filters, [
            'course_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'exam_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'exclude_exam' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], [
            'q.max' => 'คำค้นยาวได้ไม่เกิน 100 ตัวอักษร',
        ])->validate();

        $exams = self::ownExams($teacher)
            ->when(isset($v['course_id']), fn (Builder $q) => $q->where('course_id', (int) $v['course_id']))
            ->when(isset($v['exam_id']), fn (Builder $q) => $q->whereKey((int) $v['exam_id']))
            ->when(isset($v['exclude_exam']), fn (Builder $q) => $q->whereKeyNot((int) $v['exclude_exam']));
        $text = trim((string) ($v['q'] ?? ''));

        return Question::query()
            ->whereNotNull('section_id')
            ->whereIn('assignment_id', $exams->select('id'))
            ->when($text !== '', fn (Builder $q) => $q->where('prompt_text', 'like', '%'.addcslashes($text, '%_\\').'%'))
            ->with(['options', 'skills:id', 'section', 'assignment:id,title,course_id'])
            ->orderByDesc('assignment_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $input  {question_ids[], section_id?}
     * @return array{created: int, question_ids: list<int>, skipped: list<array{question_id: int, reason: string, reason_th: string}>}
     *
     * @throws ValidationException
     * @throws ApiException 409 exam_structure_locked, 422 too_many_questions / validation_failed
     */
    public function copy(Assignment $exam, User $teacher, array $input): array
    {
        $v = Validator::make($input, [
            'question_ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_COPY],
            'question_ids.*' => ['integer', 'distinct', 'min:1'],
            'section_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], [
            'question_ids.required' => 'กรุณาเลือกข้ออย่างน้อย 1 ข้อ',
            'question_ids.min' => 'กรุณาเลือกข้ออย่างน้อย 1 ข้อ',
            'question_ids.max' => 'คัดลอกได้ครั้งละไม่เกิน '.self::MAX_COPY.' ข้อ',
            'question_ids.*.distinct' => 'เลือกข้อซ้ำกัน',
            'question_ids.*.integer' => 'รหัสข้อไม่ถูกต้อง',
        ])->validate();
        $ids = array_map('intval', $v['question_ids']);
        $sources = Question::query()
            ->whereNotNull('section_id')
            ->whereIn('assignment_id', self::ownExams($teacher)->select('id'))
            ->whereKey($ids)
            ->with(['options', 'skills:id', 'section', 'assignment'])
            ->get()
            ->keyBy('id');
        $errors = [];
        foreach ($ids as $i => $id) {
            if (! $sources->has($id)) {
                $errors["question_ids.{$i}"] = ['ไม่พบข้อนี้ในข้อสอบของคุณ'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $ordered = array_map(fn (int $id) => $sources->get($id), $ids);
        $sectionId = isset($v['section_id']) ? (int) $v['section_id'] : null;

        return AssignmentLocked::run($exam->id, function (Assignment $exam) use ($ordered, $sectionId) {
            ExamEditor::assertUnlocked($exam);
            $append = new ExamAppend($exam);
            $skipped = [];
            /** @var list<array{0: ExamSection, 1: Question}> $plan */
            $plan = [];

            if ($sectionId !== null) {
                $target = ExamSection::query()->where('assignment_id', $exam->id)->find($sectionId);
                if ($target === null) {
                    throw ValidationException::withMessages(['section_id' => 'ไม่พบตอนนี้ในข้อสอบ']);
                }
                foreach ($ordered as $source) {
                    $reason = self::mismatch($source, $target);
                    if ($reason !== null) {
                        $skipped[] = ['question_id' => $source->id, 'reason' => $reason, 'reason_th' => self::REASONS_TH[$reason]];

                        continue;
                    }
                    $plan[] = [$target, $source];
                }
                self::assertRoom($append, count($plan));
            } else {
                $groups = collect($ordered)->groupBy('section_id');
                self::assertRoom($append, count($ordered));
                if ($groups->count() > $append->sectionRoom()) {
                    $message = 'ข้อสอบหนึ่งฉบับมีได้ไม่เกิน '.config('eduvision.exams.max_sections').' ตอน เลือกตอนปลายทางแทนการสร้างตอนใหม่';

                    throw new ApiException($message, 'validation_failed', 422, ['section_id' => [$message]]);
                }
                foreach ($groups as $questions) {
                    $section = $append->section(self::sectionCopy($questions->first()->section));
                    foreach ($questions as $source) {
                        $plan[] = [$section, $source];
                    }
                }
            }

            $created = [];
            foreach ($plan as [$section, $source]) {
                $created[] = $this->copyOne($exam, $append, $section, $source)->id;
            }
            if ($created !== []) {
                $append->finish();
            }

            return ['created' => count($created), 'question_ids' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Exams created by the teacher in their school.
     *
     * @return Builder<Assignment>
     */
    private static function ownExams(User $teacher): Builder
    {
        return Assignment::query()
            ->where('kind', Assignment::KIND_EXAM)
            ->where('school_id', $teacher->school_id)
            ->where('created_by', $teacher->id);
    }

    private static function mismatch(Question $source, ExamSection $target): ?string
    {
        if ($source->type !== $target->type) {
            return self::TYPE_MISMATCH;
        }
        if ($target->type === ExamSection::TYPE_MCQ && (int) $source->section?->option_count !== (int) $target->option_count) {
            return self::OPTION_COUNT_MISMATCH;
        }

        return null;
    }

    private static function assertRoom(ExamAppend $append, int $adding): void
    {
        $room = $append->questionRoom();
        if ($adding > $room) {
            $max = (int) config('eduvision.exams.max_questions');

            throw new ApiException("ข้อสอบหนึ่งฉบับมีได้ไม่เกิน {$max} ข้อ (เพิ่มได้อีก {$room} ข้อ)", 'too_many_questions', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function sectionCopy(ExamSection $source): array
    {
        return [
            'title' => $source->title,
            'instructions' => $source->instructions,
            'type' => $source->type,
            'option_count' => $source->option_count,
            'numeric_digits' => $source->numeric_digits,
            'numeric_allow_negative' => $source->numeric_allow_negative,
            'numeric_allow_decimal' => $source->numeric_allow_decimal,
            'default_points' => $source->default_points,
        ];
    }

    private function copyOne(Assignment $exam, ExamAppend $append, ExamSection $section, Question $source): Question
    {
        $options = [];
        foreach ($source->options as $option) {
            $options[$option->position] = ['text' => $option->text];
        }
        $question = $append->question($section, [
            'prompt_text' => (string) $source->prompt_text,
            'max_points' => $source->max_points,
            'answer_key' => self::key($section, $source->answer_key),
            'lock_options' => $section->type === ExamSection::TYPE_MCQ && $source->lock_options,
            'lock_options_suggested' => $section->type === ExamSection::TYPE_MCQ && $source->lock_options_suggested,
            'origin' => Question::ORIGIN_COPIED,
            'copied_from_question_id' => $source->id,
            'approved_at' => $source->approved_at === null ? null : now(),
        ], $options);

        $disk = ExamImages::disk();
        if ($source->prompt_image_path !== null && $disk->exists($source->prompt_image_path)) {
            $path = ExamImages::questionPath($question, $exam);
            $disk->put($path, (string) $disk->get($source->prompt_image_path));
            $question->forceFill(['prompt_image_path' => $path])->save();
        }
        $sourceOptions = $source->options->keyBy('position');
        foreach (QuestionOption::query()->where('question_id', $question->id)->get() as $option) {
            $from = $sourceOptions->get($option->position)?->image_path;
            if ($from !== null && $disk->exists($from)) {
                $path = ExamImages::optionPath($option, $exam);
                $disk->put($path, (string) $disk->get($from));
                $option->forceFill(['image_path' => $path])->save();
            }
        }

        $skills = self::visibleSkills($exam, $source->skills->pluck('id'));
        if ($skills !== []) {
            $question->skills()->sync($skills);
        }

        return $question;
    }

    /**
     * The source key in the target section; numbers that do not fit it are dropped.
     *
     * @return array<string, list<int|string>>|null
     */
    private static function key(ExamSection $section, mixed $key): ?array
    {
        if (! is_array($key)) {
            return null;
        }
        if ($section->type === ExamSection::TYPE_NUMERIC) {
            $values = array_values(array_filter((array) ($key['accepted_values'] ?? []), fn ($v) => is_string($v) && NumericAnswer::fits($v, $section)));

            return $values === [] ? null : ['accepted_values' => $values];
        }
        try {
            return ExamAnswerKey::normalise($section, ['accepted_options' => $key['accepted_options'] ?? null], 'answer_key.');
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Indicators of the source the target exam's school still sees, of the exam's subject.
     *
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    private static function visibleSkills(Assignment $exam, Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return Skill::query()
            ->visibleToSchool($exam->school_id)
            ->where('subject_id', $exam->subject_id)
            ->whereIn('level', Skill::ASSESSABLE_LEVELS)
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
