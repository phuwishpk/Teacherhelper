<?php

namespace App\Domain\Mastery;

use App\Http\Resources\SkillResource;
use App\Models\Mastery;
use App\Models\Skill;
use App\Models\SkillObservation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Chart (1) of DESIGN §20.4: one student's mastery after each observation,
 * one line per indicator, replaying the EWMA of §14.2 over
 * skill_observations (MasteryCalculator::ewmaSeries). At most MAX_SKILLS
 * lines at once.
 *
 * Also lists every skill the student has mastery for (the picker). Without
 * skill ids, the lines are the MAX_SKILLS skills observed most recently.
 */
final class IndicatorProgress
{
    public const MAX_SKILLS = 5;

    public const DISPLAY_TIMEZONE = 'Asia/Bangkok';

    /**
     * Parses `skill_ids` (a comma list or an array): distinct positive
     * integers, at most MAX_SKILLS (422 errors.skill_ids otherwise).
     *
     * @return list<int>|null null when not given
     */
    public static function parseIds(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];
        foreach ($parts as $part) {
            $part = is_string($part) ? trim($part) : $part;
            if (! is_numeric($part) || (int) $part < 1 || (string) (int) $part !== (string) $part) {
                throw ValidationException::withMessages(['skill_ids' => 'รหัสตัวชี้วัดไม่ถูกต้อง']);
            }
            $ids[(int) $part] = true;
        }
        if (count($ids) > self::MAX_SKILLS) {
            throw ValidationException::withMessages(['skill_ids' => 'เลือกได้ไม่เกิน '.self::MAX_SKILLS.' ตัวชี้วัดพร้อมกัน']);
        }

        return array_keys($ids);
    }

    /**
     * @param  list<int>|null  $skillIds  from parseIds(); must be skills the school sees (422 otherwise)
     * @param  list<int>|null  $allowed  the only indicators the caller may see (a subject teacher's
     *                                   course, DESIGN §24.8); null = all
     * @return array{student_id: int, skill_ids: list<int>, skills: list<array<string, mixed>>, series: list<array<string, mixed>>}
     */
    public function forStudent(int $studentId, int $schoolId, ?array $skillIds, ?array $allowed = null): array
    {
        if ($allowed !== null && $skillIds !== null && array_diff($skillIds, $allowed) !== []) {
            throw ValidationException::withMessages(['skill_ids' => 'ไม่พบตัวชี้วัดที่เลือกในรายวิชานี้']);
        }
        $mastery = Mastery::query()->where('student_id', $studentId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('skill_id', $allowed === [] ? [0] : $allowed))
            ->with('skill')->get()
            ->filter(fn (Mastery $m) => $m->skill !== null)
            ->sort(fn (Mastery $a, Mastery $b) => strnatcmp($a->skill->code, $b->skill->code) ?: $a->skill_id <=> $b->skill_id)
            ->values();

        if ($skillIds === null) {
            $skillIds = SkillObservation::query()
                ->where('student_id', $studentId)
                ->when($allowed !== null, fn ($q) => $q->whereIn('skill_id', $allowed === [] ? [0] : $allowed))
                ->groupBy('skill_id')
                ->selectRaw('skill_id, MAX(observed_at) AS last_at')
                ->orderByDesc('last_at')
                ->orderBy('skill_id')
                ->limit(self::MAX_SKILLS)
                ->pluck('skill_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $skills = Skill::query()
            ->whereIn('id', $skillIds)
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId))
            ->get()
            ->keyBy('id');
        if ($skills->count() !== count($skillIds)) {
            throw ValidationException::withMessages(['skill_ids' => 'ไม่พบตัวชี้วัดที่เลือก']);
        }

        $observations = SkillObservation::query()
            ->where('student_id', $studentId)
            ->whereIn('skill_id', $skillIds)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get(['skill_id', 'score_ratio', 'source', 'observed_at'])
            ->groupBy('skill_id');

        $ordered = $skills->values()->all();
        usort($ordered, fn (Skill $a, Skill $b) => strnatcmp($a->code, $b->code) ?: $a->id <=> $b->id);

        $series = [];
        foreach ($ordered as $skill) {
            $rows = ($observations[$skill->id] ?? collect())->values();
            $values = MasteryCalculator::ewmaSeries($rows->map(fn (SkillObservation $o) => ['score_ratio' => $o->score_ratio, 'source' => $o->source])->all());
            $series[] = [
                'skill' => SkillResource::indicator($skill),
                'points' => $rows->map(fn (SkillObservation $o, int $i) => [
                    'observed_at' => $o->observed_at->copy()->utc()->toIso8601ZuluString(),
                    'date' => Carbon::parse($o->observed_at)->setTimezone(self::DISPLAY_TIMEZONE)->toDateString(),
                    'value' => $values[$i],
                    'score_ratio' => MasteryCalculator::round3((float) $o->score_ratio),
                    'source' => $o->source,
                ])->all(),
            ];
        }

        return [
            'student_id' => $studentId,
            'skill_ids' => array_map(fn (Skill $s) => $s->id, $ordered),
            'skills' => $mastery->map(fn (Mastery $m) => [
                'skill' => SkillResource::indicator($m->skill),
                'value' => (float) $m->value,
                'n_obs' => (int) $m->n_obs,
            ])->all(),
            'series' => $series,
        ];
    }
}
