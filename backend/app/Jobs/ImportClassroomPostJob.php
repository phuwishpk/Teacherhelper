<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomPostImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pulls one announcement or material the teacher wrote on the Classroom
 * website into the app (DESIGN §45). Carries the resource the sync listed
 * (text, material ids, assignees' Google ids; no grades). Unique per course
 * item and version while queued, so two rounds before the worker ran do not
 * queue it twice. Google unreachable: retried with backoff.
 */
class ImportClassroomPostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 900;

    /**
     * @param  array<string, mixed>  $item  the Announcement or CourseWorkMaterial resource
     * @param  string|null  $notifyAfter  when the previous round listed the course (null on the first)
     */
    public function __construct(
        public readonly int $linkId,
        public readonly string $kind,
        public readonly array $item,
        public readonly ?string $notifyAfter,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->linkId, $this->kind, (string) ($this->item['id'] ?? ''), (string) ($this->item['updateTime'] ?? '')]);
    }

    public function handle(ClassroomPostImporter $importer): void
    {
        $importer->import($this->linkId, $this->kind, $this->item, $this->notifyAfter);
    }
}
