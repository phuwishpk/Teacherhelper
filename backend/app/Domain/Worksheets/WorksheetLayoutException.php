<?php

namespace App\Domain\Worksheets;

use RuntimeException;

/** A question cannot be laid out (for example it is taller than a whole page). */
class WorksheetLayoutException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $questionNumber = null)
    {
        parent::__construct($message);
    }
}
