<?php

namespace Tests\Unit\Notifications;

use App\Domain\Notifications\NoticeTexts;
use PHPUnit\Framework\TestCase;

/** DESIGN §9.9 "grading done" text, and the missing-key variant (§13). */
class NoticeTextsTest extends TestCase
{
    public function test_grading_finished_texts(): void
    {
        $this->assertSame('ตรวจ การบ้านบทที่ 3 เสร็จแล้ว มี 12 ข้อรอตรวจทาน', NoticeTexts::gradingFinished('การบ้านบทที่ 3', 12, 0));
        $this->assertSame('ใส่ Gemini API key ก่อน: การบ้านบทที่ 3 มี 8 ข้อที่ AI ยังไม่ได้ตรวจ', NoticeTexts::gradingFinished('การบ้านบทที่ 3', 8, 8));
        $this->assertSame(
            'ใส่ Gemini API key ก่อน: การบ้านบทที่ 3 มี 3 ข้อที่ AI ยังไม่ได้ตรวจ และ 9 ข้อรอตรวจทาน',
            NoticeTexts::gradingFinished('การบ้านบทที่ 3', 12, 3),
        );
    }
}
