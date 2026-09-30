<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Assignments\QuestionData;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Scan;
use App\Models\Skill;
use App\Models\Submission;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sections and questions of an exam (DESIGN §22.2–§22.5).
 *
 * - Structural changes (add, delete or move a section or question, option
 *   count, numeric settings, "ห้ามสลับตัวเลือก", number of versions) are
 *   refused with 409 exam_structure_locked once the exam was printed;
 *   texts, images, points and the key can always change.
 * - A section can create blank questions (question_count): origin teacher,
 *   empty prompt, no key, approved_at NULL ("ยังไม่ได้กรอก"). A blank is
 *   approved automatically the first time it gets what its grading method
 *   uses: a key (app) or a prompt (manual). A question the teacher types
 *   through POST /exam-sections/{id}/questions is approved when saved.
 * - Every change keeps the numbering continuous, rebuilds the shuffled
 *   versions while unlocked, and takes the key approval back from a ready
 *   app exam whose key is no longer complete.
 */
final class ExamEditor
{
    public const MAX_OPTION_TEXT = 500;

    public const MAX_INSTRUCTIONS = 2000;

    /** Fields of a question PATCH on an exam. */
    public const QUESTION_FIELDS = ['prompt_text', 'options', 'max_points', 'answer_key', 'lock_options', 'approve', 'position', 'skill_ids'];

    public const SECTION_FIELDS = ['title', 'instructions', 'type', 'option_count', 'numeric', 'default_points', 'question_count', 'position'];

    /**
     * POST /exams/{id}/sections.
     *
     * @param  array<string, mixed>  $input
     */
    public function createSection(Assignment $exam, array $input): ExamSection
    {
        return AssignmentLocked::run($exam->id, function (Assignment $exam) use ($input) {
            self::assertUnlocked($exam);
            $data = self::sectionData($input, null);
            $count = ExamSection::query()->where('assignment_id', $exam->id)->count();
            $max = (int) config('eduvision.exams.max_sections');
            if ($count >= $max) {
                throw ValidationException::withMessages(['type' => "ข้อสอบหนึ่งฉบับมีได้ไม่เกิน {$max} ตอน"]);
            }
            $blank = $data['question_count'];
            self::assertRoom($exam, $blank);

            $section = ExamSection::create([
                ...$data['attributes'],
                'assignment_id' => $exam->id,
                'position' => $count + 1,
            ]);
            if ($data['position'] !== null && $data['position'] <= $count) {
                $ids = array_values(array_filter(ExamPositions::sectionIds($exam->id), fn (int $id) => $id !== $section->id));
                array_splice($ids, $data['position'] - 1, 0, [$section->id]);
                ExamPositions::rewriteSections($exam->id, $ids);
            }
            for ($i = 0; $i < $blank; $i++) {
                $this->insertQuestion($exam, $section, [
                    'prompt_text' => '',
                    'max_points' => $section->default_points,
                    'origin' => Question::ORIGIN_TEACHER,
                    'approved_at' => null,
                ], []);
            }
            $this->afterStructureChange($exam);

            return $section->refresh();
        });
    }

    /**
     * PATCH /exam-sections/{id}: title, instructions and default_points any
     * time; option_count, numeric and position are structural.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateSection(ExamSection $section, array $changes): ExamSection
    {
        return AssignmentLocked::run($section->assignment_id, function (Assignment $exam) use ($section, $changes) {
            $section = ExamSection::query()->findOrFail($section->id);
            if (array_key_exists('type', $changes) && $changes['type'] !== $section->type) {
                throw ValidationException::withMessages(['type' => 'เปลี่ยนชนิดของตอนไม่ได้ ให้ลบตอนแล้วสร้างใหม่']);
            }
            if (array_key_exists('question_count', $changes)) {
                throw ValidationException::withMessages(['question_count' => 'เพิ่มข้อในตอนที่มีอยู่ด้วยการเพิ่มข้อทีละข้อ']);
            }
            $data = self::sectionData($changes, $section);
            $attributes = $data['attributes'];
            $oldPoints = $section->default_points;
            $oldOptions = $section->option_count;

            $section->fill($attributes);
            $structural = $section->isDirty(['option_count', 'numeric_digits', 'numeric_allow_negative', 'numeric_allow_decimal'])
                || ($data['position'] !== null && $data['position'] !== $section->position);
            if ($structural) {
                self::assertUnlocked($exam);
            }
            $section->save();

            if (abs($section->default_points - $oldPoints) > 0.0001) {
                // Questions still at the old default follow the new one; points set per question stay.
                Question::query()->where('section_id', $section->id)->where('max_points', $oldPoints)
                    ->update(['max_points' => $section->default_points]);
            }
            if ($section->type === ExamSection::TYPE_MCQ && $section->option_count !== $oldOptions) {
                $this->resizeOptions($exam, $section);
            }
            if ($data['position'] !== null && $data['position'] !== $section->position) {
                $ids = array_values(array_filter(ExamPositions::sectionIds($exam->id), fn (int $id) => $id !== $section->id));
                array_splice($ids, max(0, min($data['position'] - 1, count($ids))), 0, [$section->id]);
                ExamPositions::rewriteSections($exam->id, $ids);
                ExamPositions::renumber($exam->id);
            }
            if ($structural) {
                $this->afterStructureChange($exam);
            } else {
                ExamKeyCheck::keepApprovalValid($exam);
            }

            return $section->refresh();
        });
    }

    /** DELETE /exam-sections/{id}: with its questions (never once scanned answers exist). */
    public function deleteSection(ExamSection $section): void
    {
        AssignmentLocked::run($section->assignment_id, function (Assignment $exam) use ($section) {
            self::assertUnlocked($exam);
            $questions = Question::query()->where('section_id', $section->id)->with('options')->get();
            if (Question::query()->whereKey($questions->modelKeys())->whereHas('responses')->exists()) {
                throw new ApiException('ตอนนี้มีคำตอบของนักเรียนที่สแกนแล้ว ลบไม่ได้', 'question_has_responses', 409);
            }
            foreach ($questions as $question) {
                self::deleteFiles($question);
            }
            ExamSection::query()->whereKey($section->id)->delete();
            ExamPositions::rewriteSections($exam->id, ExamPositions::sectionIds($exam->id));
            ExamPositions::renumber($exam->id);
            $this->afterStructureChange($exam);
        });
    }

    /**
     * POST /exam-sections/{id}/questions: a question typed by the teacher
     * (origin teacher, approved when saved).
     *
     * @param  array<string, mixed>  $input
     */
    public function createQuestion(ExamSection $section, array $input): Question
    {
        return AssignmentLocked::run($section->assignment_id, function (Assignment $exam) use ($section, $input) {
            self::assertUnlocked($exam);
            $section = ExamSection::query()->findOrFail($section->id);
            self::assertRoom($exam, 1);
            $data = self::questionData($input, $section, $exam, null);

            $question = $this->insertQuestion($exam, $section, [
                'prompt_text' => $data['prompt_text'] ?? '',
                'max_points' => $data['max_points'] ?? $section->default_points,
                'answer_key' => $data['answer_key'] ?? null,
                'lock_options' => $data['lock_options'] ?? false,
                'origin' => Question::ORIGIN_TEACHER,
                'approved_at' => now(),
            ], $data['options'] ?? [], $data['position'] ?? null);
            if (($data['skill_ids'] ?? null) !== null) {
                $question->skills()->sync($data['skill_ids']);
            }
            $this->afterStructureChange($exam);

            return $question->refresh();
        });
    }

    /**
     * PATCH /questions/{id} of an exam question.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateQuestion(Question $question, array $changes): Question
    {
        return AssignmentLocked::run($question->assignment_id, function (Assignment $exam) use ($question, $changes) {
            $question = Question::query()->with(['section', 'options'])->findOrFail($question->id);
            $section = $question->section;
            $data = self::questionData($changes, $section, $exam, $question);

            $structural = (array_key_exists('lock_options', $data) && $data['lock_options'] !== $question->lock_options)
                || (($data['position'] ?? null) !== null && $data['position'] !== self::indexInSection($question));
            if ($structural) {
                self::assertUnlocked($exam);
            }

            foreach (['prompt_text', 'max_points', 'lock_options'] as $field) {
                if (array_key_exists($field, $data)) {
                    $question->{$field} = $data[$field];
                }
            }
            if (array_key_exists('answer_key', $data)) {
                $question->answer_key = $data['answer_key'];
            }
            if ($data['approve'] ?? false) {
                $question->approved_at ??= now();
            }
            $question->save();

            foreach ($data['options'] ?? [] as $position => $text) {
                QuestionOption::query()->where('question_id', $question->id)->where('position', $position)->update(['text' => $text]);
            }
            if (($data['skill_ids'] ?? null) !== null) {
                $question->skills()->sync($data['skill_ids']);
            }
            if (($data['position'] ?? null) !== null && $data['position'] !== self::indexInSection($question)) {
                $ids = Question::query()->where('section_id', $section->id)->where('id', '!=', $question->id)
                    ->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
                array_splice($ids, max(0, min($data['position'] - 1, count($ids))), 0, [$question->id]);
                ExamPositions::renumber($exam->id, [$section->id => $ids]);
            }

            self::autoApprove($question->refresh(), $exam);
            if ($structural) {
                $this->afterStructureChange($exam);
            } else {
                ExamKeyCheck::keepApprovalValid($exam);
            }

            return $question->refresh();
        });
    }

    /** DELETE /questions/{id} of an exam question. */
    public function deleteQuestion(Question $question): void
    {
        AssignmentLocked::run($question->assignment_id, function (Assignment $exam) use ($question) {
            self::assertUnlocked($exam);
            if ($question->responses()->exists()) {
                throw new ApiException('ข้อนี้มีคำตอบของนักเรียนที่สแกนแล้ว ลบไม่ได้', 'question_has_responses', 409);
            }
            self::deleteFiles($question->load('options'));
            Question::query()->whereKey($question->id)->delete();
            ExamPositions::renumber($exam->id);
            $this->afterStructureChange($exam);
        });
    }

    /**
     * POST /exams/{id}/questions/approve {question_ids[]}.
     *
     * @return int questions newly approved
     */
    public function approveQuestions(Assignment $exam, mixed $ids): int
    {
        return AssignmentLocked::run($exam->id, function (Assignment $exam) use ($ids) {
            $ids = self::ownQuestionIds($exam, $ids, 'question_ids');

            return Question::query()->whereKey($ids)->whereNull('approved_at')->update(['approved_at' => now()]);
        });
    }

    /**
     * PUT /exams/{id}/answer-key {answers: [{question_id, accepted_options?, accepted_values?}]}:
     * the listed questions get these keys (an empty list clears one). All
     * errors are reported at once, by position in the list.
     *
     * @return int questions whose key was written
     */
    public function saveAnswerKey(Assignment $exam, mixed $answers): int
    {
        return AssignmentLocked::run($exam->id, function (Assignment $exam) use ($answers) {
            if (! is_array($answers) || ! array_is_list($answers) || $answers === []) {
                throw ValidationException::withMessages(['answers' => 'กรุณาส่งเฉลยอย่างน้อย 1 ข้อ']);
            }
            if (count($answers) > (int) config('eduvision.exams.max_questions')) {
                throw ValidationException::withMessages(['answers' => 'ส่งเฉลยเกินจำนวนข้อของข้อสอบ']);
            }
            $questions = Question::query()->where('assignment_id', $exam->id)->with('section')->get()->keyBy('id');
            $errors = [];
            $keys = [];
            foreach ($answers as $i => $answer) {
                $id = is_array($answer) ? filter_var($answer['question_id'] ?? null, FILTER_VALIDATE_INT) : false;
                $question = $id === false ? null : $questions->get($id);
                if ($question === null) {
                    $errors["answers.{$i}.question_id"] = ['ไม่พบข้อนี้ในข้อสอบ'];

                    continue;
                }
                if (array_key_exists($question->id, $keys)) {
                    $errors["answers.{$i}.question_id"] = ['ส่งเฉลยของข้อนี้ซ้ำ'];

                    continue;
                }
                try {
                    $keys[$question->id] = ExamAnswerKey::normalise($question->section, [
                        'accepted_options' => $answer['accepted_options'] ?? null,
                        'accepted_values' => $answer['accepted_values'] ?? null,
                    ], "answers.{$i}.");
                } catch (ValidationException $e) {
                    $errors += $e->errors();
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($keys as $id => $key) {
                $question = $questions->get($id);
                $question->answer_key = $key;
                $question->save();
                self::autoApprove($question, $exam);
            }
            ExamKeyCheck::keepApprovalValid($exam);

            return count($keys);
        });
    }

    /** POST /exams/{id}/versions/reshuffle: a new nonce, only before the structure is locked. */
    public function reshuffle(Assignment $exam): void
    {
        AssignmentLocked::run($exam->id, function (Assignment $exam) {
            self::assertUnlocked($exam);
            $exam->shuffle_nonce = ($exam->shuffle_nonce + 1) % 65536;
            $exam->save();
            ExamVersions::sync($exam);
        });
    }

    /**
     * POST /exams/{id}/unlock-structure: allowed while no answer sheet was
     * scanned (409 exam_sheets_scanned). The versions are shuffled anew and
     * everything has to be printed again.
     */
    public function unlockStructure(Assignment $exam): void
    {
        AssignmentLocked::run($exam->id, function (Assignment $exam) {
            if (! $exam->structureLocked()) {
                return;
            }
            self::assertNoScannedSheets($exam);
            $exam->structure_locked_at = null;
            $exam->shuffle_nonce = ($exam->shuffle_nonce + 1) % 65536;
            $exam->save();
            ExamVersions::sync($exam);
        });
    }

    /** A blank question is approved the first time it has what its grading method uses (DESIGN §22.4). */
    public static function autoApprove(Question $question, Assignment $exam): void
    {
        if ($question->approved_at !== null || $question->origin !== Question::ORIGIN_TEACHER) {
            return;
        }
        $question->loadMissing('section');
        $ready = $exam->isManualExam() ? ExamKeyCheck::hasPrompt($question) : ExamKeyCheck::hasKey($question);
        if ($ready) {
            $question->approved_at = now();
            $question->save();
        }
    }

    /** @throws ApiException 409 exam_structure_locked */
    public static function assertUnlocked(Assignment $exam): void
    {
        if ($exam->structureLocked()) {
            throw new ApiException(
                'โครงสร้างข้อสอบถูกล็อกเพราะพิมพ์แล้ว กด "ปลดล็อกเพื่อแก้โครงสร้าง" ก่อน (ต้องพิมพ์ใหม่ทั้งหมด)',
                'exam_structure_locked',
                409,
            );
        }
    }

    /** @throws ApiException 409 exam_sheets_scanned */
    public static function assertNoScannedSheets(Assignment $exam): void
    {
        $scanned = Scan::query()
            ->whereIn('submission_id', Submission::query()->select('id')->where('assignment_id', $exam->id))
            ->exists();
        if ($scanned) {
            throw new ApiException('มีกระดาษคำตอบที่สแกนเข้ามาแล้ว เปลี่ยนไม่ได้', 'exam_sheets_scanned', 409);
        }
    }

    /**
     * Ids of questions of this exam from a request list.
     *
     * @return list<int>
     */
    private static function ownQuestionIds(Assignment $exam, mixed $ids, string $field): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || $ids === []) {
            throw ValidationException::withMessages([$field => 'กรุณาเลือกข้ออย่างน้อย 1 ข้อ']);
        }
        $own = Question::query()->where('assignment_id', $exam->id)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $out = [];
        foreach ($ids as $i => $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT);
            if ($value === false || ! $own->has($value)) {
                throw ValidationException::withMessages(["{$field}.{$i}" => 'ไม่พบข้อนี้ในข้อสอบ']);
            }
            $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    private function afterStructureChange(Assignment $exam): void
    {
        ExamVersions::sync($exam);
        ExamKeyCheck::keepApprovalValid($exam);
    }

    /**
     * Creates a question at the end of its section (or at $position within
     * it) with blank options for mcq, then renumbers the exam.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string|null>  $optionTexts  position => text
     */
    private function insertQuestion(Assignment $exam, ExamSection $section, array $attributes, array $optionTexts, ?int $position = null): Question
    {
        $question = Question::create([
            'lock_options' => false,
            ...$attributes,
            'assignment_id' => $exam->id,
            'section_id' => $section->id,
            'type' => $section->type,
            // Temporary number past the end; renumber() puts it in place.
            'position' => (int) Question::query()->where('assignment_id', $exam->id)->max('position') + 1,
            'is_numeric' => false,
            'match_mode' => 'exact',
            'rubric_status' => Question::RUBRIC_NOT_NEEDED,
        ]);
        if ($section->type === ExamSection::TYPE_MCQ) {
            for ($p = 1; $p <= (int) $section->option_count; $p++) {
                QuestionOption::create(['question_id' => $question->id, 'position' => $p, 'text' => $optionTexts[$p] ?? null]);
            }
        }
        $ids = Question::query()->where('section_id', $section->id)->where('id', '!=', $question->id)
            ->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $index = $position === null ? count($ids) : max(0, min($position - 1, count($ids)));
        array_splice($ids, $index, 0, [$question->id]);
        ExamPositions::renumber($exam->id, [$section->id => $ids]);

        return $question;
    }

    /** After option_count changed: add blank options or drop the extra ones (and their images and key entries). */
    private function resizeOptions(Assignment $exam, ExamSection $section): void
    {
        $count = (int) $section->option_count;
        $questions = Question::query()->where('section_id', $section->id)->with('options')->get();
        foreach ($questions as $question) {
            foreach ($question->options as $option) {
                if ($option->position > $count) {
                    ExamImages::delete($option->image_path);
                    $option->delete();
                }
            }
            for ($p = $question->options->count() + 1; $p <= $count; $p++) {
                QuestionOption::create(['question_id' => $question->id, 'position' => $p]);
            }
            $key = $question->answer_key;
            if (is_array($key) && isset($key['accepted_options'])) {
                $kept = array_values(array_filter($key['accepted_options'], fn ($o) => (int) $o <= $count));
                $question->answer_key = $kept === [] ? null : ['accepted_options' => $kept];
                $question->save();
            }
        }
    }

    private static function assertRoom(Assignment $exam, int $adding): void
    {
        $max = (int) config('eduvision.exams.max_questions');
        $count = Question::query()->where('assignment_id', $exam->id)->count();
        if ($count + $adding > $max) {
            throw new ApiException("ข้อสอบหนึ่งฉบับมีได้ไม่เกิน {$max} ข้อ (ตอนนี้มี {$count} ข้อ)", 'too_many_questions', 422);
        }
    }

    private static function indexInSection(Question $question): int
    {
        return Question::query()->where('section_id', $question->section_id)->where('position', '<=', $question->position)->count();
    }

    private static function deleteFiles(Question $question): void
    {
        ExamImages::delete($question->prompt_image_path);
        foreach ($question->options as $option) {
            ExamImages::delete($option->image_path);
        }
    }

    /**
     * @param  array<string, mixed>  $input  create: the whole body; update: the changes
     * @return array{attributes: array<string, mixed>, question_count: int, position: int|null}
     */
    private static function sectionData(array $input, ?ExamSection $current): array
    {
        $input = array_intersect_key($input, array_flip(self::SECTION_FIELDS));
        $type = $current?->type ?? (is_string($input['type'] ?? null) ? $input['type'] : null);
        $sometimes = $current === null ? [] : ['sometimes'];
        $maxBlank = (int) config('eduvision.exams.max_blank_questions');

        $rules = [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS],
            'type' => [...$sometimes, 'required', 'string', Rule::in(ExamSection::TYPES)],
            'option_count' => $type === ExamSection::TYPE_MCQ
                ? [...$sometimes, 'required', 'integer', 'min:'.ExamSection::MIN_OPTIONS, 'max:'.ExamSection::MAX_OPTIONS]
                : ['prohibited'],
            'numeric' => $type === ExamSection::TYPE_NUMERIC ? [...$sometimes, 'required', 'array'] : ['prohibited'],
            'numeric.digits' => ['required_with:numeric', 'integer', 'min:1', 'max:'.ExamSection::MAX_DIGITS],
            'numeric.allow_negative' => ['sometimes', 'boolean'],
            'numeric.allow_decimal' => ['sometimes', 'boolean'],
            'default_points' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'question_count' => ['sometimes', 'integer', 'min:0', 'max:'.$maxBlank],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('eduvision.exams.max_sections')],
        ];
        $messages = [
            'title.max' => 'ชื่อตอนยาวเกิน 255 ตัวอักษร',
            'instructions.max' => 'คำชี้แจงยาวเกิน '.self::MAX_INSTRUCTIONS.' ตัวอักษร',
            'type.required' => 'กรุณาเลือกชนิดของตอน',
            'type.in' => 'ชนิดของตอนต้องเป็น ปรนัย ถูก/ผิด หรือเติมตัวเลข',
            'option_count.required' => 'กรุณาเลือกจำนวนตัวเลือก',
            'option_count.min' => 'ข้อปรนัยต้องมีอย่างน้อย '.ExamSection::MIN_OPTIONS.' ตัวเลือก',
            'option_count.max' => 'ข้อปรนัยมีได้ไม่เกิน '.ExamSection::MAX_OPTIONS.' ตัวเลือก',
            'option_count.integer' => 'จำนวนตัวเลือกต้องเป็นตัวเลข',
            'option_count.prohibited' => 'จำนวนตัวเลือกใช้กับตอนปรนัยเท่านั้น',
            'numeric.required' => 'กรุณากำหนดจำนวนหลักของคำตอบตัวเลข',
            'numeric.prohibited' => 'ค่าตัวเลขใช้กับตอนเติมตัวเลขเท่านั้น',
            'numeric.digits.required_with' => 'กรุณากำหนดจำนวนหลัก',
            'numeric.digits.min' => 'ต้องมีอย่างน้อย 1 หลัก',
            'numeric.digits.max' => 'มีได้ไม่เกิน '.ExamSection::MAX_DIGITS.' หลัก',
            'default_points.gt' => 'คะแนนต่อข้อต้องมากกว่า 0',
            'default_points.max' => 'คะแนนต่อข้อต้องไม่เกิน 100',
            'default_points.decimal' => 'คะแนนมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
            'question_count.max' => "สร้างข้อว่างได้ครั้งละไม่เกิน {$maxBlank} ข้อ",
            'question_count.min' => 'จำนวนข้อต้องไม่ติดลบ',
        ];
        $v = Validator::make($input, $rules, $messages)->validate();

        $attributes = [];
        foreach (['title', 'instructions'] as $field) {
            if (array_key_exists($field, $v)) {
                $text = trim((string) $v[$field]);
                $attributes[$field] = $text === '' ? null : $text;
            }
        }
        if ($current === null) {
            $attributes['type'] = $type;
        }
        if ($type === ExamSection::TYPE_MCQ && array_key_exists('option_count', $v)) {
            $attributes['option_count'] = (int) $v['option_count'];
        }
        if ($type === ExamSection::TYPE_NUMERIC && array_key_exists('numeric', $v)) {
            $attributes['numeric_digits'] = (int) $v['numeric']['digits'];
            $attributes['numeric_allow_negative'] = (bool) ($v['numeric']['allow_negative'] ?? false);
            $attributes['numeric_allow_decimal'] = (bool) ($v['numeric']['allow_decimal'] ?? false);
        }
        if (array_key_exists('default_points', $v)) {
            $attributes['default_points'] = round((float) $v['default_points'], 2);
        }

        return [
            'attributes' => $attributes,
            'question_count' => (int) ($v['question_count'] ?? 0),
            'position' => isset($v['position']) ? (int) $v['position'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only the fields that were sent, normalised;
     *                              options as position => text
     */
    private static function questionData(array $input, ExamSection $section, Assignment $exam, ?Question $current): array
    {
        $input = array_intersect_key($input, array_flip(self::QUESTION_FIELDS));
        $mcq = $section->type === ExamSection::TYPE_MCQ;
        $rules = [
            'prompt_text' => ['sometimes', 'nullable', 'string', 'max:'.QuestionData::MAX_PROMPT],
            'options' => $mcq ? ['sometimes', 'nullable', 'array', 'list', 'max:'.(int) $section->option_count] : ['prohibited'],
            'options.*' => ['nullable', 'array'],
            'options.*.text' => ['nullable', 'string', 'max:'.self::MAX_OPTION_TEXT],
            'max_points' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'answer_key' => ['sometimes', 'nullable', 'array'],
            'lock_options' => $mcq ? ['sometimes', 'required', 'boolean'] : ['sometimes', 'nullable', 'declined'],
            'approve' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('eduvision.exams.max_questions')],
            'skill_ids' => ['sometimes', 'nullable', 'array', 'max:'.QuestionData::MAX_SKILLS],
            'skill_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('skills', 'id')
                    ->where('subject_id', $exam->subject_id)
                    ->whereIn('level', Skill::ASSESSABLE_LEVELS)
                    ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $exam->school_id)),
            ],
        ];
        $messages = [
            'prompt_text.max' => 'โจทย์ยาวเกิน '.QuestionData::MAX_PROMPT.' ตัวอักษร',
            'options.prohibited' => 'ข้อถูก/ผิดและข้อเติมตัวเลขไม่มีตัวเลือกให้พิมพ์',
            'options.max' => "ตอนนี้มี {$section->option_count} ตัวเลือก",
            'options.list' => 'ตัวเลือกต้องเป็นรายการตามลำดับ ก ข ค ง',
            'options.*.text.max' => 'ตัวเลือกยาวเกิน '.self::MAX_OPTION_TEXT.' ตัวอักษร',
            'max_points.required' => 'กรุณากรอกคะแนนเต็ม',
            'max_points.numeric' => 'คะแนนเต็มต้องเป็นตัวเลข',
            'max_points.gt' => 'คะแนนเต็มต้องมากกว่า 0',
            'max_points.max' => 'คะแนนเต็มต้องไม่เกิน 100',
            'max_points.decimal' => 'คะแนนเต็มมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
            'answer_key.array' => 'รูปแบบเฉลยไม่ถูกต้อง',
            'lock_options.declined' => '"ห้ามสลับตัวเลือก" ใช้กับข้อปรนัยเท่านั้น',
            'skill_ids.max' => 'เลือกตัวชี้วัดได้ไม่เกิน '.QuestionData::MAX_SKILLS.' ตัวต่อข้อ',
            'skill_ids.*.exists' => 'ไม่พบตัวชี้วัดนี้ในวิชาของข้อสอบ',
            'skill_ids.*.distinct' => 'เลือกตัวชี้วัดซ้ำกัน',
        ];
        $v = Validator::make($input, $rules, $messages)->validate();

        $out = [];
        if (array_key_exists('prompt_text', $v)) {
            $out['prompt_text'] = trim((string) $v['prompt_text']);
        }
        if ($mcq && isset($v['options'])) {
            $out['options'] = [];
            foreach ($v['options'] as $i => $option) {
                $text = trim((string) ($option['text'] ?? ''));
                $out['options'][$i + 1] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('max_points', $v)) {
            $out['max_points'] = round((float) $v['max_points'], 2);
        }
        if (array_key_exists('answer_key', $input)) {
            $out['answer_key'] = ExamAnswerKey::normalise($section, $input['answer_key'], 'answer_key.');
        }
        if ($mcq && array_key_exists('lock_options', $v)) {
            $out['lock_options'] = (bool) $v['lock_options'];
        }
        if (array_key_exists('approve', $v)) {
            $out['approve'] = (bool) $v['approve'];
        }
        if (isset($v['position'])) {
            $out['position'] = (int) $v['position'];
        }
        if (array_key_exists('skill_ids', $v)) {
            $out['skill_ids'] = array_values(array_map('intval', $v['skill_ids'] ?? []));
        }

        return $out;
    }
}
