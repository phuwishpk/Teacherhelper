<?php

namespace App\Domain\Analysis;

use App\Domain\Mastery\CourseMasterySummary;
use App\Domain\Mastery\MasteryCalculator;
use App\Models\Classroom;
use App\Models\Mastery;
use App\Models\PracticeItem;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

/**
 * Builds the AnalysisInput of the students of a classroom (DESIGN §20.5
 * scope): the indicators planned in the courses bound to the classroom
 * (I(course) of §20.3), or, for a classroom without a course, the skills of
 * the questions of its assignments. Mastery is per (student, skill), so the
 * same mastery shows in every classroom whose scope has the skill.
 */
final class AnalysisInputs
{
    public function __construct(private readonly CourseMasterySummary $summary) {}

    /**
     * @param  list<int>|null  $studentIds  null = every student of the classroom
     * @return array<int, AnalysisInput> by student id (students without any assessed indicator have an empty input)
     */
    public function forClassroom(Classroom $classroom, ?array $studentIds = null): array
    {
        $studentIds ??= array_map('intval', $classroom->students()->pluck('users.id')->all());
        if ($studentIds === []) {
            return [];
        }

        [$skillIds, $subject] = $this->scope($classroom);
        $skills = $skillIds === [] ? [] : Skill::query()->whereIn('id', $skillIds)->get()->keyBy('id')->all();

        $practice = $skills === [] ? [] : PracticeItem::query()
            ->approved()
            ->where('school_id', $classroom->school_id)
            ->whereIn('skill_id', array_keys($skills))
            ->groupBy('skill_id')
            ->selectRaw('skill_id, COUNT(*) AS n')
            ->pluck('n', 'skill_id')
            ->all();

        $byStudent = [];
        if ($skills !== []) {
            $rows = Mastery::query()
                ->whereIn('student_id', $studentIds)
                ->whereIn('skill_id', array_keys($skills))
                ->where('n_obs', '>=', 1)
                ->get(['student_id', 'skill_id', 'value', 'n_obs']);
            foreach ($rows as $row) {
                $byStudent[(int) $row->student_id][] = [
                    'skill' => $skills[(int) $row->skill_id],
                    'value' => MasteryCalculator::round3((float) $row->value),
                    'n_obs' => (int) $row->n_obs,
                    'practice_items' => (int) ($practice[(int) $row->skill_id] ?? 0),
                ];
            }
        }

        $out = [];
        foreach ($studentIds as $studentId) {
            $entries = $byStudent[$studentId] ?? [];
            usort($entries, fn (array $a, array $b) => $a['value'] <=> $b['value'] ?: $a['skill']->id <=> $b['skill']->id);
            $out[$studentId] = new AnalysisInput($studentId, $classroom->id, (int) $classroom->grade_level, $subject, $entries);
        }

        return $out;
    }

    public function forStudent(int $studentId, Classroom $classroom): AnalysisInput
    {
        return $this->forClassroom($classroom, [$studentId])[$studentId];
    }

    /**
     * The skill ids in scope and the subject name(s) for the prompt.
     *
     * @return array{0: list<int>, 1: string}
     */
    private function scope(Classroom $classroom): array
    {
        $courses = $classroom->courses()->with('subject')->orderBy('courses.id')->get();
        if ($courses->isNotEmpty()) {
            $ids = [];
            foreach ($courses as $course) {
                $ids = [...$ids, ...$this->summary->plan($course, CourseMasterySummary::AXIS_STANDARD)['course']];
            }
            $subjects = $courses->map(fn ($c) => trim((string) ($c->subject?->name ?? $c->name)))->filter()->unique()->values()->all();

            return [array_values(array_unique($ids)), $subjects === [] ? '-' : implode(', ', $subjects)];
        }

        $ids = DB::table('question_skill')
            ->join('questions', 'questions.id', '=', 'question_skill.question_id')
            ->join('assignments', 'assignments.id', '=', 'questions.assignment_id')
            ->where('assignments.classroom_id', $classroom->id)
            ->distinct()
            ->pluck('question_skill.skill_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $subjects = $ids === [] ? [] : DB::table('skills')
            ->join('subjects', 'subjects.id', '=', 'skills.subject_id')
            ->whereIn('skills.id', $ids)
            ->distinct()
            ->orderBy('subjects.name')
            ->pluck('subjects.name')
            ->all();

        return [$ids, $subjects === [] ? '-' : implode(', ', $subjects)];
    }
}
