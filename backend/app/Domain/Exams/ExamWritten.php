<?php

namespace App\Domain\Exams;

use App\Models\Assignment;
use App\Models\ExamSection;

/**
 * Rules about the written sections of an exam (DESIGN §22.20): where they
 * may be, which exams may have them, and what is not built yet.
 */
final class ExamWritten
{
    /** Whether the exam has a section the students write by hand. */
    public static function hasWritten(Assignment $exam): bool
    {
        return ExamSection::query()
            ->where('assignment_id', $exam->id)
            ->whereIn('type', ExamSection::WRITTEN_TYPES)
            ->exists();
    }
}
