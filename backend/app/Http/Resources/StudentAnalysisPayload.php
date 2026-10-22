<?php

namespace App\Http\Resources;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Students\StudentClassrooms;
use App\Models\Skill;
use App\Models\StudentAnalysis;

/**
 * The JSON of a student analysis (DESIGN §20.7).
 *
 * teacher(): {id, student_id, classroom_id, status, strengths, areas,
 *   teacher_text, student_text, next_steps: [{skill}], generated_via,
 *   generated_at, stale, shared_student_text, shared_at, approved_by,
 *   awaiting_approval, updated_at}
 *   strengths/areas: [{skill, value, n_obs, too_little}] (n_obs < 2 =
 *   "ข้อมูลยังน้อย"), stale = the texts were written from older mastery.
 *
 * summary(): the same without the texts (the classroom list).
 *
 * student(): {classroom: {id, name, academic_year, closed}, text, shared_at, next_steps} — only
 *   the shared text, never the teacher text (§20.9); next steps only while
 *   the shared text is the current draft they were written with.
 */
final class StudentAnalysisPayload
{
    /**
     * @param  iterable<StudentAnalysis>  $rows
     * @return array<int, Skill> every skill the rows mention, by id
     */
    public static function skillsOf(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ([...(array) $row->strengths, ...(array) $row->areas] as $item) {
                $ids[] = (int) ($item['skill_id'] ?? 0);
            }
            foreach ((array) ($row->next_step_skill_ids ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Skill::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * @param  array<int, Skill>  $skills
     * @return array<string, mixed>
     */
    public static function summary(StudentAnalysis $row, array $skills): array
    {
        return [
            'id' => $row->id,
            'student_id' => $row->student_id,
            'classroom_id' => $row->classroom_id,
            'status' => $row->status,
            'strengths' => self::items((array) $row->strengths, $skills),
            'areas' => self::items((array) $row->areas, $skills),
            'has_text' => $row->student_text !== null || $row->teacher_text !== null,
            'generated_via' => $row->generated_via,
            'generated_at' => $row->generated_at?->toIso8601String(),
            'stale' => $row->isStale(),
            'shared' => $row->shared_student_text !== null,
            'shared_at' => $row->shared_at?->toIso8601String(),
            'awaiting_approval' => $row->awaitsApproval(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, Skill>  $skills
     * @return array<string, mixed>
     */
    public static function teacher(StudentAnalysis $row, array $skills): array
    {
        $summary = self::summary($row, $skills);
        unset($summary['has_text'], $summary['shared']);

        return $summary + [
            'teacher_text' => $row->teacher_text,
            'student_text' => $row->student_text,
            'next_steps' => self::nextSteps($row, $skills),
            'shared_student_text' => $row->shared_student_text,
            'approved_by' => $row->approved_by,
            // The teacher's guidance of the "วิเคราะห์ตอนนี้" that wrote the texts (§21.12); never in student().
            'guidance' => $row->guidance,
        ];
    }

    /**
     * @param  array<int, Skill>  $skills
     * @return array<string, mixed>
     */
    public static function student(StudentAnalysis $row, array $skills): array
    {
        return [
            'classroom' => StudentClassrooms::label($row->classroom),
            'text' => $row->shared_student_text,
            'shared_at' => $row->shared_at?->toIso8601String(),
            'next_steps' => $row->shared_student_text === $row->student_text ? self::nextSteps($row, $skills) : [],
        ];
    }

    /**
     * @param  array<int, Skill>  $skills
     * @return list<array{skill: array<string, mixed>}>
     */
    private static function nextSteps(StudentAnalysis $row, array $skills): array
    {
        $out = [];
        foreach ((array) ($row->next_step_skill_ids ?? []) as $id) {
            if (isset($skills[(int) $id])) {
                $out[] = ['skill' => SkillResource::indicator($skills[(int) $id])];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{skill_id?: int, value?: float, n_obs?: int}>  $items
     * @param  array<int, Skill>  $skills
     * @return list<array<string, mixed>>
     */
    private static function items(array $items, array $skills): array
    {
        $out = [];
        foreach ($items as $item) {
            $skill = $skills[(int) ($item['skill_id'] ?? 0)] ?? null;
            if ($skill === null) {
                continue;
            }
            $nObs = (int) ($item['n_obs'] ?? 0);
            $out[] = [
                'skill' => SkillResource::indicator($skill),
                'value' => (float) ($item['value'] ?? 0),
                'n_obs' => $nObs,
                'too_little' => $nObs < MasteryCalculator::MIN_OBS_FOR_LEVEL,
            ];
        }

        return $out;
    }
}
