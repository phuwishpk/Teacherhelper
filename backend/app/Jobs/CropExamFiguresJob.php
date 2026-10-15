<?php

namespace App\Jobs;

use App\Domain\Exams\ExamFigures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Crops the figures that wait for one page of an exam document (DESIGN
 * §22.4, §22.16) with GD, decoding the page first when it is a photo the
 * server reads itself. Light work, kept out of the upload request.
 * Carries the page image id only.
 */
class CropExamFiguresJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $pageImageId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        ExamFigures::cropPage($this->pageImageId);
    }
}
