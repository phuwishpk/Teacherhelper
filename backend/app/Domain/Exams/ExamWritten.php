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

    public const NOT_READY_MESSAGE = 'ยังพิมพ์กระดาษคำตอบหรือเตรียมสแกนข้อสอบที่มีข้อเขียนไม่ได้ (กำลังพัฒนา)';

    /** Whether the exam has a section the students write by hand. */
    public static function hasWritten(Assignment $exam): bool
    {
        return ExamSection::query()
            ->where('assignment_id', $exam->id)
            ->whereIn('type', ExamSection::WRITTEN_TYPES)
            ->exists();
    }

    /**
     * The answer sheet has written pages since step 2 of DESIGN §22.20.9, but
     * the app cannot read them before step 3, so an exam that has written
     * questions still gives out no scan-kit. Step 3 deletes this check and
     * its caller.
     *
     * @throws ApiException 422 exam_written_not_ready
     */
    public static function assertSheetsReady(Assignment $exam): void
    {
        if (self::hasWritten($exam)) {
            throw new ApiException(self::NOT_READY_MESSAGE, 'exam_written_not_ready', 422);
        }
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
