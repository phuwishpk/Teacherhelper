<?php

namespace App\Domain\Assistant;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Courses\IndicatorMatcher;
use App\Domain\Courses\IndicatorScope;
use App\Domain\Skills\TeacherIndicators;
use App\Domain\Skills\TeacherSkills;
use App\Exceptions\ApiException;
use App\Models\Course;
use App\Models\IndicatorSuggestion;
use App\Models\Question;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An indicator card (DESIGN §37.7), items the teacher ticks:
 *
 * - `new`: an indicator of their own under a standard (or a sub-indicator
 *   under an indicator), created like "เพิ่มตัวชี้วัด" (TeacherSkills::create,
 *   shared with the whole school);
 * - `existing`: a curriculum (or school) indicator added to "ตัวชี้วัดของฉัน"
 *   (teacher_indicators).
 *
 * Either may name questions of the card's assignment: an indicator in the
 * assignment's IndicatorScope becomes an indicator_suggestion for them,
 * which the teacher confirms in the mapping screen as before.
 *
 *   {kind: indicators, target: describe()|null, items: [{action, code,
 *    name, grade_level?, skill_id?, parent?: {id, code, name}, reason,
 *    question_positions[], flags[]}]}
 *
 * Flags: not_found (the code or the parent is unknown), code_taken (a new
 * code already exists), already_mine, not_in_scope (the questions cannot
 * get it as a suggestion). Items with not_found or code_taken are not
 * ticked and are skipped.
 */
final class IndicatorCards
{
    public const MAX_ITEMS = 20;

    public const FLAG_NOT_FOUND = 'not_found';

    public const FLAG_CODE_TAKEN = 'code_taken';

    public const FLAG_ALREADY_MINE = 'already_mine';

    public const FLAG_NOT_IN_SCOPE = 'not_in_scope';

    public const BLOCKING = [self::FLAG_NOT_FOUND, self::FLAG_CODE_TAKEN];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalise(array $raw, User $teacher): ?array
    {
        $rawTarget = is_array($raw['target'] ?? null) ? $raw['target'] : [];
        $target = isset($rawTarget['assignment_id']) ? AssistantPlans::target(['assignment_id' => $rawTarget['assignment_id']], $teacher) : null;
        $scope = isset($target['assignment_id'])
            ? IndicatorScope::of(ClassroomAccess::managedAssignments($teacher)->findOrFail($target['assignment_id']))?->skillIds() ?? []
            : [];
        $mine = TeacherIndicators::ids($teacher);
        $subjects = Course::query()->where('created_by', $teacher->id)->distinct()->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        $items = [];
        foreach (array_slice(is_array($raw['indicators'] ?? null) ? $raw['indicators'] : [], 0, self::MAX_ITEMS) as $i) {
            if (! is_array($i) || ! in_array($i['action'] ?? null, ['new', 'existing'], true)) {
                continue;
            }
            $positions = [];
            foreach (is_array($i['question_positions'] ?? null) ? $i['question_positions'] : [] as $p) {
                $p = filter_var($p, FILTER_VALIDATE_INT);
                if ($p !== false && $p >= 1 && ! in_array($p, $positions, true) && count($positions) < 10) {
                    $positions[] = $p;
                }
            }
            sort($positions);
            $item = [
                'action' => $i['action'],
                'code' => mb_substr(trim((string) ($i['code'] ?? '')), 0, 40),
                'name' => mb_substr(trim((string) ($i['name'] ?? '')), 0, 1000),
                'reason' => mb_substr(trim((string) ($i['reason'] ?? '')), 0, 300),
                'question_positions' => $target === null ? [] : $positions,
                'flags' => [],
            ];
            if ($item['action'] === 'existing') {
                $skill = self::find($item['code'], $teacher, $subjects, Skill::ASSESSABLE_LEVELS);
                if ($skill === null) {
                    $item['flags'][] = self::FLAG_NOT_FOUND;
                } else {
                    $item = ['skill_id' => $skill->id, 'code' => $skill->code, 'name' => $skill->name] + $item;
                    if (in_array($skill->id, $mine, true)) {
                        $item['flags'][] = self::FLAG_ALREADY_MINE;
                    }
                }
                $mapped = isset($skill) && in_array($skill->id, $scope, true);
            } else {
                if ($item['name'] === '') {
                    continue;
                }
                $parent = self::find(mb_substr(trim((string) ($i['parent_code'] ?? '')), 0, 40), $teacher, $subjects, [Skill::LEVEL_STANDARD, Skill::LEVEL_INDICATOR]);
                $grade = filter_var($i['grade_level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
                $item['grade_level'] = $grade === false ? null : $grade;
                if ($parent === null) {
                    $item['flags'][] = self::FLAG_NOT_FOUND;
                } else {
                    $item['parent'] = ['id' => $parent->id, 'code' => $parent->code, 'name' => mb_substr($parent->name, 0, 200)];
                }
                if ($item['code'] !== '' && Skill::query()->visibleToSchool($teacher->school_id)->where('code', $item['code'])->exists()) {
                    $item['flags'][] = self::FLAG_CODE_TAKEN;
                }
                $mapped = false; // a new indicator is in no lesson plan yet
            }
            if ($item['question_positions'] !== [] && ! $mapped) {
                $item['flags'][] = self::FLAG_NOT_IN_SCOPE;
            }
            $items[] = $item;
        }

        return $items === [] ? null : ['kind' => AssistantPlans::CARD_INDICATORS, 'target' => $target, 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<int>  $selected
     * @return array<string, mixed> the result
     *
     * @throws ApiException 422 plan_nothing_created
     */
    public static function apply(User $teacher, array $card, array $selected, TeacherSkills $skills): array
    {
        $assignment = null;
        if (isset($card['target']['assignment_id'])) {
            $assignment = ClassroomAccess::managedAssignments($teacher)->find($card['target']['assignment_id']);
            if ($assignment !== null && ! Gate::forUser($teacher)->allows('update', $assignment)) {
                $assignment = null;
            }
        }
        $scope = $assignment === null ? [] : (IndicatorScope::of($assignment)?->skillIds() ?? []);
        $questions = $assignment === null ? collect() : Question::query()->where('assignment_id', $assignment->id)->pluck('id', 'position');

        $created = 0;
        $mine = [];
        $suggestions = [];
        $skipped = [];
        foreach ($selected as $index) {
            $item = $card['items'][$index];
            if (array_intersect($item['flags'], self::BLOCKING) !== []) {
                $skipped[] = ['index' => $index, 'reason' => in_array(self::FLAG_CODE_TAKEN, $item['flags'], true) ? 'รหัสนี้มีอยู่แล้ว' : 'ไม่พบรหัสนี้'];

                continue;
            }
            if ($item['action'] === 'new') {
                try {
                    $skills->create($teacher, array_filter([
                        'parent_id' => $item['parent']['id'],
                        'name' => $item['name'],
                        'code' => $item['code'] === '' ? null : $item['code'],
                        'grade_level' => $item['grade_level'],
                    ], fn ($v) => $v !== null));
                    $created++;
                } catch (ApiException $e) {
                    $skipped[] = ['index' => $index, 'reason' => $e->getMessage()];
                }

                continue;
            }
            $mine[] = (int) $item['skill_id'];
            if (in_array($item['skill_id'], $scope, true)) {
                foreach ($item['question_positions'] as $position) {
                    if (isset($questions[$position])) {
                        $suggestions[] = ['question_id' => (int) $questions[$position], 'skill_id' => (int) $item['skill_id'], 'reason_th' => AssistantPlanApplier::SUGGESTION_REASON, 'created_at' => now()];
                    }
                }
            }
        }
        $current = TeacherIndicators::ids($teacher);
        $added = array_values(array_diff(array_unique($mine), $current));
        if ($added !== []) {
            TeacherIndicators::replace($teacher, [...$current, ...$added]);
        }
        if ($suggestions !== []) {
            IndicatorSuggestion::query()->insertOrIgnore($suggestions);
        }
        if ($created === 0 && $mine === [] && $suggestions === []) {
            throw new ApiException('ไม่มีรายการที่ทำได้ '.implode(' ', array_unique(array_column($skipped, 'reason'))), 'plan_nothing_created', 422, [], ['skipped' => $skipped]);
        }

        return [
            'created' => $created,
            'added_mine' => count($added),
            'mapped' => count($suggestions),
            'skipped' => $skipped,
            'assignment_id' => $suggestions === [] ? null : $assignment?->id,
        ];
    }

    /**
     * The skill a code names, among the school's and the curriculum's
     * (exact, then normalised; the teacher's subjects first).
     *
     * @param  list<int>  $subjects
     * @param  list<string>  $levels
     */
    private static function find(string $code, User $teacher, array $subjects, array $levels): ?Skill
    {
        if ($code === '') {
            return null;
        }
        $candidates = Skill::query()->visibleToSchool($teacher->school_id)->notTrashed()->whereIn('level', $levels)
            ->where('code', 'like', mb_substr($code, 0, 1).'%')->limit(2000)->get();
        $wanted = IndicatorMatcher::normalize($code);
        $matches = $candidates->filter(fn (Skill $s) => $s->code === $code)
            ->whenEmpty(fn () => $candidates->filter(fn (Skill $s) => IndicatorMatcher::normalize($s->code) === $wanted));

        return $matches->sortBy(fn (Skill $s) => in_array($s->subject_id, $subjects, true) ? 0 : 1)->first();
    }
}
