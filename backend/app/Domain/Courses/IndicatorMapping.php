<?php

namespace App\Domain\Courses;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Assignments\QuestionData;
use App\Domain\Mastery\MasteryCalculator;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Skill;
use App\Models\Submission;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PUT /assignments/{id}/indicator-mapping (DESIGN §20.3, §20.7): the
 * teacher confirms or edits the indicators of the questions and they are
 * written to question_skill (the same rows as PATCH /questions/{id}
 * skill_ids). Each listed question gets exactly the given set; questions
 * not listed keep theirs. Any indicator or sub-indicator of the
 * assignment's subject that the school sees is allowed, not only the
 * plan's (the teacher decides; Gemini is the one limited to the plan).
 *
 * The mapping only feeds mastery, never grading, so it is allowed on a
 * closed assignment too, and published submissions are recorded again
 * (MasteryCalculator::recordSubmission) so the charts follow the new
 * mapping at once.
 */
final class IndicatorMapping
{
    public function __construct(private readonly MasteryCalculator $mastery) {}

    /**
     * @param  array<string, mixed>  $input  {questions: [{question_id, skill_ids[]}]}
     * @return int questions whose indicators changed
     *
     * @throws ValidationException
     */
    public function apply(Assignment $assignment, array $input): int
    {
        $questionIds = Question::query()->where('assignment_id', $assignment->id)->pluck('id')->all();
        $validated = Validator::make($input, [
            'questions' => ['required', 'array', 'min:1', 'max:'.QuestionData::MAX_QUESTIONS],
            'questions.*' => ['array'],
            'questions.*.question_id' => ['required', 'integer', 'distinct', Rule::in($questionIds)],
            'questions.*.skill_ids' => [
                'present',
                'array',
                'max:'.QuestionData::MAX_SKILLS,
                // `distinct` on questions.*.skill_ids.* would compare across questions.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_array($value) && count($value) !== count(array_unique(array_map('strval', $value)))) {
                        $fail('เลือกตัวชี้วัดซ้ำกัน');
                    }
                },
            ],
            'questions.*.skill_ids.*' => [
                'integer',
                // The rule of QuestionData: indicators and sub-indicators of the subject the school sees (§20.2).
                Rule::exists('skills', 'id')
                    ->where('subject_id', $assignment->subject_id)
                    ->whereIn('level', Skill::ASSESSABLE_LEVELS)
                    ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $assignment->school_id)),
            ],
        ], [
            'questions.required' => 'ส่งรายการข้ออย่างน้อยหนึ่งข้อ',
            'questions.array' => 'รายการข้อไม่ถูกต้อง',
            'questions.min' => 'ส่งรายการข้ออย่างน้อยหนึ่งข้อ',
            'questions.max' => 'ส่งได้ไม่เกิน '.QuestionData::MAX_QUESTIONS.' ข้อ',
            'questions.*.array' => 'ข้อมูลของข้อไม่ถูกต้อง',
            'questions.*.question_id.required' => 'ระบุข้อ',
            'questions.*.question_id.integer' => 'รหัสข้อไม่ถูกต้อง',
            'questions.*.question_id.distinct' => 'ส่งข้อซ้ำกัน',
            'questions.*.question_id.in' => 'ไม่พบข้อนี้ในการบ้าน',
            'questions.*.skill_ids.present' => 'ส่งรายการตัวชี้วัด (ว่างได้)',
            'questions.*.skill_ids.array' => 'รายการตัวชี้วัดไม่ถูกต้อง',
            'questions.*.skill_ids.max' => 'เลือกตัวชี้วัดได้ไม่เกิน '.QuestionData::MAX_SKILLS.' ตัวต่อข้อ',
            'questions.*.skill_ids.*.integer' => 'รหัสตัวชี้วัดไม่ถูกต้อง',
            'questions.*.skill_ids.*.exists' => 'ไม่พบตัวชี้วัดนี้ในวิชาของการบ้าน (เลือกได้เฉพาะตัวชี้วัดหรือทักษะย่อย)',
        ])->validate();

        $changed = AssignmentLocked::run($assignment->id, function () use ($validated) {
            $changed = 0;
            foreach ($validated['questions'] as $row) {
                $question = Question::query()->findOrFail((int) $row['question_id']);
                $result = $question->skills()->sync(array_values(array_map('intval', $row['skill_ids'])));
                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    $changed++;
                }
            }

            return $changed;
        }, allowClosed: true);

        if ($changed > 0) {
            Submission::query()
                ->where('assignment_id', $assignment->id)
                ->where('status', Submission::STATUS_PUBLISHED)
                ->pluck('id')
                ->each(fn (int $id) => $this->mastery->recordSubmission($id));
        }

        return $changed;
    }
}
