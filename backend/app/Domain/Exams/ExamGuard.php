<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;

/**
 * Routes of homework that exams do not use (DESIGN §22.3 "route เดิมที่รับ
 * การบ้าน"): worksheets and their questions, the homework answer key and its
 * AI reads, whole-page hand-ins and Google Classroom. Called with an exam
 * they answer 422 exam_kind_unsupported before changing anything or calling
 * Google or Gemini.
 */
final class ExamGuard
{
    /** @throws ApiException 422 exam_kind_unsupported */
    public static function homeworkOnly(Assignment $assignment): void
    {
        if ($assignment->isExam()) {
            throw new ApiException('ข้อสอบไม่ใช้คำสั่งนี้ ใช้เมนูของข้อสอบแทน', 'exam_kind_unsupported', 422);
        }
    }
}
