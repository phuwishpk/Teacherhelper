<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\LoginCardPrintService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoginCardPrintResource;
use App\Models\Classroom;
use App\Models\LoginCardPrint;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * QR login cards (DESIGN §9.2): queue a PDF for a classroom or one student,
 * poll it, download it. Rendering rotates the QR tokens on the cards and
 * revokes those students' sessions (DESIGN §7.4).
 */
class LoginCardController extends Controller
{
    public function __construct(private readonly LoginCardPrintService $prints) {}

    /** POST /api/v1/classrooms/{id}/login-cards -> 202 {data: print} */
    public function storeForClassroom(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = Classroom::query()
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->id)
            ->findOrFail($id);
        Gate::authorize('printLoginCards', $classroom);

        if (! $classroom->students()->exists()) {
            throw new ApiException('ห้องนี้ยังไม่มีนักเรียน', 'classroom_empty', 422);
        }

        $print = $this->prints->queueForClassroom($classroom, $teacher);

        return (new LoginCardPrintResource($print->refresh()))->response()->setStatusCode(202);
    }

    /** POST /api/v1/students/{id}/login-card -> 202 {data: print}; the old card stops working once rendered */
    public function storeForStudent(Request $request, int $id): JsonResponse
    {
        // Another school's student is a 404, like StudentPinController.
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($id);
        Gate::authorize('manageStudentCredentials', $student);

        $print = $this->prints->queueForStudent($student, $request->user());

        return (new LoginCardPrintResource($print->refresh()))->response()->setStatusCode(202);
    }

    /** GET /api/v1/login-card-prints/{id} -> {data: print} */
    public function show(Request $request, int $id): LoginCardPrintResource
    {
        $print = LoginCardPrint::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        Gate::authorize('view', $print);

        return new LoginCardPrintResource($print);
    }

    /** GET /api/v1/login-card-prints/{id}/file -> application/pdf | 409 print_not_ready */
    public function download(Request $request, int $id): StreamedResponse
    {
        $print = LoginCardPrint::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        Gate::authorize('download', $print);

        if (! $print->isReady() || ! Storage::disk('local')->exists($print->file_path)) {
            throw new ApiException('ไฟล์ยังไม่พร้อม', 'print_not_ready', 409);
        }

        $name = $print->classroom_id !== null
            ? 'login-cards-classroom-'.$print->classroom_id.'.pdf'
            : 'login-card-student-'.$print->student_id.'.pdf';

        return Storage::disk('local')->download($print->file_path, $name, ['Content-Type' => 'application/pdf']);
    }
}
