<?php

namespace App\Domain\Gemini;

use InvalidArgumentException;

/**
 * One prompt file: resources/prompts/{purpose}.{type}.v{n}.md (DESIGN §10.2),
 * a system instruction and a user template with {placeholders}. Rendering
 * substitutes in one pass (a value that contains "{x}" is never expanded)
 * and refuses to leave a placeholder unfilled.
 */
final readonly class Prompt
{
    public function __construct(
        public string $purpose,
        public string $type,
        public int $version,
        public string $system,
        public string $user,
        public ?float $temperature = null,
        public ?string $thinking = null,
        public ?int $maxOutputTokens = null,
    ) {}

    /** ai_calls.prompt_version, e.g. "v1" (purpose and question type are known from the row). */
    public function versionLabel(): string
    {
        return 'v'.$this->version;
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function renderSystem(array $vars = []): string
    {
        return self::render($this->system, $vars, $this->name());
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    public function renderUser(array $vars = []): string
    {
        return self::render($this->user, $vars, $this->name());
    }

    public function name(): string
    {
        return "{$this->purpose}.{$this->type}.v{$this->version}";
    }

    /**
     * @param  array<string, string|int|float>  $vars
     */
    private static function render(string $template, array $vars, string $name): string
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $template, $m);
        $missing = array_diff(array_unique($m[1]), array_keys($vars));
        if ($missing !== []) {
            throw new InvalidArgumentException("prompt {$name} needs ".implode(', ', $missing));
        }

        $pairs = [];
        foreach ($vars as $key => $value) {
            $pairs['{'.$key.'}'] = (string) $value;
        }

        return strtr($template, $pairs);
    }
}
