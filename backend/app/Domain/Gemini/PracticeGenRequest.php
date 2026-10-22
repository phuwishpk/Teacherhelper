<?php

namespace App\Domain\Gemini;

use App\Models\Assignment;
use App\Models\Question;
use App\Models\School;
use App\Models\Skill;
use Illuminate\Support\Str;

/**
 * Input of the `practice_gen` prompt (DESIGN §10.6): the skill, its subject
 * and grade, up to MAX_EXAMPLES example questions of the school that test
 * it (text only, never images) and how many items to write.
 */
final readonly class PracticeGenRequest
{
    public const MAX_EXAMPLES = 5;

    public const EXAMPLE_LENGTH = 300;

    public const MIN_COUNT = 1;

    public const MAX_COUNT = 20;

    /**
     * @param  list<string>  $examples
     */
    public function __construct(
        public int $skillId,
        public int $schoolId,
        public int $teacherId,
        public int $count,
        public string $skillCode,
        public string $skillName,
        public string $subject,
        public string $gradeLabel,
        public array $examples,
    ) {}

    public static function forSkill(Skill $skill, School $school, int $teacherId, int $count): self
    {
        $skill->loadMissing('subject');
        $examples = Question::query()
            ->whereHas('skills', fn ($q) => $q->whereKey($skill->id))
            ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $school->id))
            ->orderByDesc('id')
            ->limit(self::MAX_EXAMPLES)
            ->pluck('prompt_text')
            ->map(fn (string $text) => Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), self::EXAMPLE_LENGTH, '…'))
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->all();

        return new self(
            skillId: $skill->id,
            schoolId: $school->id,
            teacherId: $teacherId,
            count: max(self::MIN_COUNT, min(self::MAX_COUNT, $count)),
            skillCode: $skill->code,
            skillName: $skill->name,
            subject: $skill->subject?->name ?? '-',
            gradeLabel: $skill->grade_level === null ? 'ไม่ระบุชั้น' : RubricDraftRequest::gradeLabel((int) $skill->grade_level),
            examples: $examples,
        );
    }
}
