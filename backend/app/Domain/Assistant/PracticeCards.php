<?php

namespace App\Domain\Assistant;

use App\Domain\Courses\IndicatorMatcher;
use App\Domain\Practice\PracticeItemData;
use App\Exceptions\ApiException;
use App\Models\Course;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A practice card (DESIGN §37.9): practice items for one indicator,
 * checked with PracticeItemData::validate and saved as drafts from AI
 * (source ai, status draft), like "ให้ AI สร้างแบบฝึก": the teacher
 * approves them in the practice bank.
 *
 *   {kind: practice, target: {skill_id, code, title}|{error}, items:
 *    [{answer_type, prompt_text, options[], answer_key, explanation,
 *    error?}]}
 *
 * An item the validation refuses keeps its `error` and cannot be ticked.
 */
final class PracticeCards
{
    public const MAX_ITEMS = 20;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalise(array $raw, User $teacher): ?array
    {
        $skill = self::skill(trim((string) ($raw['skill_code'] ?? '')), $teacher);
        $items = [];
        foreach (array_slice(is_array($raw['practice_items'] ?? null) ? $raw['practice_items'] : [], 0, self::MAX_ITEMS) as $i) {
            if (! is_array($i)) {
                continue;
            }
            $type = in_array($i['answer_type'] ?? null, PracticeItem::TYPES, true) ? $i['answer_type'] : PracticeItem::TYPE_SHORT;
            $options = $type === PracticeItem::TYPE_MCQ ? array_values(array_filter(array_map(fn ($o) => is_scalar($o) ? mb_substr(trim((string) $o), 0, 255) : '', is_array($i['options'] ?? null) ? $i['options'] : []))) : [];
            $accepted = array_values(array_filter(array_map(fn ($a) => is_scalar($a) ? mb_substr(trim((string) $a), 0, 255) : '', is_array($i['accepted_answers'] ?? null) ? $i['accepted_answers'] : [])));
            $correct = filter_var($i['correct_option'] ?? null, FILTER_VALIDATE_INT);
            $input = [
                'answer_type' => $type,
                'prompt_text' => mb_substr(trim((string) ($i['prompt_text'] ?? '')), 0, 2000),
                'explanation' => mb_substr(trim((string) ($i['explanation'] ?? '')), 0, 2000),
                ...($type === PracticeItem::TYPE_MCQ ? [
                    'options' => array_map(fn (string $t, int $k) => ['key' => PracticeItemData::letter($k), 'text' => $t], $options, array_keys($options)),
                    'answer_key' => ['correct' => $correct !== false && $correct >= 1 && $correct <= count($options) ? PracticeItemData::letter($correct - 1) : ''],
                ] : ($type === PracticeItem::TYPE_NUMERIC ? [
                    'answer_key' => ['numeric' => ['value' => is_numeric($i['numeric_value'] ?? null) ? (float) $i['numeric_value'] : null, 'abs_tol' => 0], ...($accepted === [] ? [] : ['accepted' => $accepted])],
                ] : [
                    'answer_key' => ['accepted' => $accepted],
                ])),
            ];
            if ($input['prompt_text'] === '') {
                continue;
            }
            try {
                $items[] = PracticeItemData::validate($input) + ['options' => null];
            } catch (ValidationException $e) {
                $items[] = $input + ['options' => $input['options'] ?? null, 'error' => collect($e->errors())->flatten()->first() ?? 'ข้อนี้ไม่ครบ'];
            }
        }
        if ($items === []) {
            return null;
        }

        return [
            'kind' => AssistantPlans::CARD_PRACTICE,
            'target' => $skill === null
                ? ['error' => 'ไม่พบตัวชี้วัดรหัสนี้ ระบุตัวชี้วัดให้ผู้ช่วยอีกครั้ง']
                : ['skill_id' => $skill->id, 'code' => $skill->code, 'title' => trim($skill->code.' '.mb_substr($skill->name, 0, 160))],
            'items' => array_map(fn (array $item) => array_diff_key($item, ['status' => 0]), $items),
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<int>  $selected
     * @return array<string, mixed> the result
     *
     * @throws ApiException 422 plan_no_target / plan_nothing_created
     */
    public static function apply(User $teacher, array $card, array $selected): array
    {
        AssistantPlans::assertTarget($card['target']);
        $skill = Skill::query()->visibleToSchool($teacher->school_id)->notTrashed()->find($card['target']['skill_id']);
        if ($skill === null) {
            throw new ApiException('ไม่พบตัวชี้วัดนี้แล้ว', 'plan_no_target', 422);
        }
        $created = 0;
        $skipped = [];
        DB::transaction(function () use ($teacher, $card, $selected, $skill, &$created, &$skipped) {
            foreach ($selected as $index) {
                $item = $card['items'][$index];
                if (isset($item['error'])) {
                    $skipped[] = ['index' => $index, 'reason' => $item['error']];

                    continue;
                }
                $data = PracticeItemData::validate([...$item, 'status' => PracticeItem::STATUS_DRAFT]);
                PracticeItem::create([
                    'school_id' => $teacher->school_id,
                    'skill_id' => $skill->id,
                    'source' => PracticeItem::SOURCE_AI,
                    ...$data,
                ]);
                $created++;
            }
        });
        if ($created === 0) {
            throw new ApiException('ไม่มีข้อที่สร้างได้', 'plan_nothing_created', 422, [], ['skipped' => $skipped]);
        }

        return ['skill_id' => $skill->id, 'title' => $card['target']['title'], 'created' => $created, 'skipped' => $skipped];
    }

    private static function skill(string $code, User $teacher): ?Skill
    {
        if ($code === '') {
            return null;
        }
        $subjects = Course::query()->where('created_by', $teacher->id)->distinct()->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        $candidates = Skill::query()->visibleToSchool($teacher->school_id)->notTrashed()->whereIn('level', Skill::ASSESSABLE_LEVELS)
            ->where('code', 'like', mb_substr($code, 0, 1).'%')->limit(2000)->get();
        $wanted = IndicatorMatcher::normalize($code);
        $matches = $candidates->filter(fn (Skill $s) => $s->code === $code)
            ->whenEmpty(fn () => $candidates->filter(fn (Skill $s) => IndicatorMatcher::normalize($s->code) === $wanted));

        return $matches->sortBy(fn (Skill $s) => in_array($s->subject_id, $subjects, true) ? 0 : 1)->first();
    }
}
