<?php

namespace App\Jobs;

use App\Domain\Google\CourseWorkImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mirrors one courseWork created on the Classroom website (DESIGN §19.3,
 * §19.10) and starts its AI key draft. Carries the CourseWork resource the
 * sync listed (title, instructions, due date, material ids; no personal
 * data). Unique per classroom and courseWork while queued, so two sync
 * rounds before the worker ran do not queue it twice. Google unreachable:
 * retried with backoff.
 */
class ImportCourseWorkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 900;

    /**
     * The classroom_google_links row whose course listed the courseWork
     * (DESIGN §24.10: one course per teacher). Not promoted, so a job queued
     * before build 4 unserializes with null and falls back to the homeroom
     * teacher's course.
     */
    public ?int $linkId = null;

    /**
     * @param  array<string, mixed>  $courseWork
     */
    public function __construct(
        public readonly int $classroomId,
        public readonly array $courseWork,
        ?int $linkId = null,
    ) {
        $this->linkId = $linkId;
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->classroomId.':'.($this->courseWork['id'] ?? '');
    }

    public function handle(CourseWorkImporter $importer): void
    {
        $importer->import($this->classroomId, $this->courseWork, $this->linkId);
    }
}
