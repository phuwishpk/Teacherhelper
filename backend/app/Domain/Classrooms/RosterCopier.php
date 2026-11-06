<?php

namespace App\Domain\Classrooms;

use App\Domain\Google\ClassroomImporter;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "นำนักเรียนจากห้องเดิม" (DESIGN §24.6, POST /classrooms/{id}/students/from-classroom):
 * the homeroom teacher of a new classroom enrols students of another
 * classroom of the school (usually last year's closed one) with their one
 * account, through StudentEnroller like "เลือกนักเรียนที่มีอยู่".
 *
 * - numbering `keep`: each student keeps their number of the source
 *   classroom; a number already taken in the target (or by an earlier
 *   student of the list) goes after the highest number instead.
 *   `sorted`: ThaiNameSorter order, after the highest number of the target
 *   (from 1 in an empty classroom).
 * - pin `keep`: the PIN and QR card the student has (pin = null); `new`:
 *   a new PIN for everyone, shown once, which signs them out everywhere
 *   (§7.4).
 *
 * A student already in the target classroom, or no longer active (disabled
 * or merged), is skipped and reported with the reason; a student id that is
 * not in the source classroom is a 422. Any teacher of the school may copy
 * from any of its classrooms: only names and numbers are read, never results.
 */
final class RosterCopier
{
    public const NUMBERING_KEEP = 'keep';

    public const NUMBERING_SORTED = 'sorted';

    public const PIN_KEEP = 'keep';

    public const PIN_NEW = 'new';

    public function __construct(private readonly StudentEnroller $enroller) {}

    /**
     * @param  list<int>  $studentIds
     * @return array{enrolled: list<array{student: User, student_number: int, pin: string|null, existing: bool}>, skipped: list<array{student_id: int, name: string, reason: string}>}
     *
     * @throws ApiException 422 validation_failed (source_classroom_id, student_ids.{i}, too many numbers)
     */
    public function copy(Classroom $target, Classroom $source, array $studentIds, string $numbering, string $pin): array
    {
        if ($source->id === $target->id) {
            throw self::invalid('source_classroom_id', 'เลือกห้องต้นทางที่ไม่ใช่ห้องนี้');
        }
        $members = ClassroomStudent::query()
            ->where('classroom_id', $source->id)
            ->whereIn('student_id', $studentIds)
            ->pluck('student_number', 'student_id')
            ->mapWithKeys(fn ($number, $id) => [(int) $id => (int) $number])
            ->all();
        $errors = [];
        foreach ($studentIds as $i => $id) {
            if (! isset($members[$id])) {
                $errors["student_ids.{$i}"] = ['นักเรียนคนนี้ไม่ได้อยู่ในห้องต้นทาง'];
            }
        }
        if ($errors !== []) {
            throw new ApiException('นักเรียนบางคนไม่ได้อยู่ในห้องต้นทาง', 'validation_failed', 422, $errors);
        }

        return DB::transaction(function () use ($target, $studentIds, $members, $numbering, $pin) {
            Classroom::query()->whereKey($target->id)->lockForUpdate()->first();
            $students = User::query()->whereIn('id', $studentIds)->get()->keyBy('id');
            $inTarget = ClassroomStudent::query()->where('classroom_id', $target->id)->pluck('student_number', 'student_id');
            $taken = array_flip($inTarget->map(fn ($n) => (int) $n)->values()->all());

            $chosen = [];
            $skipped = [];
            foreach ($studentIds as $id) {
                $student = $students->get($id);
                if ($inTarget->has($id)) {
                    $skipped[] = ['student_id' => $id, 'name' => (string) $student?->name, 'reason' => 'already_enrolled'];
                } elseif (! StudentEnroller::isEnrollableStudent($student, $target)) {
                    $skipped[] = ['student_id' => $id, 'name' => (string) $student?->name, 'reason' => 'not_active'];
                } else {
                    $chosen[] = ['id' => $id, 'name' => (string) $student->name, 'source_number' => $members[$id]];
                }
            }
            if ($chosen === []) {
                return ['enrolled' => [], 'skipped' => $skipped];
            }

            $rows = [];
            if ($numbering === self::NUMBERING_KEEP) {
                usort($chosen, fn (array $a, array $b) => [$a['source_number'], $a['id']] <=> [$b['source_number'], $b['id']]);
                $moved = [];
                foreach ($chosen as $student) {
                    if (isset($taken[$student['source_number']])) {
                        $moved[] = $student;

                        continue;
                    }
                    $taken[$student['source_number']] = true;
                    $rows[] = self::row($student['id'], $student['source_number'], $pin);
                }
                $next = ($taken === [] ? 0 : max(array_keys($taken))) + 1;
                foreach ($moved as $student) {
                    $rows[] = self::row($student['id'], $next++, $pin);
                }
            } else {
                $next = ($taken === [] ? 0 : max(array_keys($taken))) + 1;
                foreach (ThaiNameSorter::sort($chosen, 'name', 'id') as $student) {
                    $rows[] = self::row($student['id'], $next++, $pin);
                }
            }
            if (max(array_column($rows, 'student_number')) > ClassroomImporter::MAX_STUDENT_NUMBER) {
                throw self::invalid('student_ids', 'เลขที่จะเกิน '.ClassroomImporter::MAX_STUDENT_NUMBER.' เลือกนักเรียนน้อยลง');
            }
            usort($rows, fn (array $a, array $b) => $a['student_number'] <=> $b['student_number']);

            return ['enrolled' => $this->enroller->enroll($target, $rows), 'skipped' => $skipped];
        });
    }

    /**
     * @return array{student_id: int, student_number: int, reissue_pin: bool}
     */
    private static function row(int $studentId, int $number, string $pin): array
    {
        return ['student_id' => $studentId, 'student_number' => $number, 'reissue_pin' => $pin === self::PIN_NEW];
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
    }
}
