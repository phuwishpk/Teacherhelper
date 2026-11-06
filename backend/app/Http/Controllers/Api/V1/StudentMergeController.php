<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\SchoolStudents;
use App\Domain\Students\StudentMerger;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "รวมบัญชีนักเรียน" in the app (DESIGN §24.5): a teacher who is the
 * homeroom teacher of both accounts compares them, then merges. Students
 * outside the teacher's school are a 404; the merge rules live in
 * StudentMerger (admins merge in Filament with the same class).
 */
class StudentMergeController extends Controller
{
    public function __construct(
        private readonly StudentMerger $merger,
        private readonly SchoolStudents $students,
    ) {}

    /**
     * GET /students/merge-preview?keep_id=&merge_id= -> {data: {keep, merge,
     * conflicts: [{type, message}], can_merge}}; 422 merge_invalid, 403
     * not_homeroom_teacher.
     */
    public function preview(Request $request): JsonResponse
    {
        [$keep, $merge] = $this->pair($request, $request->query());

        return response()->json(['data' => $this->merger->preview($keep, $merge)]);
    }

    /**
     * POST /students/merge {keep_id, merge_id} -> {data: {merge_id, kept_student}};
     * 409 merge_conflict (errors.<type>[]), 422 merge_invalid, 403 not_homeroom_teacher.
     */
    public function store(Request $request): JsonResponse
    {
        [$keep, $merge] = $this->pair($request, $request->all());
        $record = $this->merger->merge($keep, $merge, $request->user());

        return response()->json(['data' => [
            'merge_id' => $record->id,
            'kept_student' => $this->students->payloads(collect([$keep->refresh()]))[0],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: User, 1: User}
     */
    private function pair(Request $request, array $input): array
    {
        $data = validator($input, [
            'keep_id' => ['required', 'integer', 'min:1', 'max:999999999999999999'],
            'merge_id' => ['required', 'integer', 'min:1', 'max:999999999999999999'],
        ], [
            'keep_id.required' => 'เลือกบัญชีที่จะเก็บไว้',
            'merge_id.required' => 'เลือกบัญชีที่จะรวม',
        ])->validate();

        $keep = StudentController::schoolStudent($request, (int) $data['keep_id']);
        $merge = StudentController::schoolStudent($request, (int) $data['merge_id']);
        StudentMerger::assertValid($keep, $merge);
        if (! Gate::allows('mergeStudents', [$keep, $merge])) {
            throw new ApiException('รวมได้เฉพาะครูประจำชั้นของทั้งสองบัญชี', 'not_homeroom_teacher', 403);
        }

        return [$keep, $merge];
    }
}
