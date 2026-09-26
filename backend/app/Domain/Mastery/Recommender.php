<?php

namespace App\Domain\Mastery;

use App\Models\LearningResource;
use App\Models\Mastery;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Practice recommendations (DESIGN §14.1): the student's skills with
 * mastery < 0.75, weakest first; per skill up to 3 approved items of the
 * student's school not attempted by them in the last 7 days, plus the
 * skill's review links.
 */
final class Recommender
{
    public const ITEMS_PER_SKILL = 3;

    public const COOLDOWN_DAYS = 7;

    /**
     * @return list<array{mastery: Mastery, items: Collection<int, PracticeItem>, resources: Collection<int, LearningResource>}>
     */
    public function forStudent(User $student): array
    {
        $weak = Mastery::query()
            ->where('student_id', $student->id)
            ->where('value', '<', MasteryCalculator::WEAK_BELOW)
            ->with('skill')
            ->orderBy('value')
            ->orderBy('skill_id')
            ->get();
        if ($weak->isEmpty()) {
            return [];
        }
        $skillIds = $weak->pluck('skill_id')->all();

        $recentlyTried = PracticeAttempt::query()
            ->where('student_id', $student->id)
            ->where('created_at', '>=', now()->subDays(self::COOLDOWN_DAYS))
            ->pluck('practice_item_id')
            ->unique()
            ->all();

        $items = PracticeItem::query()
            ->where('school_id', $student->school_id)
            ->whereIn('skill_id', $skillIds)
            ->approved()
            ->whereNotIn('id', $recentlyTried)
            ->orderBy('id')
            ->get()
            ->groupBy('skill_id');

        $resources = LearningResource::query()
            ->where('school_id', $student->school_id)
            ->whereIn('skill_id', $skillIds)
            ->orderBy('id')
            ->get()
            ->groupBy('skill_id');

        $out = [];
        foreach ($weak as $mastery) {
            $out[] = [
                'mastery' => $mastery,
                'items' => ($items->get($mastery->skill_id) ?? collect())->take(self::ITEMS_PER_SKILL)->values(),
                'resources' => ($resources->get($mastery->skill_id) ?? collect())->values(),
            ];
        }

        return $out;
    }
}
