<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Mastery\Recommender;
use App\Domain\Practice\PracticeAttempts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PracticeAttemptRequest;
use App\Http\Resources\LearningResourceResource;
use App\Http\Resources\MasteryResource;
use App\Http\Resources\PracticeItemResource;
use App\Http\Resources\SkillResource;
use App\Models\LearningResource;
use App\Models\PracticeItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's practice (DESIGN §9.7, §14.1). Items carry no answer key
 * and no explanation until an attempt is graded.
 */
class StudentPracticeController extends Controller
{
    public function __construct(
        private readonly Recommender $recommender,
        private readonly PracticeAttempts $attempts,
    ) {}

    /**
     * GET /api/v1/student/practice -> {data: [{skill, mastery, items, resources}],
     * meta: {weak_below, items_per_skill, cooldown_days}}: weak skills first.
     */
    public function index(Request $request): JsonResponse
    {
        $groups = [];
        foreach ($this->recommender->forStudent($request->user()) as $group) {
            $mastery = $group['mastery'];
            $groups[] = [
                'skill' => $mastery->skill === null ? null : SkillResource::summary($mastery->skill),
                'skill_id' => $mastery->skill_id,
                'mastery' => MasteryResource::row($mastery),
                'items' => $group['items']->map(fn (PracticeItem $item) => PracticeItemResource::forStudent($item))->all(),
                'resources' => $group['resources']->map(fn (LearningResource $r) => (new LearningResourceResource($r))->resolve($request))->all(),
            ];
        }

        return response()->json([
            'data' => $groups,
            'meta' => [
                'weak_below' => MasteryCalculator::WEAK_BELOW,
                'items_per_skill' => Recommender::ITEMS_PER_SKILL,
                'cooldown_days' => Recommender::COOLDOWN_DAYS,
            ],
        ]);
    }

    /**
     * POST /api/v1/student/practice/{item_id}/attempts {answer} -> 201
     * {data: {attempt_id, item_id, score_ratio, correct, explanation,
     * mastery}}. explanation is the item's worked answer, sent when the
     * answer was not fully right (§14.1). 409 practice_already_attempted
     * within 7 days; unknown, unapproved or other-school items are 404.
     */
    public function attempt(PracticeAttemptRequest $request, int $itemId): JsonResponse
    {
        $student = $request->user();
        $item = PracticeItem::query()
            ->where('school_id', $student->school_id)
            ->approved()
            ->with('skill')
            ->findOrFail($itemId);

        [$attempt, $mastery] = $this->attempts->attempt($student, $item, (string) $request->validated('answer'));
        $correct = $attempt->score_ratio >= 0.999;
        if ($mastery !== null) {
            $mastery->setRelation('skill', $item->skill);
        }

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'item_id' => $item->id,
            'score_ratio' => (float) $attempt->score_ratio,
            'correct' => $correct,
            'explanation' => $correct ? null : $item->explanation,
            'mastery' => $mastery === null ? null : MasteryResource::row($mastery),
        ]], 201);
    }
}
