<?php

namespace App\Domain\Worksheets;

/**
 * The identifiers carried by a worksheet QR (DESIGN §5.4). studentId 0 is the
 * anonymous spare sheet of DESIGN §18.3.
 */
final readonly class WorksheetQr
{
    public function __construct(
        public int $assignmentId,
        public int $studentId,
        public int $page,
        public int $layoutVersion,
    ) {}

    public function isAnonymous(): bool
    {
        return $this->studentId === 0;
    }
}
