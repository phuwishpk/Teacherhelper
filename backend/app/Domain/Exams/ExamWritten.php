<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSection;

/**
 * Rules about the written sections of an exam (DESIGN §22.20): where they
 * may be, which exams may have them, and what is not built yet.
 */
final class ExamWritten
{
    public const MANUAL_MESSAGE = 'ข้อสอบที่ครูตรวจเองไม่มีตอนเขียนตอบ';

    public const QR_ONLY_MESSAGE = 'ข้อเขียนใช้กับกระดาษคำตอบแบบ QR รายคนเท่านั้น';

    public const LAST_MESSAGE = 'ตอนเขียนตอบต้องอยู่หลังตอนที่ฝนทุกตอน';

    /** Whether the exam has a section the students write by hand. */
    public static function hasWritten(Assignment $exam): bool
    {
        return ExamSection::query()
            ->where('assignment_id', $exam->id)
            ->whereIn('type', ExamSection::WRITTEN_TYPES)
            ->exists();
    }

    /**
     * Written sections belong to `app` exams whose sheets name the student by QR.
     *
     * @throws ApiException 422 exam_manual_grading / exam_written_needs_qr_sheet
     */
    public static function assertAllowed(Assignment $exam): void
    {
        if ($exam->isManualExam()) {
            throw new ApiException(self::MANUAL_MESSAGE, 'exam_manual_grading', 422);
        }
        if ($exam->usesCodeSheets()) {
            throw new ApiException(self::QR_ONLY_MESSAGE, 'exam_written_needs_qr_sheet', 422);
        }
    }

    /**
     * Written sections come after every bubble section, so the numbers of the
     * bubble questions never move.
     *
     * @throws ApiException 422 exam_written_section_last
     */
    public static function assertLast(Assignment $exam): void
    {
        $seenWritten = false;
        $types = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->pluck('type');
        foreach ($types as $type) {
            if (ExamSection::isWrittenType((string) $type)) {
                $seenWritten = true;
            } elseif ($seenWritten) {
                throw new ApiException(self::LAST_MESSAGE, 'exam_written_section_last', 422);
            }
        }
    }
}
