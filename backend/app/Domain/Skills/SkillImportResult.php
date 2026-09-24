<?php

namespace App\Domain\Skills;

final class SkillImportResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $subjectsCreated = 0,
        public array $warnings = [],
    ) {}

    public function rows(): int
    {
        return $this->created + $this->updated;
    }
}
