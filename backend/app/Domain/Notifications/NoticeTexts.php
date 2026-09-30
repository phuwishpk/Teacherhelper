<?php

namespace App\Domain\Notifications;

/**
 * Thai notification texts (DESIGN §9.9), shared by every Notifier. None of
 * them shows a score: pushes appear on the lock screen.
 */
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

    /** To the student when the teacher publishes: never the score (§9.9). */
    public static function resultsPublished(string $title): string
    {
        return "ผลการบ้าน {$title} ออกแล้ว";
    }

    /** To the teacher: how many appeals of their classrooms are open now. */
    public static function appealsWaiting(int $open): string
    {
        return "มีคำขอให้ตรวจใหม่ {$open} รายการ";
    }

    /** To the student once the teacher accepted or rejected an appeal. */
    public static function appealResolved(): string
    {
        return 'ครูตอบคำขอตรวจใหม่แล้ว';
    }

    /**
     * To the student when the teacher sends Google Classroom work back for a
     * new photo (§18.2). The reason is the teacher's own words about the
     * picture, never a score.
     */
    public static function retakeRequested(string $title, string $reason): string
    {
        $text = "ครูขอให้ส่งรูปการบ้าน {$title} ใหม่ใน Google Classroom";
        $reason = trim($reason);

        return $reason !== '' ? "{$text}: {$reason}" : $text;
    }

    /** To the teacher when the sync mirrored courseWork created on the Classroom website (§19.3). */
    public static function classroomWorkImported(string $title): string
    {
        $title = trim($title);

        return $title !== '' ? "มีงานใหม่จาก Classroom รออนุมัติเฉลย: {$title}" : 'มีงานใหม่จาก Classroom รออนุมัติเฉลย';
    }

    /** To the students when the teacher publishes a classroom's grades (§23.7): never the grade. */
    public static function gradesPublished(string $courseCode): string
    {
        $courseCode = trim($courseCode);

        return $courseCode !== '' ? "ประกาศเกรด {$courseCode} แล้ว" : 'ประกาศเกรดแล้ว';
    }

    /** To the teacher once per drop of the Google grant (§19.3). */
    public static function googleReconnectNeeded(): string
    {
        return 'ต้องเชื่อมบัญชี Google ใหม่ แอปจึงจะซิงก์งานกับ Google Classroom ต่อได้';
    }
}
