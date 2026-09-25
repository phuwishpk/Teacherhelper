<?php

namespace App\Domain\Scans;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Question;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Checks the regions of an uploaded page against the layout the QR names
 * (DESIGN §5.3, §9.4) and pairs each with its question.
 *
 * - The page must exist in that layout version, and the upload must carry
 *   exactly the page's regions with the right question ids; otherwise
 *   422 page_mismatch (the phone cropped with another page's layout).
 * - mcq regions need mcq_fill with the printed options only; a lines region
 *   with a final-answer box needs final_file (422 validation_failed).
 * - A question deleted after the sheet was printed is skipped: the other
 *   answers on the page are still graded.
 */
final class LayoutPageMatcher
{
    /**
     * The page object of layouts.pages for page number $page, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function page(Layout $layout, int $page): ?array
    {
        foreach ($layout->pages as $index => $candidate) {
            if ((int) ($candidate['page'] ?? $index + 1) === $page) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<ScanRegion>  $regions
     * @return list<MatchedRegion>
     */
    public static function match(array $page, array $regions, Assignment $assignment, bool $strict = true): array
    {
        $layoutRegions = [];
        foreach ($page['regions'] ?? [] as $layoutRegion) {
            $layoutRegions[(string) $layoutRegion['region_id']] = $layoutRegion;
        }

        if ($strict) {
            self::assertSameRegions($page, $layoutRegions, $regions);
            self::assertRegionShapes($layoutRegions, $regions);
        }

        $questions = Question::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('id', array_map(fn (ScanRegion $r) => $r->questionId, $regions))
            ->get()
            ->keyBy('id');

        $matched = [];
        foreach ($regions as $region) {
            $layoutRegion = $layoutRegions[$region->regionId] ?? null;
            $question = $questions->get($region->questionId);
            if ($layoutRegion === null || $question === null) {
                Log::info('scans.region_skipped', [
                    'assignment_id' => $assignment->id,
                    'region_id' => $region->regionId,
                    'reason' => $question === null ? 'question_deleted' : 'not_in_layout',
                ]);

                continue;
            }
            $matched[] = new MatchedRegion($region, $layoutRegion, $question);
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, array<string, mixed>>  $layoutRegions
     * @param  list<ScanRegion>  $regions
     */
    private static function assertSameRegions(array $page, array $layoutRegions, array $regions): void
    {
        $errors = [];
        $seen = [];
        foreach ($regions as $i => $region) {
            $seen[$region->regionId] = true;
            $layoutRegion = $layoutRegions[$region->regionId] ?? null;
            if ($layoutRegion === null) {
                $errors["meta.regions.{$i}.region_id"][] = "ช่อง {$region->regionId} ไม่มีในหน้านี้ของใบงาน";
            } elseif ((int) $layoutRegion['question_id'] !== $region->questionId) {
                $errors["meta.regions.{$i}.question_id"][] = "ช่อง {$region->regionId} เป็นของข้อ {$layoutRegion['question_id']}";
            }
        }

        $missing = array_values(array_diff(array_keys($layoutRegions), array_keys($seen)));
        if ($missing !== []) {
            $errors['meta.regions'][] = 'ขาดช่องคำตอบ '.implode(', ', $missing);
        }

        if ($errors !== []) {
            $pageNo = (int) ($page['page'] ?? 0);

            throw new ApiException(
                "ช่องคำตอบที่ส่งมาไม่ตรงกับหน้า {$pageNo} ของใบงาน",
                'page_mismatch',
                422,
                $errors,
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $layoutRegions
     * @param  list<ScanRegion>  $regions
     */
    private static function assertRegionShapes(array $layoutRegions, array $regions): void
    {
        $errors = [];
        foreach ($regions as $i => $region) {
            $layoutRegion = $layoutRegions[$region->regionId];
            if (($layoutRegion['kind'] ?? null) === 'mcq') {
                $options = array_map(fn (array $b) => (string) $b['option'], $layoutRegion['bubbles'] ?? []);
                if ($region->mcqFill === null || $region->mcqFill === []) {
                    $errors["meta.regions.{$i}.mcq_fill"][] = "ช่อง {$region->regionId} ต้องมีค่าการฝน (mcq_fill)";
                } elseif ($unknown = array_diff(array_map('strval', array_keys($region->mcqFill)), $options)) {
                    $errors["meta.regions.{$i}.mcq_fill"][] = 'ตัวเลือก '.implode(', ', $unknown).' ไม่มีในใบงาน';
                }
            }
            if (isset($layoutRegion['final_answer']) && $region->finalFile === null) {
                $errors["meta.regions.{$i}.final_file"][] = "ช่อง {$region->regionId} ต้องมีภาพกรอบคำตอบสุดท้าย (final_file)";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
