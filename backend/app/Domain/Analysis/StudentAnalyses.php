<?php

namespace App\Domain\Analysis;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\StudentAnalysis;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The per-student analysis of a classroom (DESIGN §20.5):
 *
 * - record(): code writes the strengths, the areas and computed_input_hash
 *   (on publish, on every nightly round, and when the teacher opens it);
 * - runNow(): "วิเคราะห์ตอนนี้", one synchronous student_analysis call with
 *   the classroom teacher's key (GeminiKeyResolver);
 * - applyText(): the texts of a successful call (now or from a batch),
 *   with generated_input_hash = the hash of the input they were written
 *   from, and the classroom's auto-share;
 * - edit() / approve(): the teacher's changes (which count as texts for the
 *   current input), and the copy of the student draft to what the student
 *   sees.
 */
final class StudentAnalyses
{
    public const FEATURE_NIGHTLY = 'analysis_nightly';

    public const FEATURE_NOW = 'analysis_now';

    public function __construct(
        private readonly AnalysisInputs $inputs,
        private readonly StudentAnalysisRequests $requests,
        private readonly GeminiGateway $gateway,
        private readonly GeminiKeyResolver $keys,
    ) {}

    /**
     * Writes the code-computed part of the analysis. A student without any
     * assessed indicator gets no row (an existing row is updated to empty).
     */
    public function record(AnalysisInput $input): ?StudentAnalysis
    {
        $row = $this->find($input);
        if ($row === null && $input->isEmpty()) {
            return null;
        }
        $row ??= new StudentAnalysis(['student_id' => $input->studentId, 'classroom_id' => $input->classroomId, 'status' => StudentAnalysis::STATUS_COMPUTED]);
        $row->computed_input_hash = $input->hash();
        $row->strengths = $input->strengths();
        $row->areas = $input->areas();
        try {
            $row->save();
        } catch (UniqueConstraintViolationException $e) {
            // Another request (the publish listener, the nightly round, a
            // second GET) created the row in between: update that one.
            $row = $this->find($input) ?? throw $e;
            $row->computed_input_hash = $input->hash();
            $row->strengths = $input->strengths();
            $row->areas = $input->areas();
            $row->save();
        }

        return $row;
    }

    private function find(AnalysisInput $input): ?StudentAnalysis
    {
        return StudentAnalysis::query()
            ->where('student_id', $input->studentId)
            ->where('classroom_id', $input->classroomId)
            ->first();
    }

    /** After a publish (or a changed published score): every classroom of the student. */
    public function refreshStudent(int $studentId): void
    {
        $classrooms = Classroom::query()
            ->whereHas('students', fn ($q) => $q->where('users.id', $studentId))
            ->get();
        foreach ($classrooms as $classroom) {
            $this->record($this->inputs->forStudent($studentId, $classroom));
        }
    }

    /** Recomputes and returns the row the teacher opens (null: nothing assessed yet). */
    public function current(User $student, Classroom $classroom): ?StudentAnalysis
    {
        return $this->record($this->inputs->forStudent($student->id, $classroom));
    }

    /**
     * "วิเคราะห์ตอนนี้" (§20.5): synchronous, the request's own timeout.
     * $guidance: the teacher's guidance to the AI (§21.12), kept on the row
     * with the texts it steered.
     *
     * @throws ApiException 422 analysis_no_data | ai_key_missing | ai_key_invalid, 502 ai_unavailable
     */
    public function runNow(User $student, Classroom $classroom, ?string $guidance = null, ?int $guidanceBy = null): StudentAnalysis
    {
        $input = $this->inputs->forStudent($student->id, $classroom);
        $row = $this->record($input);
        if ($row === null || $input->isEmpty()) {
            throw new ApiException('นักเรียนคนนี้ยังไม่มีผลที่ประเมินตัวชี้วัดของห้องนี้ เผยแพร่ผลการบ้านก่อนแล้วลองอีกครั้ง', 'analysis_no_data', 422);
        }
        $key = $this->keys->forTeacher($classroom->teacher_id);
        if ($key === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $outcome = $this->gateway->run(['analysis' => $this->requests->call($input, self::FEATURE_NOW, $guidance, $guidanceBy)], $key)['analysis'];
        if ($outcome->status === CallOutcome::KEY_INVALID) {
            throw new ApiException('Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าแล้วลองอีกครั้ง', 'ai_key_invalid', 422);
        }
        if (! $outcome->isOk()) {
            throw new ApiException('AI วิเคราะห์ไม่สำเร็จ ลองใหม่อีกครั้ง', 'ai_unavailable', 502);
        }

        return $this->applyText($row->id, (array) $outcome->data, StudentAnalysis::VIA_NOW, $input->hash(), null, $guidance) ?? $row->refresh();
    }

    /**
     * Writes Gemini's texts. A row that has meanwhile been queued in another
     * batch, or already collected, is left alone when batchId is given and
     * the row no longer belongs to it. $guidance: what steered these texts
     * (null for a nightly batch, which has none).
     *
     * @param  array{teacher_text?: string, student_text?: string, next_step_skill_ids?: list<int>}  $text
     */
    public function applyText(int $analysisId, array $text, string $via, string $inputHash, ?int $batchId, ?string $guidance = null): ?StudentAnalysis
    {
        return DB::transaction(function () use ($analysisId, $text, $via, $inputHash, $batchId, $guidance) {
            $row = StudentAnalysis::query()->lockForUpdate()->find($analysisId);
            if ($row === null) {
                return null;
            }
            if ($batchId !== null && ($row->batch_id !== $batchId || $row->status !== StudentAnalysis::STATUS_QUEUED)) {
                return null;
            }
            $row->teacher_text = (string) ($text['teacher_text'] ?? '');
            $row->student_text = (string) ($text['student_text'] ?? '');
            $row->next_step_skill_ids = array_values(array_map('intval', (array) ($text['next_step_skill_ids'] ?? [])));
            $row->generated_input_hash = $inputHash;
            $row->queued_input_hash = null;
            $row->status = StudentAnalysis::STATUS_DRAFTED;
            $row->generated_via = $via;
            $row->batch_id = $batchId;
            $row->generated_at = now();
            $row->guidance = $guidance;
            if (Classroom::query()->whereKey($row->classroom_id)->value('auto_share_analysis')) {
                $row->shared_student_text = $row->student_text;
                $row->shared_at = now();
                $row->approved_by = null; // shared by the classroom setting, not by a person
            }
            $row->save();

            return $row;
        });
    }

    /** A row of a batch that did not produce texts: tried again next night. */
    public function markFailed(int $analysisId, int $batchId): void
    {
        StudentAnalysis::query()
            ->whereKey($analysisId)
            ->where('batch_id', $batchId)
            ->where('status', StudentAnalysis::STATUS_QUEUED)
            ->update(['status' => StudentAnalysis::STATUS_FAILED, 'queued_input_hash' => null, 'updated_at' => now()]);
    }

    /**
     * PATCH /analyses/{id}: the teacher's own wording. Students still see
     * the shared text until the teacher approves.
     *
     * The teacher's text counts as written for the current input
     * (generated_input_hash = computed_input_hash, DESIGN §20.5): the
     * nightly round does not overwrite it until the mastery changes. A row
     * queued in a pending batch leaves it, like "วิเคราะห์ตอนนี้", so the
     * batch reply is only logged, never written over the edit.
     *
     * @param  array{teacher_text?: string, student_text?: string}  $fields
     */
    public function edit(StudentAnalysis $analysis, array $fields): StudentAnalysis
    {
        return DB::transaction(function () use ($analysis, $fields) {
            $row = StudentAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
            foreach (['teacher_text', 'student_text'] as $field) {
                if (array_key_exists($field, $fields)) {
                    $row->{$field} = trim((string) $fields[$field]);
                }
            }
            $row->generated_input_hash = $row->computed_input_hash;
            if ($row->status === StudentAnalysis::STATUS_QUEUED) {
                $row->batch_id = null;
                $row->queued_input_hash = null;
            }
            $row->status = StudentAnalysis::STATUS_DRAFTED;
            $row->save();

            return $row;
        });
    }

    /**
     * POST /analyses/{id}/approve: student_text becomes what the student sees.
     *
     * @throws ApiException 409 analysis_not_ready
     */
    public function approve(StudentAnalysis $analysis, User $teacher): StudentAnalysis
    {
        if ($analysis->student_text === null || trim($analysis->student_text) === '') {
            throw new ApiException('ยังไม่มีข้อความสำหรับนักเรียน กด "วิเคราะห์ตอนนี้" หรือเขียนข้อความก่อน', 'analysis_not_ready', 409);
        }
        $analysis->shared_student_text = $analysis->student_text;
        $analysis->shared_at = now();
        $analysis->approved_by = $teacher->id;
        $analysis->save();

        return $analysis;
    }
}
