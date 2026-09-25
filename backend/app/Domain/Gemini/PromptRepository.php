<?php

namespace App\Domain\Gemini;

use InvalidArgumentException;

/**
 * Loads versioned prompt files (DESIGN §10.2) from resources/prompts:
 *
 *   {purpose}.{type}.v{n}.md      e.g. extract.show_work.v1.md, explanation.general.v1.md
 *
 *   ---
 *   purpose: extract
 *   type: show_work
 *   version: 1
 *   temperature: 0
 *   ---
 *   # System
 *   ...instruction (English)...
 *   # User
 *   ...template with {placeholders}...
 *
 * The highest version of a (purpose, type) is used. Editing a prompt means
 * adding the next version, so ai_calls.prompt_version can compare them.
 */
final class PromptRepository
{
    /** @var array<string, Prompt> */
    private array $cache = [];

    public function __construct(private readonly ?string $directory = null) {}

    public function get(string $purpose, string $type): Prompt
    {
        return $this->cache[$purpose.'.'.$type] ??= $this->load($purpose, $type);
    }

    private function load(string $purpose, string $type): Prompt
    {
        if (preg_match('/^[a-z_]+$/', $purpose) !== 1 || preg_match('/^[a-z_]+$/', $type) !== 1) {
            throw new InvalidArgumentException("invalid prompt name {$purpose}.{$type}");
        }

        $dir = $this->directory ?? resource_path('prompts');
        $best = null;
        foreach (glob("{$dir}/{$purpose}.{$type}.v*.md") ?: [] as $file) {
            if (preg_match('/\.v(\d+)\.md$/', $file, $m) === 1 && ($best === null || (int) $m[1] > $best[0])) {
                $best = [(int) $m[1], $file];
            }
        }
        if ($best === null) {
            throw new InvalidArgumentException("no prompt file for {$purpose}.{$type} in {$dir}");
        }

        return self::parse((string) file_get_contents($best[1]), $purpose, $type, $best[0], basename($best[1]));
    }

    public static function parse(string $content, string $purpose, string $type, int $version, string $file = 'prompt'): Prompt
    {
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $content, $m) !== 1) {
            throw new InvalidArgumentException("{$file}: missing front matter");
        }

        $meta = [];
        foreach (preg_split('/\R/', trim($m[1])) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $kv) === 1) {
                $meta[$kv[1]] = trim($kv[2]);
            }
        }
        if (($meta['purpose'] ?? null) !== $purpose || ($meta['type'] ?? null) !== $type || (int) ($meta['version'] ?? 0) !== $version) {
            throw new InvalidArgumentException("{$file}: front matter does not match the file name");
        }

        $sections = preg_split('/^# (System|User)[ \t]*$/m', $m[2], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $parts = [];
        for ($i = 1; $i + 1 < count($sections); $i += 2) {
            $parts[strtolower($sections[$i])] = trim($sections[$i + 1]);
        }
        if (($parts['system'] ?? '') === '' || ($parts['user'] ?? '') === '') {
            throw new InvalidArgumentException("{$file}: needs a '# System' and a '# User' section");
        }

        $temperature = isset($meta['temperature']) && is_numeric($meta['temperature']) ? (float) $meta['temperature'] : null;

        return new Prompt($purpose, $type, $version, $parts['system'], $parts['user'], $temperature);
    }
}
