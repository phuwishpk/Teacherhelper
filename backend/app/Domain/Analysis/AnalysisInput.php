<?php

namespace App\Domain\Analysis;

use App\Domain\Mastery\MasteryCalculator;
use App\Models\Skill;

/**
 * What the analysis of one (student, classroom) rests on (DESIGN §20.5):
 * the student's mastery of the classroom's indicators that are assessed
 * (n_obs ≥ 1), weakest first by the ranking rule of §14.2 (value, then
 * skill id; n_obs < 2 is labelled, never moved to the end).
 *
 * The code-computed strengths and areas, and the hash that says whether
 * the texts must be written again, come from here. What goes to Gemini
 * (promptIndicators) carries no student data: codes, names and numbers only.
 */
final readonly class AnalysisInput
{
    public const TOP = 3;

    /**
     * @param  list<array{skill: Skill, value: float, n_obs: int, practice_items: int}>  $entries  weakest first
     */
    public function __construct(
        public int $studentId,
        public int $classroomId,
        public int $gradeLevel,
        public string $subject,
        public array $entries,
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The first TOP indicators below 0.75, weakest first.
     *
     * @return list<array{skill_id: int, value: float, n_obs: int}>
     */
    public function areas(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry['value'] < MasteryCalculator::WEAK_BELOW && count($out) < self::TOP) {
                $out[] = self::item($entry);
            }
        }

        return $out;
    }

    /**
     * The last TOP indicators at 0.75 or above, strongest first.
     *
     * @return list<array{skill_id: int, value: float, n_obs: int}>
     */
    public function strengths(): array
    {
        $strong = array_values(array_filter($this->entries, fn (array $e) => $e['value'] >= MasteryCalculator::WEAK_BELOW));

        return array_map(fn (array $e) => self::item($e), array_reverse(array_slice($strong, -self::TOP)));
    }

    /**
     * SHA-256 of the mastery input (skill, value, n_obs). Grade, subject and
     * practice counts only shape the wording, so they do not ask for new
     * texts on their own.
     */
    public function hash(): string
    {
        $rows = array_map(fn (array $e) => [$e['skill']->id, sprintf('%.3f', $e['value']), $e['n_obs']], $this->entries);
        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return hash('sha256', (string) json_encode($rows));
    }

    /**
     * The indicators as Gemini reads them: {code, name, mastery (0–100),
     * n_obs, practice_items}. Never a student's name, number or id.
     *
     * @return list<array{code: string, name: string, mastery: int, n_obs: int, practice_items: int}>
     */
    public function promptIndicators(): array
    {
        return array_map(fn (array $e) => [
            'code' => $e['skill']->code,
            'name' => trim((string) $e['skill']->name),
            'mastery' => (int) round($e['value'] * 100),
            'n_obs' => $e['n_obs'],
            'practice_items' => $e['practice_items'],
        ], $this->entries);
    }

    /** @return array<int, Skill> by id */
    public function skills(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $out[$entry['skill']->id] = $entry['skill'];
        }

        return $out;
    }

    /**
     * @param  array{skill: Skill, value: float, n_obs: int, practice_items: int}  $entry
     * @return array{skill_id: int, value: float, n_obs: int}
     */
    private static function item(array $entry): array
    {
        return ['skill_id' => $entry['skill']->id, 'value' => $entry['value'], 'n_obs' => $entry['n_obs']];
    }
}
