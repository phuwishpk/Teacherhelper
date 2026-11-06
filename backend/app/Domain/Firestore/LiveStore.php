<?php

namespace App\Domain\Firestore;

/**
 * The mirror jobs write to (DESIGN §38.1). Implementations never throw: the
 * container binds FirestoreLiveStore when FirestoreConfig::enabled(), else
 * NullLiveStore, and a failing mirror only logs. Writers run in queue jobs
 * or queued listeners, not inside an HTTP request.
 */
interface LiveStore
{
    public function enabled(): bool;

    /** @param  array<string, mixed>  $fields */
    public function set(string $path, array $fields, bool $merge = false): void;

    public function delete(string $path): void;

    /**
     * Several documents in one atomic commit (at most FirestoreClient::MAX_WRITES).
     *
     * @param  list<array{path: string, fields?: array<string, mixed>, merge?: bool, delete?: bool}>  $ops
     */
    public function batch(array $ops): void;
}
