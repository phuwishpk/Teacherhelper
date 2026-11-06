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
     * Whether POST /scans takes this layout page of an exam: only a written
     * page of an `app` exam with sheets that name the student by QR (§22.20.5).
     *
     * @param  array<string, mixed>  $page  a `layouts.pages` entry
     */
    public static function takesScan(Assignment $exam, array $page): bool
    {
        return ($page['sheet'] ?? null) === 'written' && ! $exam->isManualExam() && ! $exam->usesCodeSheets();
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
