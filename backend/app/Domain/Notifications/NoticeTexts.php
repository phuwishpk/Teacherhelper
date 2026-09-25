<?php

namespace App\Domain\Notifications;

/** Thai notification texts (DESIGN §9.9), shared by every Notifier. */
final class NoticeTexts
{
    /**
     * "ตรวจ {title} เสร็จแล้ว มี {n} ข้อรอตรวจทาน", or, when answers wait only
     * for a Gemini key, a prompt to add one first: the AI graded nothing of
     * those, so "เสร็จแล้ว" would mislead (§13 missing-key banner).
     */
    public static function gradingFinished(string $title, int $awaitingReview, int $awaitingAiKey): string
    {
        if ($awaitingAiKey <= 0) {
            return "ตรวจ {$title} เสร็จแล้ว มี {$awaitingReview} ข้อรอตรวจทาน";
        }
        $text = "ใส่ Gemini API key ก่อน: {$title} มี {$awaitingAiKey} ข้อที่ AI ยังไม่ได้ตรวจ";
        $others = $awaitingReview - $awaitingAiKey;

        return $others > 0 ? "{$text} และ {$others} ข้อรอตรวจทาน" : $text;
    }
}
