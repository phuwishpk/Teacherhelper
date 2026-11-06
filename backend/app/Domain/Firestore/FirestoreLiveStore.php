<?php

namespace App\Domain\Firestore;

use Illuminate\Support\Facades\Log;

/**
 * LiveStore over FirestoreClient: swallows FirestoreUnavailable with one log
 * line (`firestore.write_failed`, never the fields), so grading, publishing
 * and posting never fail over their mirror (DESIGN §38.1).
 */
final class FirestoreLiveStore implements LiveStore
{
    public function __construct(private readonly FirestoreClient $client) {}

    public function enabled(): bool
    {
        return true;
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
        $writes = [];
        foreach ($ops as $op) {
            $writes[] = ($op['delete'] ?? false)
                ? $this->client->deleteWrite($op['path'])
                : $this->client->setWrite($op['path'], $op['fields'] ?? [], (bool) ($op['merge'] ?? false));
        }
        try {
            $this->client->commit($writes);
        } catch (FirestoreUnavailable $e) {
            Log::warning('firestore.write_failed', [
                'paths' => array_map(fn (array $op) => $op['path'], $ops),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
