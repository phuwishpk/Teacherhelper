<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubjectResource;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/subjects -> {data: [{id, code, name}]}: the subjects the skill
 * picker filters by. Companion of GET /skills (not listed separately in DESIGN §9.3).
 */
class SubjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Skill::class);

        return SubjectResource::collection(Subject::query()->orderBy('code')->get());
    }
}
