<?php

namespace App\Domain\Skills;

use App\Exceptions\ApiException;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Indicators a teacher adds for their school (DESIGN §20.2, §20.7 POST and
 * PATCH /skills): under a standard (level indicator) or under an indicator
 * (level sub_indicator), with the parent's subject, `source = teacher`,
 * shared with every teacher of the school and labelled "ครูเพิ่มเอง".
 *
 * Without a code the server numbers it `<parent code>/ค<n>`, n = the next
 * number among the teacher-added rows under that parent in the school. A
 * code already used by the curriculum or the school is 422
 * skill_code_taken. The creator may edit it while no answer was observed on
 * it (409 skill_in_use afterwards).
 */
final class TeacherSkills
{
    /**
     * @param  array{parent_id: int, name: string, code?: string|null, grade_level?: int|null, subject_id?: int|null}  $input
     *
     * @throws ApiException
     */
    public function create(User $teacher, array $input): Skill
    {
        $schoolId = (int) $teacher->school_id;

        return DB::transaction(function () use ($teacher, $input, $schoolId) {
            // Locking the parent serialises the numbering of its children.
            $parent = Skill::query()->visibleToSchool($schoolId)->lockForUpdate()->find((int) $input['parent_id']);
            if ($parent === null) {
                throw new ApiException('ไม่พบมาตรฐานหรือตัวชี้วัดที่เลือก', 'validation_failed', 422, ['parent_id' => ['ไม่พบมาตรฐานหรือตัวชี้วัดที่เลือก']]);
            }
            $level = match ($parent->level) {
                Skill::LEVEL_STANDARD => Skill::LEVEL_INDICATOR,
                Skill::LEVEL_INDICATOR => Skill::LEVEL_SUB_INDICATOR,
                default => null,
            };
            if ($level === null) {
                $message = 'เพิ่มได้ใต้มาตรฐาน (เป็นตัวชี้วัด) หรือใต้ตัวชี้วัด (เป็นทักษะย่อย) เท่านั้น';

                throw new ApiException($message, 'validation_failed', 422, ['parent_id' => [$message]]);
            }
            $subjectId = $input['subject_id'] ?? null;
            if ($subjectId !== null && (int) $subjectId !== $parent->subject_id) {
                $message = 'วิชาต้องตรงกับวิชาของมาตรฐานหรือตัวชี้วัดที่เลือก';

                throw new ApiException($message, 'validation_failed', 422, ['subject_id' => [$message]]);
            }

            $code = trim((string) ($input['code'] ?? ''));
            $code = $code === '' ? $this->nextCode($parent, $schoolId) : $code;
            $this->assertCodeFree($code, $schoolId);

            return Skill::create([
                'subject_id' => $parent->subject_id,
                'parent_id' => $parent->id,
                'school_id' => $schoolId,
                'code' => $code,
                'name' => trim($input['name']),
                'grade_level' => array_key_exists('grade_level', $input) && $input['grade_level'] !== null
                    ? (int) $input['grade_level']
                    : $parent->grade_level,
                'level' => $level,
                'source' => Skill::SOURCE_TEACHER,
                'created_by' => $teacher->id,
            ]);
        });
    }

    /**
     * @param  array{name?: string, code?: string, grade_level?: int|null}  $input
     *
     * @throws ApiException
     */
    public function update(Skill $skill, array $input): Skill
    {
        return DB::transaction(function () use ($skill, $input) {
            $skill = Skill::query()->lockForUpdate()->findOrFail($skill->id);
            if (SkillObservation::query()->where('skill_id', $skill->id)->exists()) {
                throw new ApiException('ตัวชี้วัดนี้มีผลการประเมินของนักเรียนแล้ว แก้ไขไม่ได้', 'skill_in_use', 409);
            }
            if (array_key_exists('code', $input)) {
                $code = trim((string) $input['code']);
                if ($code !== $skill->code) {
                    $this->assertCodeFree($code, (int) $skill->school_id, $skill->id);
                    $skill->code = $code;
                }
            }
            if (array_key_exists('name', $input)) {
                $skill->name = trim((string) $input['name']);
            }
            if (array_key_exists('grade_level', $input)) {
                $skill->grade_level = $input['grade_level'] === null ? null : (int) $input['grade_level'];
            }
            $skill->save();

            return $skill;
        });
    }

    /** `<parent code>/ค<n>`: n follows the teacher-added rows under the parent in the school. */
    private function nextCode(Skill $parent, int $schoolId): string
    {
        $n = Skill::query()
            ->where('parent_id', $parent->id)
            ->where('school_id', $schoolId)
            ->where('source', Skill::SOURCE_TEACHER)
            ->count() + 1;
        for ($tries = 0; $tries < 1000; $tries++, $n++) {
            $code = "{$parent->code}/ค{$n}";
            if (mb_strlen($code) > SkillCsvImporter::MAX_CODE_LENGTH) {
                break;
            }
            if (! $this->taken($code, $schoolId)) {
                return $code;
            }
        }
        $message = 'สร้างรหัสให้อัตโนมัติไม่ได้ กรอกรหัสเอง (ไม่เกิน '.SkillCsvImporter::MAX_CODE_LENGTH.' ตัวอักษร)';

        throw new ApiException($message, 'validation_failed', 422, ['code' => [$message]]);
    }

    /**
     * @throws ApiException 422 skill_code_taken
     */
    private function assertCodeFree(string $code, int $schoolId, ?int $exceptId = null): void
    {
        if ($this->taken($code, $schoolId, $exceptId)) {
            $message = "รหัส \"{$code}\" มีอยู่แล้วในหลักสูตรหรือในโรงเรียน";

            throw new ApiException($message, 'skill_code_taken', 422, ['code' => [$message]]);
        }
    }

    /** The code is used by the curriculum or by the school (compared exactly, not by collation). */
    private function taken(string $code, int $schoolId, ?int $exceptId = null): bool
    {
        return Skill::query()
            ->visibleToSchool($schoolId)
            ->where('code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('code')
            ->contains(fn ($found) => (string) $found === $code);
    }
}
