<?php

namespace App\Domain\Courses;

use App\Domain\Gemini\GeminiException;

/**
 * What Gemini read from a course description, course structure or lesson
 * plans (prompt document_read, DESIGN §20.1), normalised, and the form it
 * is cached in for the whole school (document_extractions.result, purpose
 * course | lesson_plan):
 *
 *   {kind: course|lesson_plan, notes_th,
 *    course: {code, name, subject_code, grade_level, semester,
 *             academic_year, hours, description} (each may be null),
 *    indicators: [{code, text}],
 *    units: [{position, title, hours, description, indicator_codes[]}],
 *    lesson_plans: [{position, unit_position, title, hours, objectives,
 *                    content, activities, assessment, indicator_codes[]}]}
 *
 * Units and plans are renumbered 1..n in the order read; a plan's
 * unit_position points at a unit of the result or is null. Codes are kept
 * as printed; IndicatorMatcher maps them to skills when the result is
 * shown. Nothing here is saved until the teacher confirms it (POST
 * /courses/import).
 */
final class CourseDocumentResult
{
    public const KIND_COURSE = 'course';

    public const KIND_LESSON_PLAN = 'lesson_plan';

    public const KINDS = [self::KIND_COURSE, self::KIND_LESSON_PLAN];

    private const MAX_TEXT = 10000;

    /**
     * Gemini's output, checked beyond the schema; invalid output when the
     * documents gave nothing usable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws GeminiException
     */
    public static function fromGemini(string $kind, array $data): array
    {
        $course = is_array($data['course'] ?? null) ? $data['course'] : [];
        $result = [
            'kind' => $kind,
            'notes_th' => self::text($data['notes_th'] ?? null, 1000) ?? '',
            'course' => [
                'code' => self::text($course['code'] ?? null, 20),
                'name' => self::text($course['name'] ?? null, 255),
                'subject_code' => self::text($course['subject_code'] ?? null, 20),
                'grade_level' => self::int($course['grade_level'] ?? null, 1, 12),
                'semester' => self::int($course['semester'] ?? null, 0, 2),
                'academic_year' => self::int($course['academic_year'] ?? null, 2500, 2700),
                'hours' => self::int($course['hours'] ?? null, 0, 2000),
                'description' => self::text($course['description'] ?? null, self::MAX_TEXT),
            ],
            'indicators' => [],
            'units' => [],
            'lesson_plans' => [],
        ];

        $seen = [];
        foreach ((array) ($data['indicators'] ?? []) as $item) {
            $code = is_array($item) ? self::text($item['code'] ?? null, 60) : null;
            if ($code === null || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $result['indicators'][] = ['code' => $code, 'text' => self::text($item['text'] ?? null, 2000)];
        }

        /** @var array<int, int> $unitPositions position as read => position in the result */
        $unitPositions = [];
        foreach ((array) ($data['units'] ?? []) as $item) {
            $title = is_array($item) ? self::text($item['title'] ?? null, 255) : null;
            if ($title === null) {
                continue;
            }
            $position = count($result['units']) + 1;
            $read = self::int($item['position'] ?? null, 1, 1000);
            if ($read !== null && ! isset($unitPositions[$read])) {
                $unitPositions[$read] = $position;
            }
            $result['units'][] = [
                'position' => $position,
                'title' => $title,
                'hours' => self::int($item['hours'] ?? null, 0, 2000),
                'description' => self::text($item['description'] ?? null, self::MAX_TEXT),
                'indicator_codes' => self::codes($item['indicator_codes'] ?? []),
            ];
        }

        foreach ((array) ($data['lesson_plans'] ?? []) as $item) {
            $title = is_array($item) ? self::text($item['title'] ?? null, 255) : null;
            if ($title === null) {
                continue;
            }
            $unit = self::int($item['unit_position'] ?? null, 1, 1000);
            $result['lesson_plans'][] = [
                'position' => count($result['lesson_plans']) + 1,
                'unit_position' => $unit === null ? null : ($unitPositions[$unit] ?? null),
                'title' => $title,
                'hours' => self::int($item['hours'] ?? null, 0, 2000),
                'objectives' => self::text($item['objectives'] ?? null, self::MAX_TEXT),
                'content' => self::text($item['content'] ?? null, self::MAX_TEXT),
                'activities' => self::text($item['activities'] ?? null, self::MAX_TEXT),
                'assessment' => self::text($item['assessment'] ?? null, self::MAX_TEXT),
                'indicator_codes' => self::codes($item['indicator_codes'] ?? []),
            ];
        }

        $hasCourse = $result['course']['name'] !== null || $result['course']['code'] !== null;
        if (! $hasCourse && $result['indicators'] === [] && $result['units'] === [] && $result['lesson_plans'] === []) {
            throw GeminiException::invalidOutput('nothing usable in the course document');
        }

        return $result;
    }

    /**
     * Every indicator code the result mentions, in order of appearance.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function allCodes(array $result): array
    {
        $codes = array_column((array) ($result['indicators'] ?? []), 'code');
        foreach ([...(array) ($result['units'] ?? []), ...(array) ($result['lesson_plans'] ?? [])] as $item) {
            $codes = [...$codes, ...(array) ($item['indicator_codes'] ?? [])];
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (int) $value;

        return $value < $min || $value > $max ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $codes): array
    {
        $out = [];
        foreach ((array) $codes as $code) {
            $code = self::text($code, 60);
            if ($code !== null && ! in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return array_slice($out, 0, 100);
    }
}
