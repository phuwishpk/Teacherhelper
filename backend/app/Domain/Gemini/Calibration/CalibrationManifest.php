<?php

namespace App\Domain\Gemini\Calibration;

use App\Models\Question;
use App\Models\RubricCriterion;
use InvalidArgumentException;

/**
 * The golden fixture set of the calibration harness (DESIGN §21.10):
 * docs/fixtures/calibration/manifest.json and the images next to it.
 *
 *   {version: 1, subject, grade_level, items: [
 *     {id, kind: short|work, source, file, final_file?, question, label, cnn?},
 *     {id, kind: page|document, source, file, questions: [question + {position, label}]}
 *   ]}
 *
 * question: {type, prompt_text, max_points?, is_numeric?, match_mode?,
 * answer_lines?, answer_key?, criteria?: [{description, points, is_core}]}.
 * label, what is really written on the image:
 *
 *   short      {answer_text, key_match: [accepted categories]}
 *   show_work  {final_answer_text, final_answer_match: [...], steps_valid: [bool per line]}
 *   open       {criteria_levels: [met|partially_met|not_met per criterion]}
 *   mcq        {selected: A-D}            (page only)
 *   document   {answer}                   (the key the teacher wrote)
 *
 * cnn (short only, optional): the phone's digit reading {text, confidence},
 * for the CNN-skip check (§21.3); it needs no Gemini call.
 */
final class CalibrationManifest
{
    public const KINDS = ['short', 'work', 'page', 'document'];

    /**
     * @param  array<string, list<CalibrationUnit>>  $units  by kind
     */
    private function __construct(
        public readonly string $subject,
        public readonly int $gradeLevel,
        private readonly array $units,
    ) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("manifest not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException("{$path}: not a manifest (needs items[])");
        }
        $dir = dirname($path);

        $units = array_fill_keys(self::KINDS, []);
        $ids = [];
        foreach ($data['items'] as $i => $item) {
            $id = (string) ($item['id'] ?? "item {$i}");
            $kind = (string) ($item['kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException("{$id}: unknown kind '{$kind}'");
            }
            if (isset($ids[$id])) {
                throw new InvalidArgumentException("{$id}: listed twice");
            }
            $ids[$id] = true;

            $files = [self::file($dir, $id, (string) ($item['file'] ?? ''))];
            if (in_array($kind, ['short', 'work'], true)) {
                if (isset($item['final_file'])) {
                    $files[] = self::file($dir, $id, (string) $item['final_file']);
                }
                $samples = [self::sample($id, 1, (array) ($item['question'] ?? []), (array) ($item['label'] ?? []), $item['cnn'] ?? null, $kind === 'document')];
            } else {
                $samples = [];
                foreach ((array) ($item['questions'] ?? []) as $q) {
                    $q = (array) $q;
                    $samples[] = self::sample($id, (int) ($q['position'] ?? count($samples) + 1), $q, (array) ($q['label'] ?? []), null, $kind === 'document');
                }
            }
            if ($samples === []) {
                throw new InvalidArgumentException("{$id}: no question");
            }
            self::checkKind($id, $kind, $samples, count($files));
            $units[$kind][] = new CalibrationUnit($id, $kind, $files, $samples);
        }

        return new self((string) ($data['subject'] ?? ''), (int) ($data['grade_level'] ?? 5), $units);
    }

    /** @return list<CalibrationUnit> */
    public function units(string $kind): array
    {
        return $this->units[$kind] ?? [];
    }

    public function sampleCount(string $kind): int
    {
        return array_sum(array_map(fn (CalibrationUnit $u) => count($u->samples), $this->units($kind)));
    }

    /**
     * @return array{bytes: string, mime_type: string, path: string}
     */
    private static function file(string $dir, string $id, string $relative): array
    {
        $path = $dir.'/'.ltrim($relative, '/');
        if ($relative === '' || str_contains($relative, '..') || ! is_file($path)) {
            throw new InvalidArgumentException("{$id}: file '{$relative}' is missing");
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf',
            default => throw new InvalidArgumentException("{$id}: unsupported file type {$relative}"),
        };

        return ['bytes' => (string) file_get_contents($path), 'mime_type' => $mime, 'path' => $relative];
    }

    /**
     * @param  array<string, mixed>  $q
     * @param  array<string, mixed>  $label
     */
    private static function sample(string $unitId, int $position, array $q, array $label, mixed $cnn, bool $document): CalibrationSample
    {
        $type = (string) ($q['type'] ?? '');
        if (! in_array($type, Question::TYPES, true)) {
            throw new InvalidArgumentException("{$unitId}: question {$position} has no valid type");
        }
        if ($label === []) {
            throw new InvalidArgumentException("{$unitId}: question {$position} has no label");
        }
        $question = (new Question)->forceFill([
            'position' => $position,
            'type' => $type,
            'prompt_text' => (string) ($q['prompt_text'] ?? ''),
            'max_points' => (float) ($q['max_points'] ?? 1),
            'is_numeric' => (bool) ($q['is_numeric'] ?? false),
            'match_mode' => (string) ($q['match_mode'] ?? 'flexible'),
            'answer_lines' => (int) ($q['answer_lines'] ?? 3),
            'answer_key' => $q['answer_key'] ?? null,
        ]);
        $criteria = [];
        foreach ((array) ($q['criteria'] ?? []) as $i => $c) {
            $criteria[] = (new RubricCriterion)->forceFill([
                'position' => $i + 1,
                'description' => (string) ($c['description'] ?? ''),
                'points' => (float) ($c['points'] ?? 1),
                'is_core' => (bool) ($c['is_core'] ?? false),
            ]);
        }
        if (! $document && $type === Question::TYPE_OPEN && $criteria === []) {
            throw new InvalidArgumentException("{$unitId}: open question {$position} needs criteria");
        }
        $reading = is_array($cnn) && isset($cnn['text'], $cnn['confidence'])
            ? ['text' => (string) $cnn['text'], 'confidence' => (float) $cnn['confidence']]
            : null;

        return new CalibrationSample("{$unitId}#{$position}", $question, $criteria, $label, $reading);
    }

    /**
     * @param  list<CalibrationSample>  $samples
     */
    private static function checkKind(string $id, string $kind, array $samples, int $files): void
    {
        $type = $samples[0]->question->type;
        $ok = match ($kind) {
            'short' => $type === Question::TYPE_SHORT && $files === 1,
            'work' => in_array($type, [Question::TYPE_SHOW_WORK, Question::TYPE_OPEN], true) && ($files === 1 || $type === Question::TYPE_SHOW_WORK),
            default => true,
        };
        if (! $ok) {
            throw new InvalidArgumentException("{$id}: a {$kind} item cannot hold a {$type} question with {$files} file(s)");
        }
    }
}
