<?php

namespace App\Domain\Teachers;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * DESIGN §29.13.1: every teacher owns one `schools` row, their workspace.
 * It is created with the account (registration or the first Google
 * sign-in), never chosen and never shown. Its name follows the school name
 * the teacher types in the profile, else the teacher's name, so the rows
 * stay readable in the panel's "พื้นที่ของครู" list.
 */
final class Workspaces
{
    /** A fresh workspace for a teacher about to be created. */
    public static function create(string $teacherName, ?string $schoolName): School
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return School::create([
                    'name' => self::name($teacherName, $schoolName),
                    // NOT NULL UNIQUE from the days teachers typed it; nobody reads it now.
                    'teacher_join_code' => School::randomJoinCode(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }

    /** Keeps the workspace name in step with the teacher's profile (PATCH /me). */
    public static function rename(User $teacher): void
    {
        $school = $teacher->school;
        if ($teacher->isTeacher() && $school !== null) {
            $school->forceFill(['name' => self::name($teacher->name, $teacher->school_name)])->save();
        }
    }

    public static function name(string $teacherName, ?string $schoolName): string
    {
        $schoolName = trim((string) $schoolName);

        return mb_substr($schoolName !== '' ? $schoolName : $teacherName, 0, 255);
    }
}
