<?php

namespace App\Domain\Classrooms;

use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\StudentCode;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /classrooms/{id}/students (DESIGN §9.2, §24.4): each row is either
 *
 * - a new student `{name, student_number, student_code?}`: a student user
 *   (no email, no password) of the classroom's school with fresh
 *   credentials; or
 * - an existing student of the school `{student_id, student_number,
 *   reissue_pin?}`: enrolled with the PIN and QR card they already have
 *   (one credential per student across classrooms), unless reissue_pin
 *   asks for a new PIN (which revokes their sessions, §7.4).
 *
 * The plain PIN of a new student, or a reissued one, is returned exactly
 * once; an existing student gets pin = null.
 */
class StudentEnroller
{
    public function __construct(private readonly CredentialIssuer $issuer) {}

    /**
     * All-or-nothing: any rejected row rejects the whole payload and writes
     * nothing, with one code per answer, checked in this order:
     * 422 student_not_in_school, already_enrolled (errors.students.{i}.student_id),
     * student_code_taken (errors.students.{i}.student_code, with
     * existing_student), student_number_taken (errors.students).
     *
     * @param  array<int, array{name?: string, student_number: int, student_code?: string|null, student_id?: int, reissue_pin?: bool}>  $rows
     * @return array<int, array{student: User, student_number: int, pin: string|null, existing: bool}>
     *
     * @throws ApiException
     */
    public function enroll(Classroom $classroom, array $rows): array
    {
        // validated() builds nested rows key by key, so row 1 can come before row 0.
        ksort($rows);
        $rows = array_values($rows);
        $numbers = array_map(fn (array $row) => (int) $row['student_number'], $rows);
        $codes = $this->codes($rows);

        try {
            return DB::transaction(function () use ($classroom, $rows, $numbers, $codes) {
                // Serialises concurrent bulk-adds of one classroom on MariaDB
                // (a no-op on SQLite), so the checks below are race-free there.
                Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

                $existing = $this->assertEnrollable($classroom, $rows, $numbers, $codes);

                $enrolled = [];
                foreach ($rows as $i => $row) {
                    $number = (int) $row['student_number'];
                    if (isset($row['student_id'])) {
                        $student = $existing[(int) $row['student_id']];
                        $classroom->students()->attach($student->id, ['student_number' => $number]);
                        $pin = null;
                        if (! empty($row['reissue_pin']) || ! $student->credential()->exists()) {
                            $pin = $this->issuer->issuePin($student);
                        }
                        $enrolled[] = ['student' => $student, 'student_number' => $number, 'pin' => $pin, 'existing' => true];

                        continue;
                    }

                    $student = User::create([
                        'school_id' => $classroom->school_id,
                        'role' => User::ROLE_STUDENT,
                        'name' => $row['name'],
                        'email' => null,
                        'password' => null,
                        'status' => User::STATUS_ACTIVE,
                        'student_code' => $codes[$i] ?? null,
                    ]);
                    $classroom->students()->attach($student->id, ['student_number' => $number]);
                    $credentials = $this->issuer->create($student);

                    $enrolled[] = ['student' => $student, 'student_number' => $number, 'pin' => $credentials['pin'], 'existing' => false];
                }

                return $enrolled;
            });
        } catch (UniqueConstraintViolationException) {
            // A unique key fired anyway (a race the lock could not cover): the
            // transaction is rolled back, so report what the committed rows show.
            $this->assertEnrollable($classroom, $rows, $numbers, $codes);

            throw self::numbersTaken([]);
        }
    }

    /**
     * Every check of enroll(), against the committed rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $numbers
     * @param  array<int, string>  $codes  row index => normalised student code (new students)
     * @return array<int, User> the existing students by id
     *
     * @throws ApiException
     */
    private function assertEnrollable(Classroom $classroom, array $rows, array $numbers, array $codes): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['student_id'])) {
                $ids[] = (int) $row['student_id'];
            }
        }
        $existing = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get()->keyBy('id');
        $enrolledIds = $ids === [] ? [] : $classroom->students()->whereIn('users.id', $ids)->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $notInSchool = [];
        $already = [];
        foreach ($rows as $i => $row) {
            if (! isset($row['student_id'])) {
                continue;
            }
            $student = $existing->get((int) $row['student_id']);
            if (! self::isEnrollableStudent($student, $classroom)) {
                $notInSchool["students.{$i}.student_id"] = ['ไม่พบนักเรียนคนนี้ในโรงเรียน'];
            } elseif (in_array($student->id, $enrolledIds, true)) {
                $already["students.{$i}.student_id"] = [$student->name.' อยู่ในห้องนี้แล้ว'];
            }
        }
        if ($notInSchool !== []) {
            throw new ApiException('ไม่พบนักเรียนบางคนในโรงเรียน', 'student_not_in_school', 422, $notInSchool);
        }
        if ($already !== []) {
            throw new ApiException('นักเรียนบางคนอยู่ในห้องนี้แล้ว', 'already_enrolled', 422, $already);
        }

        foreach ($codes as $i => $code) {
            $holder = StudentCode::holder($classroom->school_id, $code);
            if ($holder !== null) {
                throw StudentCode::taken($holder, $code, "students.{$i}.student_code");
            }
        }

        $taken = $this->takenNumbers($classroom, $numbers);
        if ($taken !== []) {
            throw self::numbersTaken($taken);
        }

        /** @var array<int, User> */
        return $existing->all();
    }

    /** An active, unmerged student of the classroom's school. */
    public static function isEnrollableStudent(?User $student, Classroom $classroom): bool
    {
        return $student !== null
            && $student->isStudent()
            && $student->isActive()
            && ! $student->isMerged()
            && $student->school_id === $classroom->school_id;
    }

    /**
     * Normalised student codes of the new rows, keyed by row index; a code
     * given twice in the payload is a 422.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     *
     * @throws ApiException
     */
    private function codes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $i => $row) {
            if (isset($row['student_id'])) {
                continue;
            }
            $code = StudentCode::parse($row['student_code'] ?? null, "students.{$i}.student_code");
            if ($code === null) {
                continue;
            }
            if (in_array($code, $codes, true)) {
                $message = 'เลขประจำตัวซ้ำกันในรายการ';

                throw new ApiException($message, 'validation_failed', 422, ["students.{$i}.student_code" => [$message]]);
            }
            $codes[$i] = $code;
        }

        return $codes;
    }

    /**
     * Student numbers of the payload that are already taken in this classroom.
     *
     * @param  array<int, int>  $numbers
     * @return array<int, int>
     */
    public function takenNumbers(Classroom $classroom, array $numbers): array
    {
        return $classroom->students()
            ->wherePivotIn('student_number', $numbers)
            ->pluck('classroom_students.student_number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  array<int, int>  $taken
     */
    public static function numbersTaken(array $taken): ApiException
    {
        sort($taken);
        $list = implode(', ', $taken);

        return new ApiException(
            $taken === [] ? 'มีเลขที่ซ้ำกับนักเรียนในห้องนี้แล้ว' : 'เลขที่ '.$list.' มีอยู่ในห้องนี้แล้ว',
            'student_number_taken',
            422,
            ['students' => [$taken === [] ? 'เลขที่ซ้ำกับนักเรียนที่มีอยู่แล้ว' : 'เลขที่ซ้ำกับนักเรียนที่มีอยู่แล้ว: '.$list]],
        );
    }
}
