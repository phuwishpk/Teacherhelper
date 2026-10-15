<?php

namespace App\Domain\Courses;

use App\Http\Resources\SkillResource;
use App\Models\Skill;
use App\Models\Subject;

/**
 * Maps indicator codes read from a teacher's document to `skills` (DESIGN
 * §20.1): codes are compared after normalising spaces and dots (and Thai
 * digits), so "ค 1.1 ป.5/1", "ค1.1 ป 5/1" and "ค ๑.๑ ป.๕/๑" all find
 * ค 1.1 ป.5/1. Only indicators and sub-indicators the school sees match;
 * an exact code wins over a normalised one, the curriculum over a school's
 * own row. Codes that match nothing come back with skill = null: the app
 * lets the teacher pick one or add it (POST /skills).
 */
final class IndicatorMatcher
{
    private const THAI_DIGITS = ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9'];

    public static function normalize(string $code): string
    {
        $code = strtr($code, self::THAI_DIGITS);
        $code = (string) preg_replace('/[\s\x{00A0}\x{200B}.]+/u', '', $code);

        return mb_strtolower($code);
    }

    /**
     * @param  list<string>  $codes  as read
     * @return array<string, Skill|null> keyed by the code as read
     */
    public function match(?int $schoolId, array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, fn ($c) => trim((string) $c) !== '')));
        if ($codes === []) {
            return [];
        }

        $query = Skill::query()
            ->visibleToSchool($schoolId)
            ->whereIn('level', Skill::ASSESSABLE_LEVELS)
            ->orderByRaw('school_id is not null')
            ->orderBy('id');
        $subjectIds = $this->subjectIds($codes);
        if ($subjectIds !== []) {
            $query->whereIn('subject_id', $subjectIds);
        }

        $exact = [];
        $normalised = [];
        foreach ($query->get() as $skill) {
            $exact[$skill->code] ??= $skill;
            $normalised[self::normalize($skill->code)] ??= $skill;
        }

        $out = [];
        foreach ($codes as $code) {
            $out[$code] = $exact[$code] ?? $normalised[self::normalize($code)] ?? null;
        }

        return $out;
    }

    /**
     * [{code, skill: indicator|null}] for every code of a read result, in order.
     *
     * @param  array<string, mixed>  $result  CourseDocumentResult
     * @return list<array{code: string, skill: array<string, mixed>|null}>
     */
    public function forResult(?int $schoolId, array $result): array
    {
        $matches = $this->match($schoolId, CourseDocumentResult::allCodes($result));

        return array_map(fn (string $code, ?Skill $skill) => [
            'code' => $code,
            'skill' => $skill === null ? null : SkillResource::indicator($skill),
        ], array_keys($matches), array_values($matches));
    }

    /**
     * The subjects the codes name (the letters before the first digit, e.g.
     * ค), to load only their indicators; none found = every subject.
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function subjectIds(array $codes): array
    {
        $prefixes = [];
        foreach ($codes as $code) {
            if (preg_match('/^\s*([^\s\d๐-๙.]+)/u', $code, $m) === 1) {
                $prefixes[$m[1]] = true;
            }
        }
        if ($prefixes === []) {
            return [];
        }

        return Subject::query()->whereIn('code', array_keys($prefixes))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
