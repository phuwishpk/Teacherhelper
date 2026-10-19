<?php

namespace App\Domain\Skills;

use App\Exceptions\ApiException;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The teacher's trash of their own indicators (DESIGN §34.1). Deleting moves
 * an indicator (and the teacher's sub-indicators under it) to the trash
 * with one moment in skills.deleted_at: the pickers and the AI suggestions
 * stop offering it, but questions, courses, units, plans and results that
 * use it keep it. Restoring brings back what that moment trashed. Deleting
 * for good removes it only while nothing uses it; the RESTRICT foreign keys
 * of skills make sure of that.
 */
final class SkillTrash
{
    /** Tables whose rows keep a skill from being deleted for good, with the Thai name of what they are. */
    public const USES = [
        'question_skill' => 'ข้อสอบหรือการบ้าน',
        'course_indicators' => 'รายวิชา',
        'unit_indicators' => 'หน่วยการเรียนรู้',
        'lesson_plan_indicators' => 'แผนการสอน',
        'skill_observations' => 'ผลการประเมินของนักเรียน',
        'mastery' => 'ผลการประเมินของนักเรียน',
        'practice_items' => 'คลังแบบฝึก',
        'learning_resources' => 'สื่อการเรียนรู้',
        'indicator_suggestions' => 'ข้อเสนอของ AI',
    ];

    public function trash(User $teacher, Skill $skill): void
    {
        if ($skill->isTrashed()) {
            return;
        }
        $now = now()->startOfSecond();
        $ids = [$skill->id, ...self::descendantIds($skill->id, $teacher->school_id, null)];
        Skill::query()->whereIn('id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now]);
        Log::info('skills.trash', ['user_id' => $teacher->id, 'skill_id' => $skill->id, 'count' => count($ids)]);
    }

    /**
     * @throws ApiException 409 parent_in_trash when the indicator above it is in the trash too
     */
    public function restore(User $teacher, Skill $skill): void
    {
        $at = $skill->deleted_at;
        if ($at === null) {
            return;
        }
        if (Skill::query()->whereKey($skill->parent_id)->whereNotNull('deleted_at')->exists()) {
            throw new ApiException('ตัวชี้วัดที่อยู่เหนือตัวนี้อยู่ในถังขยะ กู้คืนตัวนั้นก่อน', 'parent_in_trash', 409);
        }
        $ids = [$skill->id, ...self::descendantIds($skill->id, $teacher->school_id, $at)];
        Skill::query()->whereIn('id', $ids)->where('deleted_at', $at)->update(['deleted_at' => null]);
        Log::info('skills.restore', ['user_id' => $teacher->id, 'skill_id' => $skill->id, 'count' => count($ids)]);
    }

    /**
     * Deletes a trashed indicator (and what was trashed with it) for good.
     *
     * @throws ApiException 409 skill_in_use with `uses` when something still uses it
     */
    public function purge(User $teacher, Skill $skill): void
    {
        $at = $skill->deleted_at;
        if ($at === null) {
            throw new ApiException('ย้ายตัวชี้วัดไปถังขยะก่อนจึงลบถาวรได้', 'not_in_trash', 409);
        }
        $ids = [$skill->id, ...self::descendantIds($skill->id, $teacher->school_id, $at)];
        $uses = self::uses($ids);
        if ($uses !== []) {
            throw new ApiException(
                'ลบถาวรไม่ได้ เพราะยังมี'.implode(' ', array_keys($uses)).'ใช้ตัวชี้วัดนี้อยู่ (เก็บไว้ในถังขยะได้ตามเดิม)',
                'skill_in_use',
                409,
                [],
                ['uses' => $uses],
            );
        }
        DB::transaction(function () use ($ids) {
            // Deepest first: skills.parent_id is RESTRICT.
            foreach (array_reverse($ids) as $id) {
                Skill::query()->whereKey($id)->delete();
            }
        });
        Log::info('skills.purge', ['user_id' => $teacher->id, 'skill_id' => $skill->id, 'count' => count($ids)]);
    }

    /**
     * The teacher's trash, newest first, each with what still uses it.
     *
     * @return Collection<int, array{skill: Skill, uses: array<string, int>}>
     */
    public function list(User $teacher): Collection
    {
        return Skill::query()
            ->where('school_id', $teacher->school_id)
            ->where('source', Skill::SOURCE_TEACHER)
            ->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->orderBy('code')
            ->get()
            ->map(fn (Skill $skill) => ['skill' => $skill, 'uses' => self::uses([$skill->id])]);
    }

    /**
     * What uses any of the skills: Thai name => rows.
     *
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    public static function uses(array $ids): array
    {
        $uses = [];
        foreach (self::USES as $table => $label) {
            $count = (int) DB::table($table)->whereIn('skill_id', $ids)->count();
            if ($count > 0) {
                $uses[$label] = ($uses[$label] ?? 0) + $count;
            }
        }

        return $uses;
    }

    /**
     * The teacher-made skills under $id, breadth first; with $at only those
     * trashed at that moment, without it only those not trashed.
     *
     * @return list<int>
     */
    private static function descendantIds(int $id, ?int $schoolId, ?CarbonInterface $at): array
    {
        $found = [];
        $level = [$id];
        for ($depth = 0; $depth < 5 && $level !== []; $depth++) {
            $level = Skill::query()
                ->whereIn('parent_id', $level)
                ->where('school_id', $schoolId)
                ->where('source', Skill::SOURCE_TEACHER)
                ->when($at === null, fn ($q) => $q->whereNull('deleted_at'), fn ($q) => $q->where('deleted_at', $at))
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $found = [...$found, ...$level];
        }

        return $found;
    }
}
