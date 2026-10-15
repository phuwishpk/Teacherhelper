<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceBook;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Gradebook\GradebookAccess;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Attendance of a course in a classroom, per period (DESIGN §29.5). The
 * teacher works on a course they created and a classroom bound to it, like
 * the gradebook (GradebookAccess). Every change rewrites the scores of the
 * "การเข้าเรียน" gradebook item when the teacher added one.
 */
class AttendanceController extends Controller
{
    public const MAX_PERIOD = 20;

    /**
     * GET /api/v1/courses/{id}/attendance?classroom_id= -> {data: {scores:
     * {present, late, absent}, auto_item: {id, category_id, max_points}|null,
     * sessions: [session + counts], students: [{student_id, student_number,
     * name, in_classroom, counts, counted, rate}]}}, newest session first.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('view', $course);
        $classroom = GradebookAccess::classroom($request->user(), $course, $request->query('classroom_id'));

        $counts = AttendanceBook::sessionCounts($course, $classroom);
        $sessions = AttendanceSession::query()
            ->where('course_id', $course->id)->where('classroom_id', $classroom->id)
            ->orderByDesc('held_on')->orderByDesc('period_no')->orderByDesc('id')
            ->get();
        $totals = AttendanceBook::totals($course, $classroom->id);
        $scores = AttendanceBook::scores($course);
        $students = [];
        foreach (self::people($classroom, array_keys($totals)) as $person) {
            $students[] = $person + ($totals[$person['student_id']] ?? AttendanceBook::total([], $scores));
        }
        $item = AttendanceBook::autoItem($course->id, $classroom->id);

        return response()->json(['data' => [
            'scores' => $scores,
            'auto_item' => $item === null ? null : ['id' => $item->id, 'category_id' => $item->category_id, 'max_points' => $item->max_points],
            'sessions' => $sessions->map(fn (AttendanceSession $s) => self::payload($s, $counts[$s->id] ?? null))->all(),
            'students' => $students,
        ]]);
    }

    /**
     * POST /api/v1/courses/{id}/attendance-sessions {classroom_id, held_on,
     * period_no?, note?, records?: [{student_id, status, note?}]} -> 201
     * {data: session + records}. A student of the classroom not listed in
     * records is มาตรง.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $teacher = $request->user();
        $data = $request->validate(['classroom_id' => ['required'], ...self::rules(false)], self::messages());
        $classroom = GradebookAccess::openClassroom($teacher, $course, $data['classroom_id']);
        $records = self::records($classroom, $data['records'] ?? [], true);

        $session = DB::transaction(function () use ($course, $classroom, $teacher, $data, $records) {
            Course::query()->whereKey($course->id)->lockForUpdate()->first();
            $periodNo = isset($data['period_no']) ? (int) $data['period_no'] : null;
            self::assertPeriodFree($course->id, $classroom->id, $data['held_on'], $periodNo);
            try {
                $session = AttendanceSession::create([
                    'course_id' => $course->id,
                    'classroom_id' => $classroom->id,
                    'held_on' => $data['held_on'],
                    'period_no' => $periodNo,
                    'note' => self::text($data['note'] ?? null),
                    'created_by' => $teacher->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw self::periodTaken();
            }
            self::write($session, $records, $teacher);
            AttendanceBook::syncGrades($course, $classroom->id, $teacher);

            return $session;
        });

        return response()->json(['data' => self::detail($session, $classroom)], 201);
    }

    /** GET /api/v1/attendance-sessions/{id} -> {data: session + records of the roster} */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = $this->session($request, $id, 'view');

        return response()->json(['data' => self::detail($session, $session->classroom()->firstOrFail())]);
    }

    /**
     * PUT /api/v1/attendance-sessions/{id} {held_on?, period_no?, note?,
     * records?} -> {data: session + records}; only the listed students change.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $session = $this->session($request, $id, 'update');
        $teacher = $request->user();
        $classroom = $session->classroom()->firstOrFail();
        ClosedClassrooms::assertOpen($classroom);
        $data = $request->validate(self::rules(true), self::messages());
        $records = self::records($classroom, $data['records'] ?? [], false);

        DB::transaction(function () use ($session, $classroom, $teacher, $data, $records) {
            $course = Course::query()->lockForUpdate()->findOrFail($session->course_id);
            $session = AttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if (array_key_exists('held_on', $data)) {
                $session->held_on = $data['held_on'];
            }
            if (array_key_exists('period_no', $data)) {
                $session->period_no = $data['period_no'] === null ? null : (int) $data['period_no'];
            }
            if (array_key_exists('note', $data)) {
                $session->note = self::text($data['note']);
            }
            if ($session->isDirty(['held_on', 'period_no'])) {
                self::assertPeriodFree($course->id, $classroom->id, $session->held_on->toDateString(), $session->period_no, $session->id);
            }
            try {
                $session->save();
            } catch (UniqueConstraintViolationException) {
                throw self::periodTaken();
            }
            self::write($session, $records, $teacher);
            AttendanceBook::syncGrades($course, $classroom->id, $teacher);
        });

        return response()->json(['data' => self::detail($session->refresh(), $classroom)]);
    }

    /** DELETE /api/v1/attendance-sessions/{id} -> 204 (its records go with it) */
    public function destroy(Request $request, int $id): Response
    {
        $session = $this->session($request, $id, 'update');
        ClosedClassrooms::assertOpen($session->classroom()->firstOrFail());
        DB::transaction(function () use ($session, $request) {
            $course = Course::query()->lockForUpdate()->findOrFail($session->course_id);
            $session->delete();
            AttendanceBook::syncGrades($course, $session->classroom_id, $request->user());
        });

        return response()->noContent();
    }

    /**
     * PUT /api/v1/courses/{id}/attendance/scores {present, late, absent} ->
     * {data: {scores}}: the value of each counted status, 0..1.
     */
    public function scores(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $rule = ['required', 'numeric', 'min:0', 'max:1', 'decimal:0,2'];
        $data = $request->validate(
            ['present' => $rule, 'late' => $rule, 'absent' => $rule],
            ['*.required' => 'กรอกคะแนนให้ครบทั้งสามสถานะ', '*.numeric' => 'คะแนนต้องเป็นตัวเลข', '*.min' => 'คะแนนต้องอยู่ระหว่าง 0 ถึง 1', '*.max' => 'คะแนนต้องอยู่ระหว่าง 0 ถึง 1', '*.decimal' => 'คะแนนมีทศนิยมได้ไม่เกิน 2 ตำแหน่ง'],
        );

        DB::transaction(function () use ($course, $data, $request) {
            $course = Course::query()->lockForUpdate()->findOrFail($course->id);
            $course->attendance_scores = ['present' => (float) $data['present'], 'late' => (float) $data['late'], 'absent' => (float) $data['absent']];
            $course->save();
            AttendanceBook::syncCourse($course, $request->user());
        });

        return response()->json(['data' => ['scores' => AttendanceBook::scores($course->refresh())]]);
    }

    /** A session of a course the teacher created, in a classroom the teacher teaches (404 otherwise). */
    private function session(Request $request, int $id, string $ability): AttendanceSession
    {
        $session = AttendanceSession::query()
            ->whereIn('course_id', CourseController::ownQuery($request)->select('id'))
            ->whereIn('classroom_id', ClassroomAccess::classrooms($request->user())->select('classrooms.id'))
            ->findOrFail($id);
        Gate::authorize($ability, $session->course()->firstOrFail());

        return $session;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function rules(bool $partial): array
    {
        return [
            'held_on' => [...($partial ? ['sometimes', 'required'] : ['required']), 'date_format:Y-m-d', 'after:2019-12-31', 'before:2100-01-01'],
            'period_no' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PERIOD],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'records' => ['sometimes', 'array', 'list', 'max:200'],
            'records.*' => ['required', 'array:student_id,status,note'],
            'records.*.student_id' => ['required', 'integer', 'min:1', 'distinct'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'records.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'classroom_id.required' => 'เลือกห้องเรียน',
            'held_on.required' => 'เลือกวันที่',
            'held_on.date_format' => 'วันที่ไม่ถูกต้อง',
            'held_on.after' => 'วันที่ไม่ถูกต้อง',
            'held_on.before' => 'วันที่ไม่ถูกต้อง',
            'period_no.integer' => 'คาบต้องเป็นตัวเลข',
            'period_no.min' => 'คาบต้องอยู่ระหว่าง 1 ถึง '.self::MAX_PERIOD,
            'period_no.max' => 'คาบต้องอยู่ระหว่าง 1 ถึง '.self::MAX_PERIOD,
            'note.max' => 'หมายเหตุยาวไม่เกิน 255 ตัวอักษร',
            'records.*.student_id.distinct' => 'มีนักเรียนซ้ำในรายการ',
            'records.*.status.in' => 'สถานะการเข้าเรียนไม่ถูกต้อง',
            'records.*.note.max' => 'หมายเหตุยาวไม่เกิน 255 ตัวอักษร',
        ];
    }

    /**
     * The sent records, checked against the roster; $fill adds มาตรง for
     * every student of the classroom that was not sent.
     *
     * @param  list<array{student_id: int, status: string, note?: string|null}>  $sent
     * @return array<int, array{status: string, note: string|null}> by student id
     */
    private static function records(Classroom $classroom, array $sent, bool $fill): array
    {
        $roster = $classroom->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $members = array_flip($roster);
        $errors = [];
        $records = [];
        foreach ($sent as $i => $row) {
            $studentId = (int) $row['student_id'];
            if (! isset($members[$studentId])) {
                $errors["records.{$i}.student_id"] = ['นักเรียนคนนี้ไม่ได้อยู่ในห้องนี้'];

                continue;
            }
            $records[$studentId] = ['status' => (string) $row['status'], 'note' => self::text($row['note'] ?? null)];
        }
        if ($errors !== []) {
            throw new ApiException('ข้อมูลการเช็คชื่อไม่ถูกต้อง', 'validation_failed', 422, $errors);
        }
        if ($fill) {
            foreach ($roster as $studentId) {
                $records[$studentId] ??= ['status' => AttendanceRecord::PRESENT, 'note' => null];
            }
        }

        return $records;
    }

    /**
     * @param  array<int, array{status: string, note: string|null}>  $records
     */
    private static function write(AttendanceSession $session, array $records, User $teacher): void
    {
        if ($records === []) {
            return;
        }
        $existing = AttendanceRecord::query()->where('attendance_session_id', $session->id)->lockForUpdate()->get()->keyBy('student_id');
        foreach ($records as $studentId => $record) {
            $row = $existing->get($studentId) ?? new AttendanceRecord(['attendance_session_id' => $session->id, 'student_id' => $studentId]);
            $row->fill($record);
            if (! $row->exists || $row->isDirty()) {
                $row->updated_by = $teacher->id;
                $row->save();
            }
        }
    }

    private static function assertPeriodFree(int $courseId, int $classroomId, string $heldOn, ?int $periodNo, ?int $exceptId = null): void
    {
        $taken = AttendanceSession::query()
            ->where('course_id', $courseId)->where('classroom_id', $classroomId)
            ->whereDate('held_on', $heldOn)
            ->when($periodNo === null, fn ($q) => $q->whereNull('period_no'), fn ($q) => $q->where('period_no', $periodNo))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            throw self::periodTaken();
        }
    }

    private static function periodTaken(): ApiException
    {
        $message = 'วันนี้เช็คชื่อคาบนี้ไปแล้ว เปิดรายการเดิมเพื่อแก้ไข หรือใส่เลขคาบอื่น';

        return new ApiException($message, 'attendance_period_taken', 422, ['period_no' => [$message]]);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, int>|null  $counts
     * @return array<string, mixed>
     */
    private static function payload(AttendanceSession $session, ?array $counts = null): array
    {
        return [
            'id' => $session->id,
            'course_id' => $session->course_id,
            'classroom_id' => $session->classroom_id,
            'held_on' => $session->held_on->toDateString(),
            'period_no' => $session->period_no,
            'note' => $session->note,
            'counts' => $counts ?? array_fill_keys(AttendanceRecord::STATUSES, 0),
        ];
    }

    /** @return array<string, mixed> */
    private static function detail(AttendanceSession $session, Classroom $classroom): array
    {
        $records = AttendanceRecord::query()->where('attendance_session_id', $session->id)->get()->keyBy('student_id');
        $counts = array_fill_keys(AttendanceRecord::STATUSES, 0);
        foreach ($records as $record) {
            $counts[$record->status]++;
        }
        $rows = [];
        foreach (self::people($classroom, $records->keys()->all()) as $person) {
            $record = $records->get($person['student_id']);
            $rows[] = $person + ['status' => $record?->status, 'note' => $record?->note];
        }

        return self::payload($session, $counts) + ['records' => $rows];
    }

    /**
     * The roster by student number, then students with records who left it.
     *
     * @param  list<int>  $withRecords
     * @return list<array{student_id: int, student_number: int|null, name: string, in_classroom: bool}>
     */
    private static function people(Classroom $classroom, array $withRecords): array
    {
        $people = [];
        foreach ($classroom->students()->get(['users.id', 'users.name']) as $student) {
            $people[$student->id] = ['student_id' => $student->id, 'student_number' => (int) $student->pivot->student_number, 'name' => (string) $student->name, 'in_classroom' => true];
        }
        $gone = array_values(array_diff($withRecords, array_keys($people)));
        foreach (User::query()->whereIn('id', $gone)->orderBy('name')->get(['id', 'name']) as $student) {
            $people[$student->id] = ['student_id' => $student->id, 'student_number' => null, 'name' => (string) $student->name, 'in_classroom' => false];
        }

        return array_values($people);
    }
}
