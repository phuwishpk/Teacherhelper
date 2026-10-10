<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\RosterCopier;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\StudentUsernames;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkStoreStudentsRequest;
use App\Http\Requests\Api\V1\StudentsFromClassroomRequest;
use App\Http\Resources\RosterStudentResource;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use App\Models\GradebookSpecialGrade;
use App\Models\StudentAnalysis;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roster of a classroom (DESIGN §9.2, §24.4): add new or existing students,
 * list, change a student number, take a student out, and the PINs of
 * students the background roster sync added (§19.2). Homeroom teacher only;
 * a subject teacher reads the roster and gets 403 not_homeroom_teacher on the rest (§24.8).
 */
class ClassroomStudentController extends Controller
{
    public function __construct(
        private readonly StudentEnroller $enroller,
        private readonly CredentialIssuer $issuer,
    ) {}

    /**
     * POST /api/v1/classrooms/{id}/students {students: [{name, student_number,
     * student_code?} | {student_id, student_number, reissue_pin?}]}
     * -> 201 {data: [{student_id, student_number, name, student_code, status, pin, existing}]}
     *
     * The PIN of a new student (or a reissued one) is returned exactly once
     * (only its hash is stored); the teacher reads it out to the student. An
     * existing student keeps their PIN and QR card: pin = null, existing =
     * true (DESIGN §24.4). The QR card comes from /login-cards.
     * StudentEnroller answers 422 student_number_taken, student_code_taken,
     * already_enrolled or student_not_in_school, inside the transaction.
     */
    public function store(BulkStoreStudentsRequest $request, int $id): JsonResponse
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);

        $created = $this->enroller->enroll($classroom, $request->validated('students'));

        return response()->json([
            'data' => array_map(fn (array $row) => [
                'student_id' => $row['student']->id,
                'student_number' => $row['student_number'],
                'name' => $row['student']->name,
                'student_code' => $row['student']->student_code,
                'status' => $row['student']->status,
                'username' => $row['student']->username,
                'pin' => $row['pin'],
                'existing' => $row['existing'],
            ], $created),
        ], 201);
    }

    /**
     * POST /api/v1/classrooms/{id}/students/from-classroom {source_classroom_id,
     * student_ids[], numbering: keep|sorted, pin: keep|new} -> 201 {data:
     * {enrolled: [{student_id, student_number, name, student_code, status,
     * pin, existing}], skipped: [{student_id, name, reason:
     * already_enrolled|not_active}]}} ("นำนักเรียนจากห้องเดิม", DESIGN §24.6,
     * RosterCopier). The source is any classroom of the school (404 otherwise);
     * 422 for an id not in it, the classroom itself, or numbers above 255.
     */
    public function fromClassroom(StudentsFromClassroomRequest $request, RosterCopier $copier, int $id): JsonResponse
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);
        $source = Classroom::query()->where('school_id', $classroom->school_id)->findOrFail((int) $request->validated('source_classroom_id'));

        $result = $copier->copy($classroom, $source, $request->studentIds(), (string) $request->validated('numbering'), (string) $request->validated('pin'));

        return response()->json(['data' => [
            'enrolled' => array_map(fn (array $row) => [
                'student_id' => $row['student']->id,
                'student_number' => $row['student_number'],
                'name' => $row['student']->name,
                'student_code' => $row['student']->student_code,
                'status' => $row['student']->status,
                'username' => $row['student']->username,
                'pin' => $row['pin'],
                'existing' => $row['existing'],
            ], $result['enrolled']),
            'skipped' => $result['skipped'],
        ]], 201);
    }

    /**
     * PATCH /api/v1/classrooms/{id}/students/{student_id} {student_number?, username?}
     * -> {data: roster row}; 422 student_number_taken (DESIGN §24.4), 422
     * errors.username when the sign-in name is taken (DESIGN §29.10).
     */
    public function update(Request $request, int $id, int $studentId): RosterStudentResource
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);
        $student = $classroom->students()->findOrFail($studentId);
        $data = $request->validate([
            'student_number' => ['required_without:username', 'integer', 'min:1', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:40'],
        ], [
            'student_number.required_without' => 'กรุณากรอกเลขที่',
            'username.required' => 'กรุณากรอกชื่อผู้ใช้',
            'student_number.required' => 'กรุณากรอกเลขที่',
            'student_number.integer' => 'เลขที่ต้องเป็นตัวเลข',
            'student_number.min' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
            'student_number.max' => 'เลขที่ต้องอยู่ระหว่าง 1–255',
        ]);
        if (array_key_exists('username', $data)) {
            $this->rename($student, $data['username']);
        }
        if (! array_key_exists('student_number', $data)) {
            return new RosterStudentResource($classroom->students()->findOrFail($studentId));
        }
        $number = (int) $data['student_number'];

        try {
            DB::transaction(function () use ($classroom, $student, $number) {
                Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
                $taken = $classroom->students()->wherePivot('student_number', $number)->whereKeyNot($student->id)->exists();
                if ($taken) {
                    throw StudentEnroller::numbersTaken([$number]);
                }
                $classroom->students()->updateExistingPivot($student->id, ['student_number' => $number]);
            });
        } catch (UniqueConstraintViolationException) {
            throw StudentEnroller::numbersTaken([$number]);
        }

        return new RosterStudentResource($classroom->students()->findOrFail($studentId));
    }

    /** Sets the student's sign-in name; their sessions stay. */
    private function rename(User $student, string $username): void
    {
        $username = StudentUsernames::normalize($username);
        $message = match (true) {
            ! StudentUsernames::valid($username) => 'ชื่อผู้ใช้ต้องยาว 3-40 ตัว ใช้ได้เฉพาะ a-z 0-9 จุด ขีด และขีดล่าง',
            StudentUsernames::taken($username, $student->id) => 'มีคนใช้ชื่อผู้ใช้นี้แล้ว',
            default => null,
        };
        if ($message !== null) {
            throw new ApiException($message, 'validation_failed', 422, ['username' => [$message]]);
        }
        try {
            $student->forceFill(['username' => $username])->save();
        } catch (UniqueConstraintViolationException) {
            $message = 'มีคนใช้ชื่อผู้ใช้นี้แล้ว';

            throw new ApiException($message, 'validation_failed', 422, ['username' => [$message]]);
        }
    }

    /**
     * DELETE /api/v1/classrooms/{id}/students/{student_id} -> 204: the student
     * leaves the classroom, the account stays. 409 student_has_data while
     * they have a submission or a gradebook score in this classroom.
     */
    public function destroy(Request $request, int $id, int $studentId): Response
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);
        $student = $classroom->students()->findOrFail($studentId);

        DB::transaction(function () use ($classroom, $student) {
            $hasData = Submission::query()
                ->where('student_id', $student->id)
                ->whereIn('assignment_id', Assignment::query()->select('id')->where('classroom_id', $classroom->id))
                ->exists()
                || GradebookEntry::query()->where('classroom_id', $classroom->id)->where('student_id', $student->id)
                    ->where(fn ($q) => $q->whereNotNull('score')->orWhere('excused', true))->exists()
                || GradebookSpecialGrade::query()->where('classroom_id', $classroom->id)->where('student_id', $student->id)->exists()
                || GradebookPublishedGrade::query()->where('student_id', $student->id)
                    ->whereIn('publication_id', GradebookPublication::query()->select('id')->where('classroom_id', $classroom->id))->exists();
            if ($hasData) {
                throw new ApiException('นักเรียนคนนี้มีงานหรือคะแนนในห้องนี้แล้ว เอาออกจากห้องไม่ได้', 'student_has_data', 409);
            }
            // Cleared cells of the gradebook carry nothing.
            GradebookEntry::query()->where('classroom_id', $classroom->id)->where('student_id', $student->id)->delete();
            StudentAnalysis::query()->where('classroom_id', $classroom->id)->where('student_id', $student->id)->delete();
            $classroom->students()->detach($student->id);
        });

        return response()->noContent();
    }

    /**
     * GET /api/v1/classrooms/{id}/roster -> {data: [roster row]}
     * Not paginated: the app caches the whole roster for offline scanning.
     * A subject teacher reads it without the PIN and Google state (DESIGN
     * §24.8: pin_pending and left_course_at are null).
     */
    public function index(Request $request, int $id): AnonymousResourceCollection
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('viewRoster', $classroom);
        $request->attributes->set(RosterStudentResource::LIMITED, ! ClassroomAccess::homeroomOf($request->user(), $classroom));

        return RosterStudentResource::collection($classroom->students()->with('googleIdentity')->get());
    }

    /**
     * POST /api/v1/classrooms/{id}/students/pending-pins -> {data:
     * [{student_id, student_number, name, pin}]}: the first PINs of the
     * students the background roster sync added (roster `pin_pending`,
     * DESIGN §19.2), which nobody has seen. Each gets a new PIN, shown once,
     * and stops being pending. An empty list when nobody is pending.
     */
    public function pendingPins(Request $request, int $id): JsonResponse
    {
        $classroom = $this->ownClassroom($request, $id);
        Gate::authorize('manageStudents', $classroom);

        $rows = DB::transaction(function () use ($classroom) {
            $pending = $classroom->students()->wherePivotNotNull('pin_pending_at')->lockForUpdate()->get();

            return $pending->map(fn (User $student) => [
                'student_id' => $student->id,
                'student_number' => (int) $student->pivot->student_number,
                'name' => $student->name,
                'username' => $student->username,
                'pin' => $this->issuer->issuePin($student),
            ])->values()->all();
        });

        return response()->json(['data' => $rows]);
    }

    /** A classroom the teacher sees (homeroom or subject, else 404); the policy decides the rest. */
    private function ownClassroom(Request $request, int $id): Classroom
    {
        return ClassroomAccess::classrooms($request->user())->findOrFail($id);
    }
}
