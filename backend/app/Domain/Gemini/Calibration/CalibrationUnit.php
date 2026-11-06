<?php

namespace App\Domain\Gemini\Calibration;

/**
 * One manifest item: the file(s) sent together (a crop and its final-answer
 * box, a whole page, a document page) and the labelled question(s) on them.
 */
final readonly class CalibrationUnit
{
    /**
     * @param  list<array{bytes: string, mime_type: string, path: string}>  $files
     * @param  list<CalibrationSample>  $samples
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $files,
        public array $samples,
    ) {}
}
