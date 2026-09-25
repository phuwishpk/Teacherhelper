<?php

namespace App\Domain\Worksheets;

/** The header data of one student's copy of a worksheet. */
final readonly class WorksheetStudent
{
    public function __construct(
        public int $id,
        public string $name,
        public int $number,
    ) {}
}
