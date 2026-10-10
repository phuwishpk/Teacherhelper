<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubjectResource;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Subject groups (DESIGN §29.4). GET /api/v1/subjects lists the shared ones
 * (the eight learning areas) and the teacher's own; a teacher adds, renames
 * and deletes only their own.
 */
class SubjectController extends Controller
{
    /** Own subject groups per teacher. */
    public const MAX_OWN = 30;

    /** GET /api/v1/subjects -> {data: [{id, code, name, is_own}]}, shared ones first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Skill::class);

        return SubjectResource::collection(
            Subject::query()->visibleTo($request->user())
                ->orderByRaw('case when owner_user_id is null then 0 else 1 end')
                ->orderBy('code')
                ->get()
        );
    }

    /** POST /api/v1/subjects {name} -> 201 {data: subject} */
    public function store(Request $request): JsonResponse
    {
        $teacher = self::teacher($request);
        $name = self::name($request);

        $subject = DB::transaction(function () use ($teacher, $name) {
            $own = Subject::query()->where('owner_user_id', $teacher->id)->lockForUpdate()->get(['id', 'code']);
            if ($own->count() >= self::MAX_OWN) {
                throw new ApiException('เพิ่มกลุ่มสาระของตัวเองได้ไม่เกิน '.self::MAX_OWN.' กลุ่ม', 'subject_limit', 422);
            }
            self::assertNameFree($teacher, $name);
            // The code is generated: `code` is unique across every teacher.
            $next = $own->map(fn (Subject $s) => (int) substr(strrchr($s->code, '-') ?: '-0', 1))->max() + 1;
            for ($attempt = 0; ; $attempt++) {
                try {
                    return Subject::create(['code' => 'T'.$teacher->id.'-'.($next + $attempt), 'name' => $name, 'owner_user_id' => $teacher->id]);
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt >= 5) {
                        throw $e;
                    }
                }
            }
        });

        return (new SubjectResource($subject))->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/subjects/{id} {name} -> {data: subject} */
    public function update(Request $request, int $id): SubjectResource
    {
        $teacher = self::teacher($request);
        $subject = Subject::query()->where('owner_user_id', $teacher->id)->findOrFail($id);
        $name = self::name($request);
        self::assertNameFree($teacher, $name, $subject->id);
        $subject->update(['name' => $name]);

        return new SubjectResource($subject);
    }

    /** DELETE /api/v1/subjects/{id} -> 204, or 409 subject_in_use */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $teacher = self::teacher($request);
        $subject = Subject::query()->where('owner_user_id', $teacher->id)->findOrFail($id);
        $used = Course::query()->where('subject_id', $subject->id)->exists()
            || Assignment::query()->where('subject_id', $subject->id)->exists()
            || Skill::query()->where('subject_id', $subject->id)->exists();
        if ($used) {
            throw new ApiException('ลบไม่ได้ เพราะมีรายวิชาหรือการบ้านใช้กลุ่มสาระนี้อยู่', 'subject_in_use', 409);
        }
        $subject->delete();

        return response()->json(null, 204);
    }

    private static function teacher(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isTeacher() && $user->isActive(), 403);

        return $user;
    }

    private static function name(Request $request): string
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:100']],
            ['name.required' => 'กรอกชื่อกลุ่มสาระ', 'name.max' => 'ชื่อกลุ่มสาระยาวเกิน 100 ตัวอักษร'],
        );

        return trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
    }

    private static function assertNameFree(User $teacher, string $name, ?int $exceptId = null): void
    {
        $taken = Subject::query()->visibleTo($teacher)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            $message = 'มีกลุ่มสาระชื่อนี้อยู่แล้ว';
            throw new ApiException($message, 'validation_failed', 422, ['name' => [$message]]);
        }
    }
}
