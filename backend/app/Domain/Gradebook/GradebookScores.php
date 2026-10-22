<?php

namespace App\Domain\Gradebook;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Typed scores and "ยกเว้น" of the classroom grid (DESIGN §23.3):
 *
 * - an item or a manual exam takes scores 0 … full marks with at most 2
 *   decimals; `score: null` clears one;
 * - any other assignment takes `excused` only (its score comes from the
 *   published submission): a `score` is 422 score_from_app;
 * - "ให้เต็มทั้งห้อง" fills full marks for every student of the roster
 *   whose cell is still empty and not excused.
 *
 * A row keeps only what is set: an entry with no score and not excused is
 * deleted, so "a score was typed in the classroom" stays exact.
 */
final class GradebookScores
{
    public const MAX_ROWS = 100;

    /**
     * @param  mixed  $scores  the request's `scores`
     * @return list<array{student_id: int, score: float|null, excused: bool}> the entries of the column after the change
     */
    public static function saveForItem(GradebookItem $item, User $teacher, mixed $scores): array
    {
        $classroom = Classroom::query()->findOrFail($item->classroom_id);
        $rows = self::validate($scores, $classroom, $item->max_points, true);

        return self::write($classroom, $teacher, ['gradebook_item_id' => $item->id], $rows);
    }

    /**
     * @param  mixed  $scores  the request's `scores`
     * @return list<array{student_id: int, score: float|null, excused: bool}>
     */
    public static function saveForAssignment(Assignment $assignment, User $teacher, mixed $scores): array
    {
        $classroom = Classroom::query()->findOrFail($assignment->classroom_id);
        $manual = $assignment->isManualExam();
        $rows = self::validate($scores, $classroom, $manual ? (float) $assignment->manual_full_marks : 0.0, $manual);

        return self::write($classroom, $teacher, ['assignment_id' => $assignment->id], $rows);
    }

    public static function fillItem(GradebookItem $item, User $teacher): int
    {
        $classroom = Classroom::query()->findOrFail($item->classroom_id);

        return self::fill($classroom, $teacher, ['gradebook_item_id' => $item->id], $item->max_points);
    }

    /** @throws ApiException 422 score_from_app for anything but a manual exam */
    public static function fillAssignment(Assignment $assignment, User $teacher): int
    {
        if (! $assignment->isManualExam()) {
            throw self::scoreFromApp();
        }
        $classroom = Classroom::query()->findOrFail($assignment->classroom_id);

        return self::fill($classroom, $teacher, ['assignment_id' => $assignment->id], (float) $assignment->manual_full_marks);
    }

    /**
     * @param  array{assignment_id?: int, gradebook_item_id?: int}  $target
     */
    private static function fill(Classroom $classroom, User $teacher, array $target, float $full): int
    {
        return DB::transaction(function () use ($classroom, $teacher, $target, $full) {
            $studentIds = $classroom->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $entries = GradebookEntry::query()->where($target)->whereIn('student_id', $studentIds)->lockForUpdate()->get()->keyBy('student_id');
            $filled = 0;
            foreach ($studentIds as $studentId) {
                $entry = $entries->get($studentId);
                if ($entry !== null && ($entry->excused || $entry->score !== null)) {
                    continue;
                }
                $entry ??= new GradebookEntry($target + ['classroom_id' => $classroom->id, 'student_id' => $studentId]);
                $entry->fill(['score' => round($full, 2), 'updated_by' => $teacher->id])->save();
                $filled++;
            }

            return $filled;
        });
    }

    /**
     * @return list<array{index: int, student_id: int, score?: float|null, excused?: bool}>
     */
    private static function validate(mixed $scores, Classroom $classroom, float $full, bool $scoreAllowed): array
    {
        $rows = Validator::make(['scores' => $scores], [
            'scores' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ROWS],
            'scores.*' => ['required', 'array:student_id,score,excused'],
            'scores.*.student_id' => ['required', 'integer', 'min:1', 'distinct'],
            'scores.*.score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'scores.*.excused' => ['sometimes', 'boolean'],
        ], [
            'scores.required' => 'ไม่มีคะแนนที่จะบันทึก',
            'scores.max' => 'บันทึกได้ครั้งละไม่เกิน '.self::MAX_ROWS.' คน',
            'scores.*.student_id.distinct' => 'มีนักเรียนซ้ำในรายการ',
            'scores.*.score.numeric' => 'คะแนนต้องเป็นตัวเลข',
            'scores.*.score.min' => 'คะแนนต้องไม่ติดลบ',
            'scores.*.score.decimal' => 'คะแนนมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง',
        ])->validate()['scores'];

        $members = array_flip($classroom->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
        $errors = [];
        $sentScore = false;
        foreach ($rows as $i => $row) {
            if (! isset($members[(int) $row['student_id']])) {
                $errors["scores.{$i}.student_id"] = ['นักเรียนคนนี้ไม่ได้อยู่ในห้องนี้'];
            }
            if (array_key_exists('score', $row)) {
                $sentScore = true;
                if ($scoreAllowed && $row['score'] !== null && (float) $row['score'] > $full + 1e-9) {
                    $errors["scores.{$i}.score"] = ['คะแนนเกินคะแนนเต็ม ('.GradebookSettings::formatWeight($full).')'];
                }
            }
        }
        if ($sentScore && ! $scoreAllowed) {
            throw self::scoreFromApp();
        }
        if ($errors !== []) {
            throw new ApiException('ข้อมูลคะแนนไม่ถูกต้อง', 'validation_failed', 422, $errors);
        }

        return array_values($rows);
    }

    /**
     * @param  array{assignment_id?: int, gradebook_item_id?: int}  $target
     * @param  list<array{student_id: int, score?: float|null, excused?: bool}>  $rows
     * @return list<array{student_id: int, score: float|null, excused: bool}>
     */
    private static function write(Classroom $classroom, User $teacher, array $target, array $rows): array
    {
        return DB::transaction(function () use ($classroom, $teacher, $target, $rows) {
            $entries = GradebookEntry::query()->where($target)->lockForUpdate()->get()->keyBy('student_id');
            foreach ($rows as $row) {
                $studentId = (int) $row['student_id'];
                $entry = $entries->get($studentId) ?? new GradebookEntry($target + ['classroom_id' => $classroom->id, 'student_id' => $studentId, 'excused' => false]);
                if (array_key_exists('score', $row)) {
                    $entry->score = $row['score'] === null ? null : round((float) $row['score'], 2);
                }
                if (array_key_exists('excused', $row)) {
                    $entry->excused = (bool) $row['excused'];
                }
                if ($entry->score === null && ! $entry->excused) {
                    if ($entry->exists) {
                        $entry->delete();
                    }
                    $entries->forget($studentId);

                    continue;
                }
                $entry->updated_by = $teacher->id;
                $entry->save();
                $entries->put($studentId, $entry);
            }

            return $entries->sortKeys()->map(fn (GradebookEntry $e) => [
                'student_id' => $e->student_id,
                'score' => $e->score,
                'excused' => $e->excused,
            ])->values()->all();
        });
    }

    private static function scoreFromApp(): ApiException
    {
        return new ApiException('งานนี้ตรวจด้วยแอป คะแนนมาจากผลที่ประกาศแล้ว ตั้งได้เฉพาะ "ยกเว้น"', 'score_from_app', 422);
    }
}
