<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\AttendanceBook;
use App\Domain\Students\StudentClassrooms;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/student/attendance?course_id=&classroom_id= -> {data: [{course:
 * {id, code, name}, classroom: {id, name, academic_year, closed}, counts,
 * counted, rate, sessions: [{id, held_on, period_no, status, note}]}]}
 * (DESIGN §29.5): the student's own status in every checked period, per
 * course and classroom, newest period first. A course without a record of
 * the student is left out.
 */
class StudentAttendanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = StudentClassrooms::filters($request);
        $student = $request->user();
        $rooms = StudentClassrooms::of($student)->keyBy('id');

        $rows = DB::table('attendance_records')
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->where('attendance_records.student_id', $student->id)
            ->whereIn('attendance_sessions.classroom_id', $rooms->keys()->all())
            ->when($filters['course_id'] !== null, fn ($q) => $q->where('attendance_sessions.course_id', $filters['course_id']))
            ->when($filters['classroom_id'] !== null, fn ($q) => $q->where('attendance_sessions.classroom_id', $filters['classroom_id']))
            ->orderByDesc('attendance_sessions.held_on')
            ->orderByDesc('attendance_sessions.period_no')
            ->orderByDesc('attendance_sessions.id')
            ->get([
                'attendance_sessions.id', 'attendance_sessions.course_id', 'attendance_sessions.classroom_id',
                'attendance_sessions.held_on', 'attendance_sessions.period_no',
                'attendance_records.status', 'attendance_records.note',
            ]);

        $courses = Course::query()->whereIn('id', $rows->pluck('course_id')->unique()->all())->get()->keyBy('id');
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->course_id.':'.$row->classroom_id;
            $groups[$key] ??= ['course_id' => (int) $row->course_id, 'classroom_id' => (int) $row->classroom_id, 'by_status' => [], 'sessions' => []];
            $groups[$key]['by_status'][$row->status] = ($groups[$key]['by_status'][$row->status] ?? 0) + 1;
            $groups[$key]['sessions'][] = [
                'id' => (int) $row->id,
                'held_on' => substr((string) $row->held_on, 0, 10),
                'period_no' => $row->period_no === null ? null : (int) $row->period_no,
                'status' => (string) $row->status,
                'note' => $row->note,
            ];
        }

        $data = [];
        foreach ($groups as $group) {
            /** @var Course|null $course */
            $course = $courses->get($group['course_id']);
            /** @var Classroom|null $room */
            $room = $rooms->get($group['classroom_id']);
            if ($course === null || $room === null) {
                continue;
            }
            $data[] = [
                'course' => StudentClassrooms::course($course),
                'classroom' => StudentClassrooms::label($room),
                ...AttendanceBook::total($group['by_status'], AttendanceBook::scores($course)),
                'sessions' => $group['sessions'],
            ];
        }

        return response()->json(['data' => $data]);
    }
}
