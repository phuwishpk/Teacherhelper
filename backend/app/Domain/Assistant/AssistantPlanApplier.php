<?php

namespace App\Domain\Assistant;

use App\Domain\Assignments\AssignmentCreator;
use App\Domain\Assignments\QuestionEditor;
use App\Domain\Assignments\RubricService;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Courses\IndicatorMatcher;
use App\Domain\Courses\IndicatorScope;
use App\Domain\Exams\ExamEditor;
use App\Domain\Exams\NumericAnswer;
use App\Domain\Skills\TeacherSkills;
use App\Exceptions\ApiException;
use App\Jobs\DraftRubricJob;
use App\Models\Assignment;
use App\Models\AssistantMessage;
use App\Models\ExamSection;
use App\Models\IndicatorSuggestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * «สร้างเลย» on a plan card (DESIGN §37.6): the ticked questions go into
 * the target assignment through the same services as the teacher's own
 * screens (a rubric or an indicator card: RubricCards / IndicatorCards), so validation, permissions and locks are theirs:
 *
 * - homework: QuestionEditor::create (a ready worksheet goes back to
 *   draft); a freeform assignment loses its key approval, and an empty
 *   key_origin becomes ai_draft;
 * - exam: the first section of the same shape, or a new one, then
 *   ExamEditor::createQuestion (approved with its key); a printed exam is
 *   409 exam_structure_locked;
 * - open questions queue a rubric draft; indicator codes in the
 *   assignment's IndicatorScope become indicator_suggestions.
 *
 * A question the services refuse is skipped with the reason; an error of
 * the whole assignment (closed, locked) rolls everything back. Nothing
 * created at all is 422 plan_nothing_created, so no empty new assignment
 * is left behind.
 */
final class AssistantPlanApplier
{
    public const SUGGESTION_REASON = 'ผู้ช่วย Krucheck เสนอ';

    /** Answer lines of a show_work or open question created from a card. */
    private const ANSWER_LINES = 6;

    public function __construct(
        private readonly AssignmentCreator $creator,
        private readonly QuestionEditor $questions,
        private readonly ExamEditor $exams,
        private readonly RubricService $rubrics,
        private readonly TeacherSkills $skills,
    ) {}

    /**
     * @param  list<int>  $selected  indexes into the card's questions
     * @param  array<string, mixed>|null  $target  {assignment_id} or {new: {…}}; null = the card's
     *
     * @throws ApiException 409 plan_not_open, 422 plan_no_target / plan_nothing_created, and the services'
     */
    public function apply(User $teacher, AssistantMessage $message, array $selected, ?array $target): AssistantMessage
    {
        $rubricDrafts = [];
        DB::transaction(function () use ($teacher, $message, $selected, $target, &$rubricDrafts) {
            $message = AssistantMessage::query()->lockForUpdate()->findOrFail($message->id);
            $plan = $message->plan;
            if ($message->plan_status !== AssistantMessage::PLAN_PROPOSED || ! is_array($plan)) {
                throw new ApiException('การ์ดนี้สร้างไปแล้วหรือมีการ์ดใหม่แทนแล้ว', 'plan_not_open', 409);
            }
            $kind = $plan['kind'] ?? AssistantPlans::CARD_QUESTIONS;
            if ($kind !== AssistantPlans::CARD_QUESTIONS) {
                $plan['result'] = [
                    ...($kind === AssistantPlans::CARD_RUBRIC
                        ? RubricCards::apply($teacher, $plan, $this->rubrics)
                        : IndicatorCards::apply($teacher, $plan, $selected, $this->skills)),
                    'at' => now()->toIso8601String(),
                ];
                $message->plan = $plan;
                $message->plan_status = AssistantMessage::PLAN_APPLIED;
                $message->save();

                return;
            }
            $resolved = $target === null ? $plan['target'] : AssistantPlans::target($target, $teacher);
            AssistantPlans::assertTarget($resolved);
            $assignment = $this->assignment($teacher, $resolved);

            $kind = AssistantPlans::kindOf($assignment);
            $created = [];
            $skipped = [];
            $wanted = [];
            foreach ($selected as $index) {
                $question = $plan['questions'][$index];
                $reason = AssistantPlans::unsupported($question, $kind)
                    ?? (AssistantPlans::keyComplete($question) ? null : 'เฉลยไม่ครบ');
                if ($reason !== null) {
                    $skipped[] = ['index' => $index, 'reason' => $reason];
                } else {
                    $wanted[$index] = $question;
                }
            }

            $sections = $kind === AssistantPlans::KIND_EXAM ? $this->sections($assignment, $wanted) : [];
            foreach ($wanted as $index => $question) {
                try {
                    $made = $kind === AssistantPlans::KIND_EXAM
                        ? $this->exams->createQuestion($sections[$index], self::examInput($question))
                        : $this->questions->create($assignment, self::homeworkInput($question));
                } catch (ValidationException $e) {
                    $skipped[] = ['index' => $index, 'reason' => self::firstError($e)];

                    continue;
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'too_many_questions') {
                        throw $e;
                    }
                    $skipped[] = ['index' => $index, 'reason' => $e->getMessage()];

                    continue;
                }
                $created[$index] = $made;
                if ($made->type === Question::TYPE_OPEN) {
                    $rubricDrafts[] = $made->id;
                }
            }
            if ($created === []) {
                throw new ApiException('ไม่มีข้อที่สร้างได้ '.implode(' ', array_unique(array_column($skipped, 'reason'))), 'plan_nothing_created', 422, [], ['skipped' => $skipped]);
            }

            $assignment->refresh();
            if ($assignment->isFreeform()) {
                if ($assignment->key_origin === null) {
                    $assignment->key_origin = Assignment::KEY_AI_DRAFT;
                    $assignment->save();
                }
                $assignment->revokeKeyApproval();
            }
            $this->suggestIndicators($assignment, $created, $plan['questions']);

            usort($skipped, fn (array $a, array $b) => $a['index'] <=> $b['index']);
            $plan['target'] = AssistantPlans::describe($assignment->load('classroom'));
            $plan['result'] = [
                'assignment_id' => $assignment->id,
                'title' => $assignment->title,
                'kind' => $kind,
                'created' => count($created),
                'skipped' => $skipped,
                'at' => now()->toIso8601String(),
            ];
            $message->plan = $plan;
            $message->plan_status = AssistantMessage::PLAN_APPLIED;
            $message->save();
        });
        foreach ($rubricDrafts as $questionId) {
            DraftRubricJob::dispatch($questionId);
        }

        return $message->refresh();
    }

    /** @param  array<string, mixed>  $target */
    private function assignment(User $teacher, array $target): Assignment
    {
        if (isset($target['new'])) {
            $new = $target['new'];

            return $this->creator->create($teacher, [
                'classroom_id' => $new['classroom_id'],
                'course_id' => $new['course_id'],
                'title' => $new['title'],
                'kind' => $new['kind'] === AssistantPlans::KIND_EXAM ? Assignment::KIND_EXAM : Assignment::KIND_HOMEWORK,
                'mode' => $new['kind'] === AssistantPlans::KIND_FREEFORM ? Assignment::MODE_FREEFORM : Assignment::MODE_WORKSHEET,
                'send_to_google' => false,
            ]);
        }
        $assignment = ClassroomAccess::managedAssignments($teacher)->with('classroom')->findOrFail($target['assignment_id']);
        Gate::forUser($teacher)->authorize('update', $assignment);
        ClosedClassrooms::assertOpen($assignment->classroom); // §24.6

        return $assignment;
    }

    /**
     * The section of every exam question: the first of the same shape, or
     * one new section per shape, sized for every number it will hold.
     *
     * @param  array<int, array<string, mixed>>  $wanted
     * @return array<int, ExamSection>
     */
    private function sections(Assignment $exam, array $wanted): array
    {
        $existing = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $found = [];
        $missing = [];
        foreach ($wanted as $index => $question) {
            $section = $existing->first(fn (ExamSection $s) => self::fits($s, $question));
            if ($section !== null) {
                $found[$index] = $section;
            } else {
                $missing[self::shape($question)][$index] = $question;
            }
        }
        foreach ($missing as $group) {
            $first = reset($group);
            $input = ['type' => $first['type'], 'default_points' => $first['points']];
            if ($first['type'] === ExamSection::TYPE_MCQ) {
                $input['option_count'] = count($first['options']);
            }
            if ($first['type'] === ExamSection::TYPE_NUMERIC) {
                $needs = array_map(fn (array $q) => AssistantPlans::numericNeeds($q['numeric_value']), $group);
                $input['numeric'] = [
                    'digits' => max(array_column($needs, 'digits')),
                    'allow_negative' => in_array(true, array_column($needs, 'negative'), true),
                    'allow_decimal' => in_array(true, array_column($needs, 'decimal'), true),
                ];
            }
            $section = $this->exams->createSection($exam, $input);
            foreach (array_keys($group) as $index) {
                $found[$index] = $section;
            }
        }

        return $found;
    }

    /** @param  array<string, mixed>  $question */
    private static function fits(ExamSection $section, array $question): bool
    {
        return $section->type === $question['type'] && match ($section->type) {
            ExamSection::TYPE_MCQ => (int) $section->option_count === count($question['options']),
            ExamSection::TYPE_NUMERIC => NumericAnswer::fits((string) $question['numeric_value'], $section),
            default => true,
        };
    }

    /** @param  array<string, mixed>  $question */
    private static function shape(array $question): string
    {
        return $question['type'] === ExamSection::TYPE_MCQ ? 'mcq:'.count($question['options']) : $question['type'];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private static function homeworkInput(array $q): array
    {
        $accepted = fn (): array => [
            'accepted' => $q['accepted_answers'] !== [] ? $q['accepted_answers'] : [(string) $q['numeric_value']],
            ...($q['numeric_value'] !== null ? ['numeric' => ['value' => (float) $q['numeric_value'], 'abs_tol' => 0]] : []),
        ];
        $prompt = $q['prompt_text'];
        if ($q['type'] === Question::TYPE_MCQ) {
            foreach ($q['options'] as $i => $option) {
                $prompt .= "\n".Question::MCQ_OPTIONS[$i].'. '.$option;
            }
        }

        return [
            'type' => $q['type'],
            'prompt_text' => $prompt,
            'max_points' => $q['points'],
            'answer_lines' => Question::typeNeedsRubric($q['type']) ? self::ANSWER_LINES : null,
            'is_numeric' => $q['numeric_value'] !== null && in_array($q['type'], [Question::TYPE_SHORT, Question::TYPE_SHOW_WORK], true),
            'model_answer' => $q['model_answer'],
            ...match ($q['type']) {
                Question::TYPE_MCQ => ['answer_key' => ['correct' => Question::MCQ_OPTIONS[$q['correct_options'][0] - 1]]],
                Question::TYPE_SHORT => ['answer_key' => $accepted()],
                Question::TYPE_SHOW_WORK => ['answer_key' => ['final' => $accepted(), 'reference_steps' => $q['reference_steps']]],
                default => [],
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private static function examInput(array $q): array
    {
        return [
            'prompt_text' => $q['prompt_text'],
            'max_points' => $q['points'],
            ...($q['type'] === ExamSection::TYPE_MCQ ? ['options' => array_map(fn (string $t) => ['text' => $t], $q['options'])] : []),
            'answer_key' => $q['type'] === ExamSection::TYPE_NUMERIC
                ? ['accepted_values' => [$q['numeric_value']]]
                : ['accepted_options' => $q['correct_options']],
        ];
    }

    /**
     * @param  array<int, Question>  $created  card index => question
     * @param  list<array<string, mixed>>  $cardQuestions
     */
    private function suggestIndicators(Assignment $assignment, array $created, array $cardQuestions): void
    {
        $scope = IndicatorScope::of($assignment);
        if ($scope === null || $scope->isEmpty()) {
            return;
        }
        $byCode = [];
        foreach ($scope->indicators as $skill) {
            $byCode[IndicatorMatcher::normalize($skill->code)] = $skill->id;
        }
        $rows = [];
        foreach ($created as $index => $question) {
            $seen = [];
            foreach ($cardQuestions[$index]['indicator_codes'] as $code) {
                $skillId = $byCode[IndicatorMatcher::normalize($code)] ?? null;
                if ($skillId !== null && ! isset($seen[$skillId])) {
                    $seen[$skillId] = true;
                    $rows[] = ['question_id' => $question->id, 'skill_id' => $skillId, 'reason_th' => self::SUGGESTION_REASON, 'created_at' => now()];
                }
            }
        }
        if ($rows !== []) {
            IndicatorSuggestion::query()->insertOrIgnore($rows);
        }
    }

    private static function firstError(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            return (string) ($messages[0] ?? 'ข้อมูลไม่ถูกต้อง');
        }

        return 'ข้อมูลไม่ถูกต้อง';
    }
}
