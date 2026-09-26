<?php

namespace App\Domain\Practice;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Mastery\Recommender;
use App\Exceptions\ApiException;
use App\Models\Mastery;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /student/practice/{item_id}/attempts (DESIGN §9.7, §14.1): grades
 * the typed answer at once (PracticeGrader), stores the attempt, writes the
 * practice observation and recomputes the skill's mastery. One attempt per
 * item per COOLDOWN_DAYS, so repeating one item cannot pump mastery.
 */
final class PracticeAttempts
{
    public function __construct(private readonly MasteryCalculator $mastery) {}

    /**
     * @return array{0: PracticeAttempt, 1: Mastery|null}
     *
     * @throws ApiException 409 practice_already_attempted
     */
    public function attempt(User $student, PracticeItem $item, string $answer): array
    {
        return DB::transaction(function () use ($student, $item, $answer) {
            $repeated = PracticeAttempt::query()
                ->where('student_id', $student->id)
                ->where('practice_item_id', $item->id)
                ->where('created_at', '>=', now()->subDays(Recommender::COOLDOWN_DAYS))
                ->exists();
            if ($repeated) {
                throw new ApiException('ข้อนี้ทำไปแล้ว ลองข้ออื่นก่อน แล้วกลับมาทำข้อนี้ใหม่ได้ใน 7 วัน', 'practice_already_attempted', 409);
            }

            $attempt = PracticeAttempt::create([
                'practice_item_id' => $item->id,
                'student_id' => $student->id,
                'answer' => $answer,
                'score_ratio' => PracticeGrader::grade($item, $answer),
                'created_at' => now(),
            ]);
            $mastery = $this->mastery->recordPracticeAttempt($attempt, $item);

            return [$attempt, $mastery];
        });
    }
}
