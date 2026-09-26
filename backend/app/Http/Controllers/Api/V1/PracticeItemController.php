<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Practice\PracticeBank;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GeneratePracticeItemsRequest;
use App\Http\Requests\Api\V1\PracticeItemIndexRequest;
use App\Http\Requests\Api\V1\StorePracticeItemRequest;
use App\Http\Resources\PracticeItemResource;
use App\Jobs\GeneratePracticeItemsJob;
use App\Models\PracticeItem;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The school's practice bank for teachers (DESIGN §9.6, §14.1). Items of
 * other schools do not exist for a teacher (404).
 */
class PracticeItemController extends Controller
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly PracticeBank $bank,
        private readonly GeminiKeyResolver $keys,
    ) {}

    /**
     * GET /api/v1/practice-items?skill=&status=&cursor= -> cursor-paginated
     * PracticeItemResource, newest first.
     */
    public function index(PracticeItemIndexRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PracticeItem::class);

        $query = self::schoolQuery($request)->with('skill')->orderByDesc('id');
        if ($request->filled('skill')) {
            $query->where('skill_id', (int) $request->validated('skill'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        return PracticeItemResource::collection($query->cursorPaginate(self::PER_PAGE));
    }

    /**
     * POST /api/v1/practice-items {skill_id, answer_type, prompt_text,
     * options?, answer_key, explanation, status?} -> 201 {data: item}
     * (source teacher). 422 skill_id when the skill is not visible to the school.
     */
    public function store(StorePracticeItemRequest $request): JsonResponse
    {
        Gate::authorize('create', PracticeItem::class);

        $skill = self::visibleSkill($request, (int) $request->validated('skill_id'));
        $item = $this->bank->create($request->user(), $skill, $request->all());

        return (new PracticeItemResource($item->load('skill')))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/v1/practice-items/{id} {status?, prompt_text?, options?,
     * answer_key?, explanation?, answer_type?} -> {data: item}. Approving
     * needs a teacher of the skill's subject (403 subject_not_taught).
     */
    public function update(Request $request, int $id): PracticeItemResource
    {
        $item = self::schoolQuery($request)->findOrFail($id);
        Gate::authorize('update', $item);

        $updated = $this->bank->update($request->user(), $item, $request->all());

        return new PracticeItemResource($updated->load('skill'));
    }

    /**
     * POST /api/v1/skills/{id}/practice-items/generate {count?} -> 202
     * {data: {queued: true, count}}: GeneratePracticeItemsJob asks Gemini with
     * the teacher's key and stores the items as drafts. Without any Gemini
     * key (teacher or server, DESIGN §10.1) nothing is queued: 422
     * ai_key_missing, the same answer as the other AI endpoints, so the app
     * can send the teacher to the key settings instead of waiting for
     * drafts that never come.
     */
    public function generate(GeneratePracticeItemsRequest $request, int $id): JsonResponse
    {
        Gate::authorize('generate', PracticeItem::class);
        $skill = Skill::query()->visibleToSchool($request->user()->school_id)->findOrFail($id);
        Gate::authorize('view', $skill);

        if ($this->keys->forTeacher($request->user()->id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        $count = $request->count();
        GeneratePracticeItemsJob::dispatch($skill->id, (int) $request->user()->school_id, $request->user()->id, $count);

        return response()->json(['data' => ['queued' => true, 'skill_id' => $skill->id, 'count' => $count]], 202);
    }

    /**
     * @return Builder<PracticeItem>
     */
    private static function schoolQuery(Request $request)
    {
        return PracticeItem::query()->where('school_id', $request->user()->school_id);
    }

    private static function visibleSkill(Request $request, int $skillId): Skill
    {
        $skill = Skill::query()->visibleToSchool($request->user()->school_id)->find($skillId);
        if ($skill === null) {
            throw ValidationException::withMessages(['skill_id' => ['ไม่พบทักษะนี้']]);
        }

        return $skill;
    }
}
