<?php

namespace App\Domain\Grading;

/**
 * Feedback that needs no Gemini call (DESIGN §7.2 step 5): praise for full
 * marks, a nudge for a blank answer, and the text of an assignment set to
 * "เฉพาะคะแนน" (score_only, §21.7) below full marks. The teacher can edit
 * any of them before publishing.
 */
final class FeedbackTemplates
{
    private const PRAISE = [
        'ทำได้ถูกต้องครบถ้วน เยี่ยมมาก รักษาความละเอียดแบบนี้ไว้นะ',
        'ถูกต้องทั้งหมด แสดงว่าเข้าใจเรื่องนี้ดีแล้ว ลองท้าทายตัวเองด้วยโจทย์ที่ยากขึ้นดูนะ',
        'ยอดเยี่ยม ตอบได้ถูกต้องและชัดเจน',
    ];

    public const SCORE_ONLY = 'ข้อนี้ยังได้คะแนนไม่เต็ม ลองทบทวนโจทย์และคำตอบของตัวเองอีกครั้ง ถ้าสงสัยตรงไหนถามครูได้เลย';

    public const BLANK = 'ข้อนี้ยังไม่ได้เขียนคำตอบ ลองอ่านโจทย์อีกครั้งแล้วเขียนวิธีคิดของตัวเองลงไป แม้ไม่แน่ใจก็ลองเขียนดูนะ';

    /** Deterministic per response, so a regrade does not reshuffle the text. */
    public static function praise(int $responseId): string
    {
        return self::PRAISE[$responseId % count(self::PRAISE)];
    }
}
