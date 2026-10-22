<?php

namespace App\Domain\Gradebook;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The per-course gradebook settings (DESIGN §23.2): categories with weights
 * (set from a template once, then replaced as a whole set), the default
 * category of new homework and the grade cutoffs. Shared by every
 * classroom bound to the course.
 */
final class GradebookSettings
{
    /**
     * GET /gradebook/templates.
     *
     * @return list<array{key: string, name: string, categories: list<array{name: string, weight: float, is_homework_default: bool}>}>
     */
    public static function templates(): array
    {
        $out = [];
        foreach ((array) config('eduvision.gradebook.templates', []) as $key => $template) {
            $out[] = [
                'key' => (string) $key,
                'name' => (string) $template['name'],
                'categories' => array_map(fn (array $c) => [
                    'name' => (string) $c['name'],
                    'weight' => (float) $c['weight'],
                    'is_homework_default' => (bool) ($c['is_homework_default'] ?? false),
                ], $template['categories']),
            ];
        }

        return $out;
    }

    public static function configured(Course $course): bool
    {
        return $course->gradebookCategories()->exists();
    }

    /** The category new homework of the course gets (§23.3), if the gradebook is set up. */
    public static function homeworkDefaultId(?int $courseId): ?int
    {
        if ($courseId === null) {
            return null;
        }
        $id = GradebookCategory::query()->where('course_id', $courseId)->where('is_homework_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The settings payload of GET /courses/{id}/gradebook/settings.
     *
     * @return array<string, mixed>
     */
    public static function payload(Course $course): array
    {
        $categories = $course->gradebookCategories()->get();
        $assignmentCounts = Assignment::query()->where('course_id', $course->id)->whereNotNull('gradebook_category_id')
            ->groupBy('gradebook_category_id')->selectRaw('gradebook_category_id, COUNT(*) AS n')->pluck('n', 'gradebook_category_id');
        $itemCounts = GradebookItem::query()->where('course_id', $course->id)->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS n')->pluck('n', 'category_id');

        return [
            'configured' => $categories->isNotEmpty(),
            'template' => $course->gradebook_template,
            'categories' => $categories->map(fn (GradebookCategory $c) => [
                'id' => $c->id,
                'position' => $c->position,
                'name' => $c->name,
                'weight' => $c->weight,
                'drop_lowest' => $c->drop_lowest,
                'is_homework_default' => $c->is_homework_default,
                'item_count' => (int) ($assignmentCounts[$c->id] ?? 0) + (int) ($itemCounts[$c->id] ?? 0),
            ])->values()->all(),
            'cutoffs' => GradeCutoffs::of($course),
            'default_cutoffs' => GradeCutoffs::defaults(),
            'uncategorised_count' => $categories->isEmpty() ? 0 : self::uncategorisedCount($course),
        ];
    }

    /** Assignments and items of the course without a category ("ยังไม่ระบุหมวด", not counted). */
    public static function uncategorisedCount(Course $course): int
    {
        return Assignment::query()->where('course_id', $course->id)->whereNull('gradebook_category_id')->count()
            + GradebookItem::query()->where('course_id', $course->id)->whereNull('category_id')->count();
    }

    /**
     * Sets the categories from a template: only while the course has none
     * (409 gradebook_configured).
     */
    public static function applyTemplate(Course $course, string $key): void
    {
        $template = config("eduvision.gradebook.templates.{$key}");
        if (! is_array($template)) {
            throw ValidationException::withMessages(['template' => 'ไม่พบ template นี้']);
        }
        DB::transaction(function () use ($course, $key, $template) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            if (self::configured($course)) {
                throw new ApiException('รายวิชานี้ตั้งค่าสมุดคะแนนแล้ว แก้หมวดคะแนนแทนการเลือก template', 'gradebook_configured', 409);
            }
            foreach (array_values($template['categories']) as $i => $category) {
                GradebookCategory::create([
                    'course_id' => $course->id,
                    'position' => $i + 1,
                    'name' => $category['name'],
                    'weight' => $category['weight'],
                    'drop_lowest' => 0,
                    'is_homework_default' => (bool) ($category['is_homework_default'] ?? false),
                ]);
            }
            $course->forceFill(['gradebook_template' => $key])->save();
            self::assignFirstSetup($course);
        });
    }

    /**
     * Replaces the whole set in the order sent (§23.2): an entry with an id
     * of the course keeps that category, one without an id is new, a
     * category left out is deleted (its assignments and items become
     * "ยังไม่ระบุหมวด").
     *
     * @param  mixed  $input  the request's `categories`
     */
    public static function replaceCategories(Course $course, mixed $input): void
    {
        $validator = Validator::make(['categories' => $input], [
            'categories' => ['required', 'array', 'min:1', 'max:'.GradebookCategory::MAX_CATEGORIES],
            'categories.*' => ['required', 'array:id,name,weight,drop_lowest,is_homework_default'],
            'categories.*.id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:100'],
            'categories.*.weight' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'categories.*.drop_lowest' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.GradebookCategory::MAX_DROP_LOWEST],
            'categories.*.is_homework_default' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'categories.required' => 'ต้องมีอย่างน้อยหนึ่งหมวด',
            'categories.max' => 'มีได้ไม่เกิน '.GradebookCategory::MAX_CATEGORIES.' หมวด',
            'categories.*.name.required' => 'กรุณาตั้งชื่อหมวด',
            'categories.*.name.max' => 'ชื่อหมวดยาวไม่เกิน 100 ตัวอักษร',
            'categories.*.weight.required' => 'กรุณาใส่น้ำหนักของหมวด',
            'categories.*.weight.gt' => 'น้ำหนักของหมวดต้องมากกว่า 0',
            'categories.*.weight.max' => 'น้ำหนักของหมวดต้องไม่เกิน 100',
            'categories.*.weight.decimal' => 'น้ำหนักมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
            'categories.*.drop_lowest.max' => 'ตัดคะแนนต่ำสุดได้ไม่เกิน '.GradebookCategory::MAX_DROP_LOWEST.' รายการ',
        ]);
        $rows = $validator->validate()['categories'];

        $errors = [];
        $names = [];
        $defaults = 0;
        $cents = 0;
        foreach ($rows as $i => $row) {
            $name = trim((string) $row['name']);
            if ($name === '') {
                $errors["categories.{$i}.name"] = ['กรุณาตั้งชื่อหมวด'];
            }
            $folded = mb_strtolower($name);
            if (isset($names[$folded])) {
                $errors["categories.{$i}.name"] = ['ชื่อหมวดซ้ำกัน'];
            }
            $names[$folded] = true;
            $defaults += ! empty($row['is_homework_default']) ? 1 : 0;
            $cents += (int) round((float) $row['weight'] * 100);
        }
        if ($defaults > 1) {
            $errors['categories'] = ['หมวดตั้งต้นของการบ้านมีได้ไม่เกินหนึ่งหมวด'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($cents !== 10000) {
            $message = 'น้ำหนักทุกหมวดรวมกันต้องเท่ากับ 100 (ตอนนี้รวม '.self::formatWeight($cents / 100).')';

            throw new ApiException($message, 'weights_not_100', 422, ['categories' => [$message]]);
        }

        DB::transaction(function () use ($course, $rows) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            /** @var Collection<int, GradebookCategory> $existing */
            $existing = GradebookCategory::query()->where('course_id', $course->id)->get()->keyBy('id');
            $firstSetup = $existing->isEmpty();
            $kept = [];
            foreach ($rows as $i => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;
                if ($id !== null && (! $existing->has($id) || isset($kept[$id]))) {
                    throw ValidationException::withMessages(["categories.{$i}.id" => 'ไม่พบหมวดนี้ในรายวิชา']);
                }
                if ($id !== null) {
                    $kept[$id] = true;
                }
            }
            $removed = $existing->keys()->reject(fn (int $id) => isset($kept[$id]))->values()->all();
            if ($removed !== []) {
                Assignment::query()->whereIn('gradebook_category_id', $removed)->update(['gradebook_category_id' => null]);
                GradebookItem::query()->whereIn('category_id', $removed)->update(['category_id' => null]);
                GradebookCategory::query()->whereIn('id', $removed)->delete();
            }
            // Park the kept rows out of the way of uq_category_position before renumbering.
            GradebookCategory::query()->where('course_id', $course->id)->update(['position' => DB::raw('position + 100')]);
            foreach ($rows as $i => $row) {
                $values = [
                    'position' => $i + 1,
                    'name' => trim((string) $row['name']),
                    'weight' => round((float) $row['weight'], 2),
                    'drop_lowest' => (int) ($row['drop_lowest'] ?? 0),
                    'is_homework_default' => (bool) ($row['is_homework_default'] ?? false),
                ];
                if (isset($row['id'])) {
                    // A query update: the in-memory position is stale after the parking step.
                    GradebookCategory::query()->whereKey((int) $row['id'])->update($values + ['updated_at' => now()]);
                } else {
                    GradebookCategory::create($values + ['course_id' => $course->id]);
                }
            }
            if ($firstSetup) {
                self::assignFirstSetup($course);
            }
        });
    }

    /**
     * PUT /courses/{id}/gradebook/cutoffs {cutoffs: [7 values] | null}.
     */
    public static function setCutoffs(Course $course, mixed $cutoffs): void
    {
        if ($cutoffs !== null) {
            $problem = is_array($cutoffs) ? GradeCutoffs::problem($cutoffs) : 'เกณฑ์เกรดต้องเป็นรายการ 7 ค่า';
            if ($problem !== null) {
                throw ValidationException::withMessages(['cutoffs' => $problem]);
            }
            $cutoffs = array_map('intval', $cutoffs);
        }
        $course->forceFill(['grade_cutoffs' => $cutoffs])->save();
    }

    public static function formatWeight(float $weight): string
    {
        return rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');
    }

    /**
     * First setup (§23.2): homework of the course without a category gets
     * the homework default; exams wait for the teacher's pick.
     */
    private static function assignFirstSetup(Course $course): void
    {
        $default = self::homeworkDefaultId($course->id);
        if ($default === null) {
            return;
        }
        Assignment::query()->where('course_id', $course->id)->where('kind', Assignment::KIND_HOMEWORK)
            ->whereNull('gradebook_category_id')->update(['gradebook_category_id' => $default]);
    }
}
