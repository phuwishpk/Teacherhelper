<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Timetable\Timetable;
use App\Http\Controllers\Controller;
use App\Models\TimetableCancellation;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/student/timetable?week= -> {data: {week_start, slots,
 * cancellations}} (DESIGN §29.12): the periods of the student's open
 * classrooms, each with its course and teacher.
 */
class StudentTimetableController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $weekStart = Timetable::weekStart($request->query('week'));
        $slots = Timetable::forStudent($request->user())->orderBy('weekday')->orderBy('period_no')->orderBy('starts_at')->get();

        return response()->json(['data' => [
            'week_start' => $weekStart->toDateString(),
            'slots' => $slots->map(fn (TimetableSlot $s) => Timetable::slot($s) + ['teacher_name' => $s->course?->creator?->name])->all(),
            'cancellations' => Timetable::cancellations($slots->modelKeys(), $weekStart)
                ->map(fn (TimetableCancellation $c) => ['id' => $c->id, 'slot_id' => $c->timetable_slot_id, 'held_on' => $c->held_on?->toDateString(), 'note' => $c->note])->all(),
        ]]);
    }
}
