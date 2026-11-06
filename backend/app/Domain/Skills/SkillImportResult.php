<?php

namespace App\Domain\Skills;

/**
 * The report of one CSV import (DESIGN §20.2): {created, updated,
 * unchanged, errors: [{line, message}]}. A row with an error is skipped
 * (or, for a parent_code that cannot be found, saved without its parent)
 * and every other row is imported.
 */
final class SkillImportResult
{
    /**
     * @param  array<int, string>  $warnings
     * @param  list<array{line: int, message: string}>  $errors
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $subjectsCreated = 0,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    /** Rows written or confirmed (every row without an error that stopped it). */
    public function rows(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    public function error(int $line, string $message): void
    {
        $this->errors[] = ['line' => $line, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string> "บรรทัด n: message", in line order
     */
    public function errorLines(): array
    {
        $errors = $this->errors;
        usort($errors, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return array_map(fn (array $e) => "บรรทัด {$e['line']}: {$e['message']}", $errors);
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, subjects_created: int, warnings: array<int, string>, errors: list<array{line: int, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'subjects_created' => $this->subjectsCreated,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
