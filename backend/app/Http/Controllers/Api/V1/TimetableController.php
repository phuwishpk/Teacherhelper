<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Gradebook\GradebookAccess;
use App\Domain\Timetable\Timetable;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\TimetableCancellation;
use App\Models\TimetableSlot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The teacher's weekly timetable (DESIGN §29.12): the slots of their own
 * courses, the busy blocks of shared classrooms, and the cancelled dates.
 */
class TimetableController extends Controller
{
    public const MAX_PERIOD = AttendanceController::MAX_PERIOD;

    /**
     * GET /api/v1/timetable?week=YYYY-MM-DD -> {data: {week_start, slots,
     * busy, cancellations, terms}}. A slot carries its course and classroom;
     * a busy block only its classroom.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $weekStart = Timetable::weekStart($request->query('week'));
        $slots = Timetable::own($teacher)->orderBy('weekday')->orderBy('period_no')->orderBy('starts_at')->get();
        $busy = Timetable::busy($teacher)->orderBy('weekday')->orderBy('period_no')->get();
        $cancellations = Timetable::cancellations($slots->modelKeys(), $weekStart);
        $terms = Course::query()->where('created_by', $teacher->id)->where('school_id', $teacher->school_id)
            ->whereNotNull('term_starts_on')->orderBy('term_starts_on')
            ->get(['id', 'code', 'term_starts_on', 'term_ends_on']);

        return response()->json(['data' => [
            'week_start' => $weekStart->toDateString(),
            'slots' => $slots->map(fn (TimetableSlot $s) => Timetable::slot($s))->all(),
            'busy' => $busy->map(fn (TimetableSlot $s) => Timetable::slot($s, false))->all(),
            'cancellations' => $cancellations->map(fn (TimetableCancellation $c) => self::cancellation($c))->all(),
            'terms' => $terms->map(fn (Course $c) => [
                'course_id' => $c->id,
                'code' => (string) $c->code,
                'term_starts_on' => $c->term_starts_on?->toDateString(),
                'term_ends_on' => $c->term_ends_on?->toDateString(),
            ])->all(),
        ]]);
    }

    /**
     * POST /api/v1/courses/{id}/timetable-slots {classroom_id, weekday,
     * period_no?, starts_at?, ends_at?} -> 201 {data: slot}. 422
     * timetable_period_taken when the classroom has that period that day.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $course = CourseController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $course);
        $data = $request->validate(['classroom_id' => ['required'], ...self::rules()], self::messages());
        $classroom = GradebookAccess::openClassroom($request->user(), $course, $data['classroom_id']);
        self::assertTimes($data);
        $periodNo = isset($data['period_no']) ? (int) $data['period_no'] : null;
        self::assertFree($classroom->id, (int) $data['weekday'], $periodNo, null);

        $slot = TimetableSlot::create([
            'course_id' => $course->id,
            'classroom_id' => $classroom->id,
            'weekday' => (int) $data['weekday'],
            'period_no' => $periodNo,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => Timetable::slot($slot->load(['course:id,code,name', 'classroom:id,name']))], 201);
    }

    /** PUT /api/v1/timetable-slots/{id} {weekday?, period_no?, starts_at?, ends_at?} -> {data: slot} */
    public function update(Request $request, int $id): JsonResponse
    {
        $slot = $this->own($request, $id);
        ClosedClassrooms::assertOpen($slot->classroom);
        $data = $request->validate(self::rules(true), self::messages());
        $merged = ['starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, ...$data];
        self::assertTimes($merged);
        $weekday = array_key_exists('weekday', $data) ? (int) $data['weekday'] : $slot->weekday;
        $periodNo = array_key_exists('period_no', $data) ? ($data['period_no'] === null ? null : (int) $data['period_no']) : $slot->period_no;
        self::assertFree($slot->classroom_id, $weekday, $periodNo, $slot->id);
        $slot->fill([
            'weekday' => $weekday,
            'period_no' => $periodNo,
            'starts_at' => array_key_exists('starts_at', $data) ? $data['starts_at'] : $slot->starts_at,
            'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $slot->ends_at,
        ])->save();

        return response()->json(['data' => Timetable::slot($slot->load(['course:id,code,name', 'classroom:id,name']))]);
    }

    /** DELETE /api/v1/timetable-slots/{id} -> 204 */
    public function destroy(Request $request, int $id): Response
    {
        $slot = $this->own($request, $id);
        ClosedClassrooms::assertOpen($slot->classroom);
        $slot->delete();

        return response()->noContent();
    }

    /**
     * POST /api/v1/timetable-slots/{id}/cancellations {held_on, note?} -> 201
     * {data: cancellation}: that date's period does not happen (§29.5
     * "ยกเลิกคาบ"). The date must fall on the slot's weekday.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $slot = $this->own($request, $id);
        ClosedClassrooms::assertOpen($slot->classroom);
        $data = $request->validate([
            'held_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['held_on.required' => 'ระบุวันที่ยกเลิก', 'held_on.date_format' => 'วันที่ต้องเป็น YYYY-MM-DD']);
        if (Carbon::parse($data['held_on'], 'Asia/Bangkok')->dayOfWeekIso !== (int) $slot->weekday) {
            throw new ApiException('วันที่นี้ไม่ตรงกับวันของคาบ', 'validation_failed', 422, ['held_on' => ['วันที่นี้ไม่ตรงกับวันของคาบ']]);
        }
        try {
            $cancellation = TimetableCancellation::create([
                'timetable_slot_id' => $slot->id,
                'held_on' => $data['held_on'],
                'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
                'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('คาบนี้ยกเลิกไว้แล้ว', 'timetable_already_cancelled', 409);
        }

        return response()->json(['data' => self::cancellation($cancellation)], 201);
    }

    /** DELETE /api/v1/timetable-cancellations/{id} -> 204: the period happens after all. */
    public function uncancel(Request $request, int $id): Response
    {
        $cancellation = TimetableCancellation::query()
            ->whereIn('timetable_slot_id', Timetable::own($request->user())->select('timetable_slots.id'))
            ->findOrFail($id);
        $cancellation->delete();

        return response()->noContent();
    }

    private function own(Request $request, int $id): TimetableSlot
    {
        return Timetable::own($request->user())->with('classroom')->findOrFail($id);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function rules(bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'weekday' => [...$required, 'integer', 'min:1', 'max:7'],
            'period_no' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PERIOD],
            'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'weekday.required' => 'เลือกวันในสัปดาห์',
            'weekday.min' => 'วันต้องเป็น 1 (จันทร์) ถึง 7 (อาทิตย์)',
            'weekday.max' => 'วันต้องเป็น 1 (จันทร์) ถึง 7 (อาทิตย์)',
            'period_no.max' => 'เลขคาบต้องไม่เกิน '.self::MAX_PERIOD,
            'starts_at.date_format' => 'เวลาต้องเป็น HH:MM',
            'ends_at.date_format' => 'เวลาต้องเป็น HH:MM',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertTimes(array $data): void
    {
        $start = $data['starts_at'] ?? null;
        $end = $data['ends_at'] ?? null;
        if ($start !== null && $end !== null && substr((string) $end, 0, 5) <= substr((string) $start, 0, 5)) {
            throw new ApiException('เวลาจบต้องหลังเวลาเริ่ม', 'validation_failed', 422, ['ends_at' => ['เวลาจบต้องหลังเวลาเริ่ม']]);
        }
    }

    /** A classroom has one course per numbered period of a weekday. */
    private static function assertFree(int $classroomId, int $weekday, ?int $periodNo, ?int $exceptId): void
    {
        if ($periodNo === null) {
            return;
        }
        $taken = TimetableSlot::query()
            ->where('classroom_id', $classroomId)->where('weekday', $weekday)->where('period_no', $periodNo)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->with('course:id,code')
            ->first();
        if ($taken !== null) {
            $message = "คาบ {$periodNo} วัน".Timetable::WEEKDAYS[$weekday - 1].' ของห้องนี้มีรายวิชา '.(string) ($taken->course?->code ?? '').' อยู่แล้ว';

            throw new ApiException($message, 'timetable_period_taken', 422, ['period_no' => [$message]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function cancellation(TimetableCancellation $c): array
    {
        return ['id' => $c->id, 'slot_id' => $c->timetable_slot_id, 'held_on' => $c->held_on?->toDateString(), 'note' => $c->note];
    }
}
