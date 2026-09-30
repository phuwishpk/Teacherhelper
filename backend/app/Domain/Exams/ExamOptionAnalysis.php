<?php

namespace App\Domain\Exams;

use App\Domain\Mastery\ItemAnalysis;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;

/**
 * GET /exams/{id}/option-analysis (DESIGN §22.13): how often each option of
 * every master question was chosen, over all versions at once (exam_answer
 * stores original option positions), from published submissions only.
 * Computed on read, never stored (at most 50 students × 200 questions).
 *
 * Per question the answer that counts is the teacher's reading
 * (exam_answer.resolved) when there is one, else the marks as read
 * (ExamAnswerScore::effective). Every published student lands in exactly one
 * bucket of an mcq / true_false question: one option (that option's count),
 * no mark (blank) or several marks (multiple), so the percentages add up to
 * 100. A published student without an answer row for the question counts as
 * blank. Numeric questions have no options: only blank (no readable value).
 *
 * top / bottom: how many of the top and bottom 27 % by the total that counts
 * (ItemAnalysis::groups, effectiveTotal()) chose the option; null below
 * ItemAnalysis::MIN_FOR_R published students (groups_ready false), when no
 * flag is shown either. Flags of a wrong option (a distractor, not in the
 * current master key):
 *
 * - unused_distractor    nobody chose it ("ตัวลวงที่ไม่มีใครเลือก");
 * - reversed_distractor  the top group chose it more often than the bottom
 *                        group ("ตัวลวงที่กลุ่มสูงเลือกมากกว่ากลุ่มต่ำ").
 *
 * p and r are those of ItemAnalysis (§14.3) for the same question.
 */
final class ExamOptionAnalysis
{
    public const FLAG_UNUSED = 'unused_distractor';

    public const FLAG_REVERSED = 'reversed_distractor';

    /**
     * @return array{
     *   published_count: int,
     *   groups_ready: bool,
     *   min_count_for_r: int,
     *   group_size: int|null,
     *   questions: list<array<string, mixed>>
     * }
     */
    public static function of(Assignment $exam): array
    {
        $questions = Question::query()
            ->where('assignment_id', $exam->id)
            ->with('section')
            ->orderBy('position')
            ->get();
        $submissions = Submission::query()
            ->where('assignment_id', $exam->id)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->get(['id', 'total_score', 'total_override']);
        $published = $submissions->count();
        [$top, $bottom] = ItemAnalysis::groups($submissions);
        $topIds = array_flip($top ?? []);
        $bottomIds = array_flip($bottom ?? []);
        $ready = $top !== null;

        /** @var array<int, array<int, array<string, mixed>|null>> question_id => [submission_id => exam_answer] */
        $answers = [];
        if ($published > 0) {
            Response::query()
                ->whereIn('submission_id', $submissions->modelKeys())
                ->get(['id', 'submission_id', 'question_id', 'exam_answer'])
                ->each(function (Response $r) use (&$answers) {
                    $answers[$r->question_id][$r->submission_id] = is_array($r->exam_answer) ? $r->exam_answer : null;
                });
        }

        $items = collect(ItemAnalysis::forAssignment($exam)['items'])->keyBy('question_id');
        $pct = fn (int $count): ?float => $published === 0 ? null : round(100 * $count / $published, 1);

        $out = [];
        foreach ($questions as $question) {
            $choices = $question->section?->choiceCount() ?? 0;
            $accepted = array_map('intval', (array) ($question->answer_key['accepted_options'] ?? []));
            $counts = array_fill(1, max(1, $choices), 0);
            $topCounts = $counts;
            $bottomCounts = $counts;
            $blank = 0;
            $multiple = 0;
            foreach ($submissions as $submission) {
                $answer = ExamAnswerScore::effective($answers[$question->id][$submission->id] ?? null);
                if ($question->type === Question::TYPE_NUMERIC) {
                    $blank += $answer['value'] === null ? 1 : 0;

                    continue;
                }
                $selected = array_values(array_unique($answer['selected']));
                if ($selected === []) {
                    $blank++;
                } elseif (count($selected) > 1) {
                    $multiple++;
                } elseif ($selected[0] >= 1 && $selected[0] <= $choices) {
                    $position = $selected[0];
                    $counts[$position]++;
                    $topCounts[$position] += isset($topIds[$submission->id]) ? 1 : 0;
                    $bottomCounts[$position] += isset($bottomIds[$submission->id]) ? 1 : 0;
                }
            }

            $labels = ExamAnswerScore::labels($question);
            $options = [];
            for ($position = 1; $position <= $choices; $position++) {
                $correct = in_array($position, $accepted, true);
                $flags = [];
                if ($ready && ! $correct) {
                    if ($counts[$position] === 0) {
                        $flags[] = self::FLAG_UNUSED;
                    }
                    if ($topCounts[$position] > $bottomCounts[$position]) {
                        $flags[] = self::FLAG_REVERSED;
                    }
                }
                $options[] = [
                    'position' => $position,
                    'label' => $labels[$position - 1] ?? (string) $position,
                    'correct' => $correct,
                    'count' => $counts[$position],
                    'pct' => $pct($counts[$position]),
                    'top' => $ready ? $topCounts[$position] : null,
                    'bottom' => $ready ? $bottomCounts[$position] : null,
                    'flags' => $flags,
                ];
            }

            $item = $items->get($question->id);
            $out[] = [
                'question_id' => $question->id,
                'position' => (int) $question->position,
                'section_id' => $question->section_id,
                'type' => $question->type,
                'prompt_text' => $question->prompt_text,
                'p' => $item['p'] ?? null,
                'r' => $item['r'] ?? null,
                'options' => $options,
                'blank' => ['count' => $blank, 'pct' => $pct($blank)],
                'multiple' => ['count' => $multiple, 'pct' => $pct($multiple)],
                'flag_count' => array_sum(array_map(fn (array $o) => count($o['flags']), $options)),
            ];
        }

        return [
            'published_count' => $published,
            'groups_ready' => $ready,
            'min_count_for_r' => ItemAnalysis::MIN_FOR_R,
            'group_size' => $ready ? count($top) : null,
            'questions' => $out,
        ];
    }
}
