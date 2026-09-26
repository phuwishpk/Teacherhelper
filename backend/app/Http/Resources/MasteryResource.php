<?php

namespace App\Http\Resources;

use App\Domain\Mastery\MasteryCalculator;
use App\Models\Mastery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One (student, skill) mastery (DESIGN §9.6, §9.7, §14.2):
 * {skill: {id, code, name, subject_id, grade_level}, skill_id, value, n_obs,
 *  level: good|partial|not_yet|too_little, updated_at}.
 *
 * @mixin Mastery
 */
class MasteryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::row($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Mastery $mastery): array
    {
        return [
            'skill' => $mastery->relationLoaded('skill') && $mastery->skill !== null ? SkillResource::summary($mastery->skill) : null,
            'skill_id' => $mastery->skill_id,
            'value' => (float) $mastery->value,
            'n_obs' => (int) $mastery->n_obs,
            'level' => MasteryCalculator::level((float) $mastery->value, (int) $mastery->n_obs),
            'updated_at' => $mastery->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Weakest first: skills with enough data before "too little", then by
     * value, then by code (§14.3 "จุดอ่อนรายคน" = the first three).
     *
     * @param  Collection<int, Mastery>  $rows
     * @return list<Mastery>
     */
    public static function weakestFirst($rows): array
    {
        return $rows->sortBy(fn (Mastery $m) => [
            (int) $m->n_obs < MasteryCalculator::MIN_OBS_FOR_LEVEL ? 1 : 0,
            (float) $m->value,
            $m->skill?->code ?? '',
            $m->skill_id,
        ])->values()->all();
    }
}
