<?php

namespace App\Domain\AnswerKeys;

use App\Domain\Assignments\AssignmentLocked;
use App\Jobs\DraftRubricJob;
use App\Models\Assignment;
use App\Models\Question;

/**
 * Writes a key that Gemini read or drafted into the assignment's questions
 * as a draft for the teacher to edit and approve (DESIGN §19.5):
 *
 * - no question yet: one question per entry, numbered 1..n in the order of
 *   question_no (points as read, else 1; show_work and open get 5 answer
 *   lines and a draft rubric);
 * - questions already there: the key is filled in by position = question_no
 *   when the type matches; an entry with another type, without an answer,
 *   or with no such question is skipped (reported, nothing changes). An
 *   approved show_work rubric whose reference steps change goes back to
 *   draft. Points are kept.
 *
 * Open questions get the model answer; those without criteria are queued
 * for a rubric draft (DraftRubricJob), which starts from that model answer
 * (§10.4). Each teacher gets their own copy in `questions`; the cached
 * result never changes.
 *
 * key_origin becomes `document` or `ai_draft` (the "AI ร่าง ไม่มีคำตอบของครู"
 * label), and a freeform assignment loses its approval: the new key must be
 * approved before anything more is graded.
 */
final class AnswerKeyApplier
{
    public const DEFAULT_LINES = 5;

    /**
     * @return array{created: int, filled: int, skipped: list<array{question_no: int, reason: string}>}
     */
    public function apply(Assignment $assignment, AnswerKeyResult $result): array
    {
        $rubricDrafts = [];
        $summary = AssignmentLocked::run($assignment->id, function (Assignment $assignment) use ($result, &$rubricDrafts) {
            $existing = Question::query()->with('rubricCriteria')->where('assignment_id', $assignment->id)->orderBy('position')->get();
            $summary = $existing->isEmpty()
                ? $this->create($assignment, $result, $rubricDrafts)
                : $this->fill($existing->keyBy('position')->all(), $result, $rubricDrafts, $assignment->isFreeform());

            $assignment->key_origin = $result->kind === AnswerKeyResult::KIND_DRAFT ? Assignment::KEY_AI_DRAFT : Assignment::KEY_DOCUMENT;
            $assignment->save();
            if ($assignment->isFreeform()) {
                $assignment->revokeKeyApproval();
            } elseif ($summary['created'] > 0 || self::hasDraftRubric($assignment)) {
                $assignment->backToDraft(); // a worksheet: the layout rules decide (§2.2)
            }

            return $summary;
        });

        foreach ($rubricDrafts as $questionId) {
            DraftRubricJob::dispatch($questionId);
        }

        return $summary;
    }

    /**
     * @param  list<int>  $rubricDrafts
     * @return array{created: int, filled: int, skipped: list<array{question_no: int, reason: string}>}
     */
    private function create(Assignment $assignment, AnswerKeyResult $result, array &$rubricDrafts): array
    {
        $position = 0;
        foreach ($result->questions as $item) {
            $type = (string) $item['type'];
            $needsRubric = Question::typeNeedsRubric($type);
            $key = $item['answer_key'];
            $question = Question::create([
                'assignment_id' => $assignment->id,
                'position' => ++$position,
                'type' => $type,
                'prompt_text' => trim((string) $item['prompt_text']) !== '' ? $item['prompt_text'] : 'ข้อ '.$item['question_no'],
                'max_points' => $item['max_points'] ?? 1,
                'answer_lines' => $needsRubric ? self::DEFAULT_LINES : null,
                'is_numeric' => self::numeric($type, $key),
                'match_mode' => 'flexible',
                'answer_key' => $key,
                'rubric_status' => $needsRubric ? Question::RUBRIC_DRAFT : Question::RUBRIC_NOT_NEEDED,
                'model_answer' => $item['model_answer'],
            ]);
            if ($type === Question::TYPE_OPEN) {
                $rubricDrafts[] = $question->id;
            }
        }

        return ['created' => $position, 'filled' => 0, 'skipped' => []];
    }

    /**
     * @param  array<int, Question>  $byPosition
     * @param  list<int>  $rubricDrafts
     * @return array{created: int, filled: int, skipped: list<array{question_no: int, reason: string}>}
     */
    private function fill(array $byPosition, AnswerKeyResult $result, array &$rubricDrafts, bool $freeform): array
    {
        $filled = 0;
        $skipped = [];
        foreach ($result->questions as $item) {
            $no = (int) $item['question_no'];
            $question = $byPosition[$no] ?? null;
            if ($question === null) {
                $skipped[] = ['question_no' => $no, 'reason' => 'no_such_question'];

                continue;
            }
            if ($question->type !== $item['type']) {
                $skipped[] = ['question_no' => $no, 'reason' => 'type_mismatch'];

                continue;
            }

            $changed = false;
            if ($question->type === Question::TYPE_OPEN) {
                if ($item['model_answer'] !== null) {
                    $question->model_answer = $item['model_answer'];
                    $changed = true;
                }
                if ($question->rubricCriteria->isEmpty()) {
                    $rubricDrafts[] = $question->id;
                }
            } elseif ($item['answer_key'] !== null) {
                $key = $item['answer_key'];
                if ($question->type === Question::TYPE_SHOW_WORK) {
                    $old = $question->answer_key['reference_steps'] ?? [];
                    if ($key['reference_steps'] === []) {
                        $key['reference_steps'] = $old;
                    }
                    if ($key['reference_steps'] !== $old && $question->rubric_status === Question::RUBRIC_APPROVED) {
                        $question->rubric_status = Question::RUBRIC_DRAFT;
                    }
                }
                $question->answer_key = $key;
                if ($freeform) {
                    // A worksheet's numeric box is printed: only its layout edit may change it.
                    $question->is_numeric = $question->is_numeric || self::numeric($question->type, $key);
                }
                $changed = true;
            }

            if (! $changed) {
                $skipped[] = ['question_no' => $no, 'reason' => 'no_answer'];

                continue;
            }
            $question->save();
            $filled++;
        }

        return ['created' => 0, 'filled' => $filled, 'skipped' => $skipped];
    }

    private static function hasDraftRubric(Assignment $assignment): bool
    {
        return Question::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('type', [Question::TYPE_SHOW_WORK, Question::TYPE_OPEN])
            ->where('rubric_status', '!=', Question::RUBRIC_APPROVED)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $key
     */
    private static function numeric(string $type, ?array $key): bool
    {
        return match ($type) {
            Question::TYPE_SHORT => isset($key['numeric']),
            Question::TYPE_SHOW_WORK => isset($key['final']['numeric']),
            default => false,
        };
    }
}
