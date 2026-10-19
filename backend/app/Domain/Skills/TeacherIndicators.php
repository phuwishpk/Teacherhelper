<?php

namespace App\Domain\Skills;

use App\Exceptions\ApiException;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "ตัวชี้วัดของฉัน" (DESIGN §34): the indicators and sub-indicators a
 * teacher teaches, picked by subject and grade in the app and kept as one
 * row per skill. The pickers ask for `GET /skills?mine=1`, which is that
 * set plus every indicator the teacher's workspace added itself; a teacher
 * who picked nothing gets the whole curriculum as before.
 */
final class TeacherIndicators
{
    /** Enough for every indicator of several subjects across all grades. */
    public const MAX = 3000;

    /** @return list<int> */
    public static function ids(User $teacher): array
    {
        return DB::table('teacher_indicators')->where('user_id', $teacher->id)
            ->orderBy('skill_id')->pluck('skill_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replaces the teacher's set. Each id must be an indicator or
     * sub-indicator the teacher's workspace sees.
     *
     * @return list<int> the ids now kept
     *
     * @throws ApiException 422 errors.skill_ids(.{i})
     */
    public static function replace(User $teacher, mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > self::MAX) {
            $message = 'ส่งรายการตัวชี้วัดเป็นรายการ ไม่เกิน '.self::MAX.' ตัว';

            throw new ApiException($message, 'validation_failed', 422, ['skill_ids' => [$message]]);
        }
        foreach ($ids as $i => $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new ApiException('รหัสตัวชี้วัดไม่ถูกต้อง', 'validation_failed', 422, ["skill_ids.{$i}" => ['รหัสตัวชี้วัดไม่ถูกต้อง']]);
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = [...$found, ...Skill::query()
                ->visibleToSchool($teacher->school_id)
                ->whereIn('level', Skill::ASSESSABLE_LEVELS)
                ->whereIn('id', $chunk)
                ->pluck('id')->map(fn ($id) => (int) $id)->all()];
        }
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            $i = array_search($missing[0], $ids, true);
            $message = 'ไม่พบตัวชี้วัดนี้ (เลือกได้เฉพาะตัวชี้วัดหรือทักษะย่อยของหลักสูตร หรือที่คุณเพิ่มเอง)';

            throw new ApiException($message, 'validation_failed', 422, ["skill_ids.{$i}" => [$message]]);
        }

        DB::transaction(function () use ($teacher, $ids) {
            DB::table('teacher_indicators')->where('user_id', $teacher->id)->delete();
            $now = now();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('teacher_indicators')->insert(array_map(fn (int $id) => ['user_id' => $teacher->id, 'skill_id' => $id, 'created_at' => $now], $chunk));
            }
        });
        sort($ids);

        return $ids;
    }

    /**
     * Narrows a skills query to the teacher's set and the workspace's own
     * indicators; leaves it alone when the teacher picked nothing.
     *
     * @param  Builder<Skill>  $query
     */
    public static function narrow(Builder $query, User $teacher): void
    {
        if (! DB::table('teacher_indicators')->where('user_id', $teacher->id)->exists()) {
            return;
        }
        $query->where(fn (Builder $b) => $b
            ->whereIn('skills.id', DB::table('teacher_indicators')->select('skill_id')->where('user_id', $teacher->id))
            ->orWhere(fn (Builder $b) => $b->where('skills.source', Skill::SOURCE_TEACHER)->where('skills.school_id', $teacher->school_id)));
    }
}
