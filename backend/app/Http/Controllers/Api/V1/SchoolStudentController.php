<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\SchoolStudents;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /school-students?q= (DESIGN §24.4): every active teacher of the school
 * searches the school's students by name or student code, to enrol an
 * existing student or to pick the other account of a merge. Only names,
 * codes and classrooms: no email, PIN or result (§24.14).
 */
class SchoolStudentController extends Controller
{
    public function __construct(private readonly SchoolStudents $students) {}

    /** -> {data: [{id, name, student_code, has_google, classrooms: [{id, name, academic_year, student_number, closed}]}]} */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Classroom::class);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:'.SchoolStudents::MIN_QUERY, 'max:100'],
        ], [
            'q.required' => 'พิมพ์ชื่อหรือเลขประจำตัวอย่างน้อย '.SchoolStudents::MIN_QUERY.' ตัวอักษร',
            'q.min' => 'พิมพ์ชื่อหรือเลขประจำตัวอย่างน้อย '.SchoolStudents::MIN_QUERY.' ตัวอักษร',
            'q.max' => 'คำค้นยาวเกินไป',
        ]);

        return response()->json(['data' => $this->students->search((int) $request->user()->school_id, trim($data['q']))]);
    }
}
