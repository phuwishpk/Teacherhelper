<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SkillIndexRequest;
use App\Http\Resources\SkillResource;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/skills?subject=&grade=&q= (DESIGN §9.3): curriculum skills plus
 * the sub-skills of the teacher's school, cursor-paginated.
 */
class SkillController extends Controller
{
    public const PER_PAGE = 100;

    public function index(SkillIndexRequest $request): AnonymousResourceCollection
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

        $q = trim((string) $request->validated('q', ''));
        if ($q !== '') {
            // `!` as the LIKE escape character reads the same on MariaDB and SQLite
            // (a backslash literal does not), so % and _ in the search are literal.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn (Builder $b) => $b
                ->whereRaw("code like ? escape '!'", [$like])
                ->orWhereRaw("name like ? escape '!'", [$like]));
        }

        return SkillResource::collection($query->cursorPaginate(self::PER_PAGE));
    }
}
