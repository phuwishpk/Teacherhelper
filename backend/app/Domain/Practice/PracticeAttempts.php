<?php

namespace App\Domain\Practice;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Mastery\Recommender;
use App\Exceptions\ApiException;
use App\Models\Mastery;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * POST /student/practice/{item_id}/attempts (DESIGN §9.7, §14.1): grades
 * the typed answer at once (PracticeGrader), stores the attempt, writes the
 * practice observation and recomputes the skill's mastery. One attempt per
 * item per COOLDOWN_DAYS, so repeating one item cannot pump mastery. The
 * repeat check and the insert run under a cache lock per (student, item)
 * (cache_locks of the database cache store on Plesk), so two taps sent at
 * the same moment cannot both pass the check: the second waits for the
 * first and is then refused like any repeat.
 */
final class PracticeAttempts
{
    /** Longest an attempt may hold its lock; a request that died frees it by then. */
    public const LOCK_SECONDS = 5;

    /** How long a concurrent attempt waits for the lock before it is refused. */
    public const LOCK_WAIT_SECONDS = 2;

    public function __construct(private readonly MasteryCalculator $mastery) {}

    /**
     * @return array{0: PracticeAttempt, 1: Mastery|null}
     *
     * @throws ApiException 409 practice_already_attempted
     */
    public function attempt(User $student, PracticeItem $item, string $answer): array
    {
        try {
            return Cache::lock(self::lockKey($student->id, $item->id), self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->store($student, $item, $answer));
        } catch (LockTimeoutException) {
            throw self::alreadyAttempted();
        }
    }

    public static function lockKey(int $studentId, int $itemId): string
    {
        return "practice_attempt:{$studentId}:{$itemId}";
    }

    /**
     * @return array{0: PracticeAttempt, 1: Mastery|null}
     */
    private function store(User $student, PracticeItem $item, string $answer): array
    {
        return DB::transaction(function () use ($student, $item, $answer) {
            $repeated = PracticeAttempt::query()
                ->where('student_id', $student->id)
                ->where('practice_item_id', $item->id)
                ->where('created_at', '>=', now()->subDays(Recommender::COOLDOWN_DAYS))
                ->exists();
            if ($repeated) {
                throw self::alreadyAttempted();
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

    private static function alreadyAttempted(): ApiException
    {
        return new ApiException('ข้อนี้ทำไปแล้ว ลองข้ออื่นก่อน แล้วกลับมาทำข้อนี้ใหม่ได้ใน 7 วัน', 'practice_already_attempted', 409);
    }
}
