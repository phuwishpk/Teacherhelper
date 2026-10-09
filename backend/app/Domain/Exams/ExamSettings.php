<?php

namespace App\Domain\Exams;

use App\Models\Assignment;
use Illuminate\Validation\ValidationException;

/**
 * The exam fields of PATCH /assignments/{id} (DESIGN §22.1, §22.5, §22.15).
 * Callers hold the assignment row lock and save the row.
 *
 * - kind is set at creation only.
 * - grading_method app -> manual: refused once answer sheets were scanned
 *   (409 exam_sheets_scanned); needs manual_full_marks; the exam is ready.
 *   manual -> app: ready ⇔ the key is approved and still complete.
 * - version_count is structural (409 exam_structure_locked once printed)
 *   and rebuilds the shuffled versions.
 * - sheet_identity and student_code_digits (§22.19) are structural too: they
 *   change the answer-sheet layout. `code` needs the number of digits;
 *   `qr` clears it.
 * - duration_minutes, show_key_to_students and manual_full_marks change any time.
 */
final class ExamSettings
{
    private const FIELDS = [
        'grading_method', 'version_count', 'duration_minutes', 'show_key_to_students', 'manual_full_marks',
        'sheet_identity', 'student_code_digits',
    ];

    /**
     * @param  array<string, mixed>  $data  the validated PATCH body
     */
    public static function apply(Assignment $assignment, array $data): void
    {
        if (array_key_exists('kind', $data) && $data['kind'] !== $assignment->kind) {
            throw ValidationException::withMessages(['kind' => 'ชนิดของงานตั้งได้ตอนสร้างเท่านั้น']);
        }
        if (! $assignment->isExam()) {
            $sent = array_values(array_intersect(self::FIELDS, array_keys($data)));
            if ($sent !== []) {
                throw ValidationException::withMessages(array_fill_keys($sent, 'ค่านี้ใช้กับข้อสอบเท่านั้น'));
            }

            return;
        }

        foreach (['duration_minutes', 'show_key_to_students'] as $field) {
            if (array_key_exists($field, $data)) {
                $assignment->{$field} = $field === 'show_key_to_students' ? (bool) $data[$field] : $data[$field];
            }
        }
        if (array_key_exists('manual_full_marks', $data)) {
            $assignment->manual_full_marks = $data['manual_full_marks'] === null ? null : round((float) $data['manual_full_marks'], 2);
        }

        if (array_key_exists('version_count', $data) && (int) $data['version_count'] !== $assignment->version_count) {
            ExamEditor::assertUnlocked($assignment);
            $assignment->version_count = (int) $data['version_count'];
            $assignment->save();
            ExamVersions::sync($assignment);
        }

        self::applySheetIdentity($assignment, $data);

        $method = $data['grading_method'] ?? $assignment->grading_method;
        if ($method !== $assignment->grading_method) {
            if ($method === Assignment::GRADING_MANUAL) {
                ExamEditor::assertNoScannedSheets($assignment);
            }
            $assignment->grading_method = $method;
        }
        if ($assignment->isManualExam() && $assignment->manual_full_marks === null) {
            throw ValidationException::withMessages(['manual_full_marks' => 'ข้อสอบที่ครูตรวจเองต้องกำหนดคะแนนเต็ม']);
        }
        if ($assignment->isClosed() || ! $assignment->isDirty('grading_method')) {
            return;
        }
        if ($assignment->isManualExam()) {
            $assignment->status = Assignment::STATUS_READY;

            return;
        }
        // Back to app: ready only while the approved key is still complete (§22.1).
        $complete = $assignment->key_approved_at !== null
            && ExamKeyCheck::questions($assignment)->isNotEmpty()
            && ExamKeyCheck::keyProblems($assignment) === [];
        $assignment->status = $complete ? Assignment::STATUS_READY : Assignment::STATUS_DRAFT;
        if (! $complete) {
            $assignment->key_approved_at = null;
            $assignment->key_approved_by = null;
        }
    }

    /**
     * How the answer sheet names its student (DESIGN §22.19).
     *
     * @param  array<string, mixed>  $data
     */
    private static function applySheetIdentity(Assignment $assignment, array $data): void
    {
        if (! array_key_exists('sheet_identity', $data) && ! array_key_exists('student_code_digits', $data)) {
            return;
        }
        $identity = $data['sheet_identity'] ?? $assignment->sheet_identity;
        $digits = array_key_exists('student_code_digits', $data) ? $data['student_code_digits'] : $assignment->student_code_digits;
        $digits = $digits === null ? null : (int) $digits;

        if ($identity === Assignment::IDENTITY_QR) {
            if (($data['student_code_digits'] ?? null) !== null) {
                throw ValidationException::withMessages(['student_code_digits' => 'จำนวนหลักใช้เมื่อเลือกฝนเลขประจำตัวเท่านั้น']);
            }
            $digits = null;
        } elseif ($digits === null) {
            throw ValidationException::withMessages(['student_code_digits' => 'กำหนดจำนวนหลักของเลขประจำตัวที่จะให้ฝน']);
        }

        if ($identity === $assignment->sheet_identity && $digits === $assignment->student_code_digits) {
            return;
        }
        ExamEditor::assertUnlocked($assignment);
        $assignment->sheet_identity = $identity;
        $assignment->student_code_digits = $digits;
    }
}
