<?php

namespace App\Domain\Firestore;

/** Firestore off: every write is a no-op (DESIGN §38.1). */
final class NullLiveStore implements LiveStore
{
    public function enabled(): bool
    {
        return false;
    }

    public function set(string $path, array $fields, bool $merge = false): void {}

    public function delete(string $path): void {}

    public function batch(array $ops): void {}
}
