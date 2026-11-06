<?php

namespace App\Domain\Assistant;

use App\Domain\Gradebook\GradebookSettings;
use App\Domain\Gradebook\GradeCutoffs;
use App\Exceptions\ApiException;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A gradebook card (DESIGN §37.9): new categories and/or grade cutoffs for
 * one of the teacher's own courses, saved like the gradebook settings
 * screen (GradebookSettings::replaceCategories, ::setCutoffs).
 *
 *   {kind: gradebook, target: {course_id, title}, categories: [{id?, name,
 *    weight, drop_lowest, is_homework_default}]|null, cutoffs: [7]|null,
 *    current: {categories: [{name, weight, item_count}], cutoffs},
 *    removed: [{name, item_count}], problem?}
 *
 * A proposed category with the name of a current one keeps its id (and the
 * work in it); a current category left out is removed and its work becomes
 * "ยังไม่ระบุหมวด" (the card lists them). Weights that do not add up to 100
 * or cutoffs that do not go down are a `problem`. Published grades are not
 * touched: the gradebook says they changed and the teacher publishes again.
 */
final class GradebookCards
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalise(array $raw, User $teacher): ?array
    {
        $course = self::course($teacher, $raw['course_id'] ?? null);
        if ($course === null) {
            return ['kind' => AssistantPlans::CARD_GRADEBOOK, 'target' => ['error' => 'ไม่พบรายวิชานี้ในรายวิชาของครู'], 'categories' => null, 'cutoffs' => null, 'current' => null, 'removed' => [], 'problem' => 'ระบุรายวิชาให้ผู้ช่วยอีกครั้ง'];
        }
        $current = GradebookSettings::payload($course);
        $byName = [];
        foreach ($current['categories'] as $c) {
            $byName[mb_strtolower(trim($c['name']))] = $c;
        }

        $categories = null;
        if (is_array($raw['categories'] ?? null) && $raw['categories'] !== []) {
            $categories = [];
            foreach (array_slice($raw['categories'], 0, GradebookCategory::MAX_CATEGORIES) as $c) {
                $name = is_array($c) ? mb_substr(trim((string) ($c['name'] ?? '')), 0, 100) : '';
                $weight = is_array($c) ? filter_var($c['weight'] ?? null, FILTER_VALIDATE_FLOAT) : false;
                if ($name === '' || $weight === false) {
                    continue;
                }
                $existing = $byName[mb_strtolower($name)] ?? null;
                $categories[] = [
                    ...($existing === null ? [] : ['id' => $existing['id']]),
                    'name' => $name,
                    'weight' => round($weight, 2),
                    'drop_lowest' => max(0, min(GradebookCategory::MAX_DROP_LOWEST, (int) ($c['drop_lowest'] ?? 0))),
                    'is_homework_default' => ($c['is_homework_default'] ?? false) === true,
                ];
            }
            $categories = $categories === [] ? null : $categories;
        }
        $cutoffs = null;
        if (is_array($raw['cutoffs'] ?? null) && $raw['cutoffs'] !== []) {
            $cutoffs = array_values(array_map(fn ($v) => (int) $v, array_filter($raw['cutoffs'], 'is_numeric')));
        }
        if ($categories === null && $cutoffs === null) {
            return null;
        }
        $kept = array_column($categories ?? [], 'id');
        $removed = $categories === null ? [] : array_values(array_map(
            fn (array $c) => ['name' => $c['name'], 'item_count' => $c['item_count']],
            array_filter($current['categories'], fn (array $c) => ! in_array($c['id'], $kept, true)),
        ));

        $card = [
            'kind' => AssistantPlans::CARD_GRADEBOOK,
            'target' => ['course_id' => $course->id, 'title' => trim($course->code.' '.$course->name)],
            'categories' => $categories,
            'cutoffs' => $cutoffs,
            'current' => [
                'categories' => array_map(fn (array $c) => ['name' => $c['name'], 'weight' => (float) $c['weight'], 'drop_lowest' => $c['drop_lowest'], 'item_count' => $c['item_count']], $current['categories']),
                'cutoffs' => $current['cutoffs'],
            ],
            'removed' => $removed,
        ];
        $problem = self::problem($categories, $cutoffs);

        return $problem === null ? $card : $card + ['problem' => $problem];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed> the result
     *
     * @throws ApiException 422 plan_invalid
     */
    public static function apply(User $teacher, array $card): array
    {
        if (isset($card['problem'])) {
            throw new ApiException((string) $card['problem'], 'plan_invalid', 422);
        }
        $course = self::course($teacher, $card['target']['course_id'] ?? null);
        if ($course === null) {
            throw new ApiException('ไม่พบรายวิชานี้ในรายวิชาของครู', 'not_found', 404);
        }
        Gate::forUser($teacher)->authorize('update', $course);
        try {
            DB::transaction(function () use ($course, $card) {
                if ($card['categories'] !== null) {
                    GradebookSettings::replaceCategories($course, $card['categories']);
                }
                if ($card['cutoffs'] !== null) {
                    GradebookSettings::setCutoffs($course, $card['cutoffs']);
                }
            });
        } catch (ValidationException $e) {
            throw new ApiException(collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง', 'plan_invalid', 422);
        }

        return [
            'course_id' => $course->id,
            'title' => $card['target']['title'],
            'categories' => $card['categories'] === null ? null : count($card['categories']),
            'cutoffs' => $card['cutoffs'] !== null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $categories
     * @param  list<int>|null  $cutoffs
     */
    private static function problem(?array $categories, ?array $cutoffs): ?string
    {
        if ($categories !== null) {
            $sum = (int) round(array_sum(array_map(fn (array $c) => round($c['weight'] * 100), $categories)));
            if ($sum !== 10000) {
                return 'น้ำหนักทุกหมวดรวมกันต้องได้ 100 (ตอนนี้ได้ '.GradebookSettings::formatWeight($sum / 100).')';
            }
            if (count(array_filter($categories, fn (array $c) => $c['is_homework_default'])) > 1) {
                return 'หมวดตั้งต้นของการบ้านมีได้หมวดเดียว';
            }
            $names = array_map(fn (array $c) => mb_strtolower($c['name']), $categories);
            if (count(array_unique($names)) !== count($names)) {
                return 'ชื่อหมวดซ้ำกัน';
            }
        }
        if ($cutoffs !== null) {
            return GradeCutoffs::problem($cutoffs);
        }

        return null;
    }

    private static function course(User $teacher, mixed $id): ?Course
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);

        return $id === false ? null : Course::query()->where('school_id', $teacher->school_id)->where('created_by', $teacher->id)->find($id);
    }
}
