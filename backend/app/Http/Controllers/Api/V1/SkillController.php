<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Skills\TeacherSkills;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SkillIndexRequest;
use App\Http\Requests\Api\V1\StoreSkillRequest;
use App\Http\Requests\Api\V1\UpdateSkillRequest;
use App\Http\Resources\SkillResource;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Skills (indicators) of the teacher's school (DESIGN §9.3, §20.2, §20.7):
 * the curriculum plus the school's own rows.
 *
 * - GET /skills?subject=&grade=&level=&q= cursor-paginated, or with
 *   tree=1 the matching skills with their ancestors as a tree;
 * - POST /skills: a teacher adds a missing indicator (source teacher);
 * - PATCH /skills/{id}: its creator edits it before any observation.
 */
class SkillController extends Controller
{
    public const PER_PAGE = 100;

    public function __construct(private readonly TeacherSkills $teacherSkills) {}

    public function index(SkillIndexRequest $request): AnonymousResourceCollection|JsonResponse
    {
        Gate::authorize('viewAny', Skill::class);
        $user = $request->user();

        $query = Skill::query()
            ->visibleToSchool($user->isAdmin() ? null : $user->school_id)
            ->orderBy('subject_id')
            ->orderBy('grade_level')
            ->orderBy('code')
            ->orderBy('id');

        $subject = $request->validated('subject');
        if ($subject !== null && $subject !== '') {
            $subjectId = ctype_digit((string) $subject)
                ? (int) $subject
                : Subject::query()->where('code', $subject)->value('id');
            $query->where('subject_id', $subjectId ?? 0);
        }

        $grade = $request->validated('grade');
        if ($grade !== null && $grade !== '') {
            $query->where('grade_level', (int) $grade);
        }

        $level = (string) $request->validated('level', '');
        if ($level !== '') {
            $query->whereIn('level', array_values(array_unique(explode(',', $level))));
        }

        $q = trim((string) $request->validated('q', ''));
        if ($q !== '') {
            // `!` as the LIKE escape character reads the same on MariaDB and SQLite
            // (a backslash literal does not), so % and _ in the search are literal.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn (Builder $b) => $b
                ->whereRaw("code like ? escape '!'", [$like])
                ->orWhereRaw("name like ? escape '!'", [$like]));
        }

        if ($request->boolean('tree')) {
            return response()->json(['data' => self::tree($query->get())]);
        }

        return SkillResource::collection($query->cursorPaginate(self::PER_PAGE));
    }

    /** POST /api/v1/skills -> 201 {data: skill} (source teacher, level from the parent). */
    public function store(StoreSkillRequest $request): JsonResponse
    {
        Gate::authorize('create', Skill::class);
        $skill = $this->teacherSkills->create($request->user(), $request->validated());

        return (new SkillResource($skill))->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/skills/{id} {name?, code?, grade_level?} -> {data: skill} */
    public function update(UpdateSkillRequest $request, int $id): SkillResource
    {
        $skill = Skill::query()->visibleToSchool($request->user()->school_id)->findOrFail($id);
        Gate::authorize('update', $skill);

        return new SkillResource($this->teacherSkills->update($skill, $request->validated()));
    }

    /**
     * The matching skills and every ancestor of them, nested:
     * [{...skill, children: [...]}], each level ordered by code (natural).
     *
     * @param  Collection<int, Skill>  $matched
     * @return list<array<string, mixed>>
     */
    private static function tree(Collection $matched): array
    {
        /** @var array<int, Skill> $byId */
        $byId = $matched->keyBy('id')->all();
        for ($depth = 0; $depth < 5; $depth++) {
            $missing = [];
            foreach ($byId as $skill) {
                if ($skill->parent_id !== null && ! isset($byId[$skill->parent_id])) {
                    $missing[$skill->parent_id] = true;
                }
            }
            if ($missing === []) {
                break;
            }
            foreach (Skill::query()->whereIn('id', array_keys($missing))->get() as $parent) {
                $byId[$parent->id] = $parent;
            }
        }

        $children = [];
        $roots = [];
        foreach ($byId as $skill) {
            if ($skill->parent_id !== null && isset($byId[$skill->parent_id])) {
                $children[$skill->parent_id][] = $skill;
            } else {
                $roots[] = $skill;
            }
        }

        $build = function (array $skills) use (&$build, $children): array {
            usort($skills, fn (Skill $a, Skill $b) => strnatcmp($a->code, $b->code) ?: $a->id <=> $b->id);

            return array_map(fn (Skill $skill) => (new SkillResource($skill))->resolve() + [
                'children' => $build($children[$skill->id] ?? []),
            ], $skills);
        };

        return $build($roots);
    }
}
