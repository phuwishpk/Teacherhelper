<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLearningResourceRequest;
use App\Http\Resources\LearningResourceResource;
use App\Models\LearningResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Review links of a skill (DESIGN §9.6, §14.1), per school.
 */
class LearningResourceController extends Controller
{
    /** GET /api/v1/skills/{id}/resources -> {data: [resource]} */
    public function index(Request $request, int $skillId): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LearningResource::class);
        $skill = self::skill($request, $skillId);

        return LearningResourceResource::collection(
            LearningResource::query()
                ->where('school_id', $request->user()->school_id)
                ->where('skill_id', $skill->id)
                ->orderBy('id')
                ->get(),
        );
    }

    /** POST /api/v1/skills/{id}/resources {title, url} -> 201 {data: resource} */
    public function store(StoreLearningResourceRequest $request, int $skillId): JsonResponse
    {
        Gate::authorize('create', LearningResource::class);
        $skill = self::skill($request, $skillId);

        $resource = LearningResource::create([
            'school_id' => $request->user()->school_id,
            'skill_id' => $skill->id,
            'title' => trim((string) $request->validated('title')),
            'url' => trim((string) $request->validated('url')),
            'added_by' => $request->user()->id,
        ]);

        return (new LearningResourceResource($resource))->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/resources/{id} {title?, url?} -> {data: resource} */
    public function update(StoreLearningResourceRequest $request, int $id): LearningResourceResource
    {
        $resource = self::find($request, $id);
        Gate::authorize('update', $resource);

        $data = $request->validated();
        if (array_key_exists('title', $data)) {
            $resource->title = trim((string) $data['title']);
        }
        if (array_key_exists('url', $data)) {
            $resource->url = trim((string) $data['url']);
        }
        $resource->save();

        return new LearningResourceResource($resource);
    }

    /** DELETE /api/v1/resources/{id} -> 204 */
    public function destroy(Request $request, int $id): Response
    {
        $resource = self::find($request, $id);
        Gate::authorize('delete', $resource);

        $resource->delete();

        return response()->noContent();
    }

    private static function skill(Request $request, int $skillId): Skill
    {
        $skill = Skill::query()->visibleToSchool($request->user()->school_id)->findOrFail($skillId);
        Gate::authorize('view', $skill);

        return $skill;
    }

    private static function find(Request $request, int $id): LearningResource
    {
        return LearningResource::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
    }
}
