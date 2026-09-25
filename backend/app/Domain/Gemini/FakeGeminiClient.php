<?php

namespace App\Domain\Gemini;

use App\Models\Question;

/**
 * Deterministic stand-in for Gemini: no network, no key, no cost. Bound by
 * default so development and tests run without an API key; the real client
 * replaces the binding when one is configured.
 */
class FakeGeminiClient implements GeminiClient
{
    public function draftRubric(RubricDraftRequest $request): RubricDraft
    {
        if ($request->type === Question::TYPE_SHOW_WORK) {
            return new RubricDraft(referenceSteps: [
                'เขียนสิ่งที่โจทย์กำหนดให้และสิ่งที่โจทย์ถาม',
                'เลือกวิธีคำนวณหรือตั้งสมการให้ตรงกับโจทย์',
                'คำนวณทีละขั้นอย่างถูกต้อง',
                'สรุปคำตอบสุดท้ายให้ชัดเจน',
            ]);
        }

        $descriptions = [
            'อธิบายแนวคิดหลักของคำตอบได้ถูกต้อง',
            'ให้เหตุผลหรือตัวอย่างสนับสนุนที่สอดคล้องกับโจทย์',
            'เรียบเรียงคำตอบได้ชัดเจน ใช้คำศัพท์ถูกต้อง',
        ];
        $points = self::split($request->maxPoints, $request->maxPoints >= 3 ? 3 : 2);

        $criteria = [];
        foreach ($points as $i => $p) {
            $criteria[] = ['description' => $descriptions[$i], 'points' => $p, 'is_core' => $i === 0];
        }

        return new RubricDraft(criteria: $criteria);
    }

    /**
     * Half (rounded to 0.5) for the core criterion, the rest shared equally;
     * the last share absorbs rounding so the sum is exactly $total.
     *
     * @return list<float>
     */
    private static function split(float $total, int $parts): array
    {
        $core = min($total, $total >= 1 ? round($total) / 2 : round($total / 2, 2));
        $restCount = $parts - 1;
        $share = floor(($total - $core) / $restCount * 100) / 100;

        $points = [$core];
        for ($i = 1; $i < $restCount; $i++) {
            $points[] = $share;
        }
        $points[] = round($total - $core - $share * ($restCount - 1), 2);

        return $points;
    }
}
