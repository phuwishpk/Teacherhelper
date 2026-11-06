<?php

namespace App\Domain\Assistant;

/**
 * What "ผู้ช่วย Krucheck" knows (DESIGN §37), from
 * backend/resources/assistant/:
 *
 * - core/*.md, sent with every message: the in-app guide and the menu
 *   (generated from the app, test/help/assistant_knowledge_test.dart), the
 *   grading rules and the common errors;
 * - manual/*.md, the full user manual and the use case document
 *   (tools/assistant-manuals.py), split into sections at "##" headings; the
 *   sections that fit the question are added, up to SECTION_BUDGET.
 *
 * Thai has no spaces between words, so a section's fit is the share of the
 * question's character trigrams it contains, titles counting more.
 */
final class AssistantKnowledge
{
    /** Characters of manual sections per message (~20-25k tokens of Thai). */
    public const SECTION_BUDGET = 60000;

    /** Sections at most, whatever their size. */
    public const MAX_SECTIONS = 10;

    /** @var array{0: string, 1: list<array{title: string, text: string, grams: array<string, true>, titleGrams: array<string, true>}>}|null */
    private ?array $loaded = null;

    public function __construct(private readonly ?string $directory = null) {}

    public function core(): string
    {
        return $this->load()[0];
    }

    /**
     * The manual sections for $query, best first, within the budget.
     *
     * @return list<array{title: string, text: string}>
     */
    public function sectionsFor(string $query): array
    {
        $wanted = self::grams($query);
        if ($wanted === []) {
            return [];
        }
        $scored = [];
        foreach ($this->load()[1] as $i => $section) {
            $inTitle = count(array_intersect_key($wanted, $section['titleGrams']));
            $inText = count(array_intersect_key($wanted, $section['grams']));
            $score = (3 * $inTitle + $inText) / count($wanted);
            if ($inText > 0 || $inTitle > 0) {
                $scored[] = [$score, $i];
            }
        }
        usort($scored, fn (array $a, array $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        $picked = [];
        $used = 0;
        foreach ($scored as [$score, $i]) {
            if (count($picked) >= self::MAX_SECTIONS || $score < 0.15) {
                break;
            }
            $section = $this->load()[1][$i];
            $length = mb_strlen($section['text']);
            if ($used + $length > self::SECTION_BUDGET) {
                continue;
            }
            $used += $length;
            $picked[] = ['title' => $section['title'], 'text' => $section['text']];
        }

        return $picked;
    }

    /**
     * Character trigrams of the letters and digits of $text, lower-cased.
     *
     * @return array<string, true>
     */
    public static function grams(string $text): array
    {
        $plain = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', mb_strtolower($text)) ?? '';
        $grams = [];
        $length = mb_strlen($plain);
        for ($i = 0; $i + 3 <= $length; $i++) {
            $grams[mb_substr($plain, $i, 3)] = true;
        }

        return $grams;
    }

    /** @return array{0: string, 1: list<array{title: string, text: string, grams: array<string, true>, titleGrams: array<string, true>}>} */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $root = $this->directory ?? resource_path('assistant');

        $core = [];
        foreach (self::files($root.'/core') as $file) {
            $core[] = trim((string) file_get_contents($file));
        }

        $sections = [];
        foreach (self::files($root.'/manual') as $file) {
            $text = (string) file_get_contents($file);
            $chapter = preg_match('/^# (.+)$/m', $text, $m) === 1 ? trim($m[1]) : basename($file, '.md');
            foreach (preg_split('/^(?=## )/m', $text) ?: [] as $part) {
                $part = trim((string) preg_replace('/<!--.*?-->/s', '', $part));
                if (mb_strlen($part) < 80) {
                    continue;
                }
                $heading = preg_match('/^## (.+)$/m', $part, $m) === 1 ? trim($m[1]) : '';
                $title = $heading === '' ? $chapter : $chapter.' > '.$heading;
                $sections[] = [
                    'title' => $title,
                    'text' => $part,
                    'grams' => self::grams($part),
                    'titleGrams' => self::grams($title),
                ];
            }
        }

        return $this->loaded = [implode("\n\n", $core), $sections];
    }

    /** @return list<string> */
    private static function files(string $dir): array
    {
        $files = glob($dir.'/*.md') ?: [];
        sort($files);

        return $files;
    }
}
