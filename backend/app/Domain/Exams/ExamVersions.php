<?php

namespace App\Domain\Exams;

use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\ExamVersion;
use App\Models\Question;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The shuffled versions of an exam (DESIGN §22.5).
 *
 * - Version 1 (ก) is the original order (identity permutation).
 * - Other versions shuffle the questions within each section only (sections
 *   keep their order, numbers run on) and the options of each mcq question
 *   unless it is "ห้ามสลับตัวเลือก"; true_false and numeric keep theirs.
 * - The shuffle is deterministic: the seed is the first 8 bytes of
 *   SHA-256("{assignment_id}|{version_no}|{shuffle_nonce}") driving PHP's
 *   Randomizer with Mt19937, and the permutation is stored in
 *   exam_versions so a printed version reads back the same later.
 * - Rows are rebuilt whenever the structure (sections, questions, option
 *   counts, locks) or the nonce changed while the structure is unlocked;
 *   once locked (printed) they are never rewritten.
 * - Keys of every version are derived from the master key through the
 *   permutation; they are never stored.
 */
final class ExamVersions
{
    /** Version labels in Thai consonant order (ก ข ค ง, then จ ฉ ช … if the maximum grows). */
    public const LABELS = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ'];

    public static function label(int $versionNo): string
    {
        return self::LABELS[$versionNo - 1] ?? (string) $versionNo;
    }

    public static function maxVersions(): int
    {
        return (int) config('eduvision.exams.max_versions');
    }

    /** Hex of the first 8 bytes of SHA-256("{assignment_id}|{version_no}|{shuffle_nonce}"). */
    public static function seed(int $assignmentId, int $versionNo, int $nonce): string
    {
        return substr(hash('sha256', "{$assignmentId}|{$versionNo}|{$nonce}"), 0, 16);
    }

    /**
     * Hash of what the shuffle depends on: sections in order with their type
     * and option count, their questions in order with the option lock.
     *
     * @param  Collection<int, ExamSection>  $sections  in position order
     * @param  Collection<int, Question>  $questions  in position order
     */
    public static function structureHash(Collection $sections, Collection $questions): string
    {
        $bySection = $questions->groupBy('section_id');
        $shape = $sections->map(fn (ExamSection $s) => [
            $s->id,
            $s->type,
            $s->choiceCount(),
            ($bySection->get($s->id) ?? collect())->map(fn (Question $q) => [$q->id, (bool) $q->lock_options])->values()->all(),
        ])->values()->all();

        return hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR));
    }

    /**
     * The permutation of one version.
     *
     * @param  Collection<int, ExamSection>  $sections  in position order
     * @param  Collection<int, Question>  $questions  in position order
     * @return array{question_order: list<int>, option_orders: array<int, list<int>>}
     */
    public static function permutation(Collection $sections, Collection $questions, int $versionNo, string $seed): array
    {
        $bySection = $questions->groupBy('section_id');
        if ($versionNo === 1) {
            return ['question_order' => $questions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), 'option_orders' => []];
        }

        $seedBytes = hex2bin($seed);
        $randomizer = new Randomizer(new Mt19937(unpack('J', $seedBytes === false ? str_repeat("\0", 8) : $seedBytes)[1]));
        $order = [];
        foreach ($sections as $section) {
            $ids = ($bySection->get($section->id) ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            array_push($order, ...(count($ids) > 1 ? $randomizer->shuffleArray($ids) : $ids));
        }

        $sectionsById = $sections->keyBy('id');
        $optionOrders = [];
        foreach ($questions as $question) {
            $section = $sectionsById->get($question->section_id);
            if ($section?->type !== ExamSection::TYPE_MCQ || $question->lock_options || $section->choiceCount() < 2) {
                continue;
            }
            $optionOrders[(int) $question->id] = array_values($randomizer->shuffleArray(range(1, $section->choiceCount())));
        }

        return ['question_order' => array_values($order), 'option_orders' => $optionOrders];
    }

    /**
     * Brings the stored versions in line with the current structure, nonce
     * and version_count. Does nothing once the structure is locked. Callers
     * hold the assignment row lock.
     *
     * @return Collection<int, ExamVersion>
     */
    public static function sync(Assignment $exam): Collection
    {
        if (! $exam->isExam() || $exam->structureLocked()) {
            return self::stored($exam);
        }
        [$sections, $questions] = self::structure($exam);
        $hash = self::structureHash($sections, $questions);
        $existing = self::stored($exam)->keyBy('version_no');

        for ($no = 1; $no <= $exam->version_count; $no++) {
            $seed = self::seed($exam->id, $no, $exam->shuffle_nonce);
            /** @var ExamVersion|null $row */
            $row = $existing->get($no);
            if ($row !== null && $row->structure_hash === $hash && $row->seed === $seed) {
                continue;
            }
            $permutation = self::permutation($sections, $questions, $no, $seed);
            ExamVersion::query()->updateOrCreate(
                ['assignment_id' => $exam->id, 'version_no' => $no],
                ['seed' => $seed, 'structure_hash' => $hash, ...$permutation],
            );
        }
        ExamVersion::query()->where('assignment_id', $exam->id)->where('version_no', '>', $exam->version_count)->delete();

        return self::stored($exam);
    }

    /** Whether every version has a permutation of the current structure and nonce. */
    public static function ready(Assignment $exam): bool
    {
        [$sections, $questions] = self::structure($exam);
        if ($questions->isEmpty()) {
            return false;
        }
        $hash = self::structureHash($sections, $questions);
        $stored = self::stored($exam);

        return $stored->count() === $exam->version_count
            && $stored->every(fn (ExamVersion $v) => $v->structure_hash === $hash
                && $v->seed === self::seed($exam->id, $v->version_no, $exam->shuffle_nonce));
    }

    /**
     * The key of one version by the number on its sheet (DESIGN §22.5):
     * mcq options are the displayed positions of that version.
     *
     * @param  Collection<int, Question>  $questionsById  keyed by id, with `section` loaded
     * @return list<array<string, mixed>>
     */
    public static function keyOf(ExamVersion $version, Collection $questionsById): array
    {
        $orders = $version->option_orders;
        $items = [];
        foreach (array_values($version->question_order) as $index => $questionId) {
            /** @var Question|null $question */
            $question = $questionsById->get((int) $questionId);
            if ($question === null) {
                continue;
            }
            $order = isset($orders[$questionId]) ? array_map('intval', $orders[$questionId]) : null;
            $key = $question->answer_key;
            $item = [
                'sheet_no' => $index + 1,
                'question_id' => (int) $question->id,
                'original_position' => (int) $question->position,
                'section_id' => (int) $question->section_id,
                'type' => $question->type,
                'points' => (float) $question->max_points,
                'option_order' => $order,
            ];
            if ($question->type === Question::TYPE_NUMERIC) {
                $item['accepted_values'] = is_array($key) ? array_values($key['accepted_values'] ?? []) : [];
            } else {
                $item['accepted_options'] = self::displayed(is_array($key) ? ($key['accepted_options'] ?? []) : [], $order);
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Original option positions -> displayed positions of a version.
     *
     * @param  array<int, mixed>  $original
     * @param  list<int>|null  $order  original position by displayed position, null = not shuffled
     * @return list<int>
     */
    public static function displayed(array $original, ?array $order): array
    {
        $original = array_map('intval', $original);
        if ($order === null) {
            sort($original);

            return array_values($original);
        }
        $out = [];
        foreach ($order as $index => $position) {
            if (in_array($position, $original, true)) {
                $out[] = $index + 1;
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, ExamVersion>
     */
    public static function stored(Assignment $exam): Collection
    {
        return ExamVersion::query()->where('assignment_id', $exam->id)->orderBy('version_no')->get();
    }

    /**
     * @return array{0: Collection<int, ExamSection>, 1: Collection<int, Question>}
     */
    private static function structure(Assignment $exam): array
    {
        $sections = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $questions = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get(['id', 'section_id', 'position', 'type', 'lock_options']);

        return [$sections, $questions];
    }
}
