<?php

namespace App\Domain\Assistant;

use App\Domain\Assignments\RubricService;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\RubricDraft;
use App\Exceptions\ApiException;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A rubric card (DESIGN §37.7): a rubric draft for one show_work or open
 * question of an assignment the teacher manages.
 *
 *   {kind: rubric, target: AssistantPlans::describe(), question: {id,
 *    position, type, prompt, max_points, rubric_status}, criteria:
 *    [{description, points, is_core}], reference_steps: [], problem?}
 *
 * `problem` (a question that needs no rubric, criteria that do not add up
 * to the points or have no single core criterion) blocks «บันทึกเป็นร่าง».
 * Applying stores the draft like "ให้ AI ร่าง rubric" does
 * (RubricService::applyDraft): rubric_status draft, the teacher approves
 * it in the rubric screen.
 */
final class RubricCards
{
    public const MAX_DESCRIPTION = 1000;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalise(array $raw, User $teacher): array
    {
        $target = AssistantPlans::target(is_array($raw['target'] ?? null) ? $raw['target'] : [], $teacher);
        $criteria = [];
        foreach (array_slice(is_array($raw['criteria'] ?? null) ? $raw['criteria'] : [], 0, RubricDraft::MAX_CRITERIA) as $c) {
            $description = is_array($c) ? mb_substr(trim((string) ($c['description'] ?? '')), 0, self::MAX_DESCRIPTION) : '';
            $points = is_array($c) ? filter_var($c['points'] ?? null, FILTER_VALIDATE_FLOAT) : false;
            if ($description !== '' && $points !== false) {
                $criteria[] = ['description' => $description, 'points' => round(max(0, $points), 2), 'is_core' => ($c['is_core'] ?? false) === true];
            }
        }
        $steps = [];
        foreach (array_slice(is_array($raw['reference_steps'] ?? null) ? $raw['reference_steps'] : [], 0, RubricDraft::MAX_STEPS) as $s) {
            $s = is_scalar($s) ? mb_substr(trim((string) $s), 0, 500) : '';
            if ($s !== '') {
                $steps[] = $s;
            }
        }
        $card = ['kind' => AssistantPlans::CARD_RUBRIC, 'target' => $target, 'question' => null, 'criteria' => $criteria, 'reference_steps' => $steps];

        $position = filter_var($raw['question_position'] ?? null, FILTER_VALIDATE_INT);
        $question = isset($target['assignment_id']) && $position !== false
            ? Question::query()->where('assignment_id', $target['assignment_id'])->where('position', $position)->first()
            : null;
        if ($question === null) {
            return $card + ['problem' => 'ไม่พบข้อนี้ในงาน ระบุงานและเลขข้อให้ผู้ช่วยอีกครั้ง'];
        }
        $card['question'] = [
            'id' => $question->id,
            'position' => $question->position,
            'type' => $question->type,
            'prompt' => mb_substr(preg_replace('/\s+/u', ' ', $question->prompt_text) ?? '', 0, 160),
            'max_points' => (float) $question->max_points,
            'rubric_status' => $question->rubric_status,
        ];
        if ($question->type === Question::TYPE_SHOW_WORK) {
            $card['criteria'] = [];
        } elseif ($question->type === Question::TYPE_OPEN) {
            $card['reference_steps'] = [];
        }
        $problem = self::problem($question, $card);

        return $problem === null ? $card : $card + ['problem' => $problem];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed> the result
     *
     * @throws ApiException 409 assignment_closed / plan_stale, 422 plan_invalid
     */
    public static function apply(User $teacher, array $card, RubricService $rubrics): array
    {
        if (isset($card['problem']) || $card['question'] === null) {
            throw new ApiException((string) ($card['problem'] ?? 'การ์ดนี้ใช้ไม่ได้'), 'plan_invalid', 422);
        }
        $assignment = ClassroomAccess::managedAssignments($teacher)->with('classroom')->findOrFail($card['target']['assignment_id']);
        Gate::forUser($teacher)->authorize('update', $assignment);
        ClosedClassrooms::assertOpen($assignment->classroom);
        if ($assignment->isClosed()) {
            throw new ApiException('การบ้านนี้ปิดแล้ว แก้ไขไม่ได้', 'assignment_closed', 409);
        }
        $question = Question::query()->where('assignment_id', $assignment->id)->find($card['question']['id']);
        if ($question === null || $question->type !== $card['question']['type'] || abs((float) $question->max_points - $card['question']['max_points']) > 0.001) {
            throw new ApiException('ข้อนี้ถูกแก้หรือลบหลังผู้ช่วยเสนอ ขอให้ผู้ช่วยทำ rubric ใหม่', 'plan_stale', 409);
        }
        $rubrics->applyDraft($question, new RubricDraft($card['reference_steps'], $card['criteria']));

        return [
            'assignment_id' => $assignment->id,
            'title' => $assignment->title,
            'question_id' => $question->id,
            'position' => $question->position,
        ];
    }

    /** @param  array<string, mixed>  $card */
    private static function problem(Question $question, array $card): ?string
    {
        if (! Question::typeNeedsRubric($question->type)) {
            return 'ข้อ '.$question->position.' เป็นข้อ'.($question->type === Question::TYPE_MCQ ? 'ปรนัย' : 'ตอบสั้น').' ไม่ต้องใช้ rubric';
        }
        try {
            (new RubricDraft($card['reference_steps'], $card['criteria']))->validateFor($question->type, (float) $question->max_points);
        } catch (GeminiException) {
            return $question->type === Question::TYPE_SHOW_WORK
                ? 'ยังไม่มีขั้นตอนอ้างอิง'
                : 'เกณฑ์ต้องมีเกณฑ์หลัก 1 ข้อ และคะแนนรวมเท่ากับคะแนนเต็ม '.self::points((float) $question->max_points).' คะแนน';
        }

        return null;
    }

    private static function points(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    }
}
