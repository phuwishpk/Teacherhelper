<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Skills\SkillTrash;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkillResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The trash of a teacher's own indicators (DESIGN §34.1):
 *
 * - GET /skills/trash -> {data: [{...skill, uses: {what: count}}]};
 * - DELETE /skills/{id} -> 204, into the trash (with the sub-indicators under it);
 * - POST /skills/{id}/restore -> {data: skill} (409 parent_in_trash);
 * - DELETE /skills/{id}/permanent -> 204 (409 not_in_trash, 409 skill_in_use with `uses`).
 *
 * Only the teacher who added the indicator; anybody else gets 404 or 403.
 */
class SkillTrashController extends Controller
{
    public function __construct(private readonly SkillTrash $trash) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->trash->list($request->user())
            ->map(fn (array $row) => (new SkillResource($row['skill']))->resolve() + ['uses' => (object) $row['uses']])
            ->values()]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->trash->trash($request->user(), self::own($request, $id));

        return response()->noContent();
    }

    public function restore(Request $request, int $id): SkillResource
    {
        $skill = self::own($request, $id);
        $this->trash->restore($request->user(), $skill);

        return new SkillResource($skill->refresh());
    }

    public function purge(Request $request, int $id): Response
    {
        $this->trash->purge($request->user(), self::own($request, $id));

        return response()->noContent();
    }

    private static function own(Request $request, int $id): Skill
    {
        $skill = Skill::query()
            ->where('school_id', $request->user()->school_id)
            ->where('source', Skill::SOURCE_TEACHER)
            ->findOrFail($id);
        Gate::authorize('trash', $skill);

        return $skill;
    }
}
