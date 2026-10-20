<?php

namespace Tests\Support;

use App\Domain\Firestore\LiveStore;

/** Records every Firestore write instead of sending it (DESIGN §38). */
class RecordingLiveStore implements LiveStore
{
    /** @var list<array{path: string, fields?: array<string, mixed>, merge?: bool, delete?: bool}> */
    public array $ops = [];

    public int $commits = 0;

    public function __construct(private readonly bool $on = true) {}

    public function enabled(): bool
    {
        return $this->on;
    }

    public function set(string $path, array $fields, bool $merge = false): void
    {
        $this->batch([['path' => $path, 'fields' => $fields, 'merge' => $merge]]);
    }

    public function delete(string $path): void
    {
        $this->batch([['path' => $path, 'delete' => true]]);
    }

    public function batch(array $ops): void
    {
        $this->commits++;
        foreach ($ops as $op) {
            $this->ops[] = $op;
        }
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(fn (array $op) => $op['path'], $this->ops);
    }
}
