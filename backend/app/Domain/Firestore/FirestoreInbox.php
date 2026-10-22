<?php

namespace App\Domain\Firestore;

use App\Domain\Notifications\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The notification inbox (DESIGN §38 step 2): every PushMessage becomes one
 * document per recipient at users/{uid}/notices/{ulid}:
 *
 *   kind        the push type (PushMessage::* constants)
 *   title, body the push text (never a score, §9.9)
 *   data        the push data map, strings, `type` first: the app routes on it
 *   read        false until POST /me/notices/read
 *   created_at  server time
 *   expires_at  created_at + RETENTION_DAYS, for the Firestore TTL policy
 *
 * The unread badge is counted by the app from the documents it listens to,
 * so no counter document is kept. Writes go through LiveStore (never throw).
 */
final class FirestoreInbox
{
    public const RETENTION_DAYS = 90;

    public function __construct(private readonly LiveStore $store) {}

    public function enabled(): bool
    {
        return $this->store->enabled();
    }

    /**
     * @param  list<int>  $userIds
     */
    public function record(array $userIds, PushMessage $message, ?CarbonImmutable $now = null): void
    {
        if (! $this->store->enabled() || $userIds === []) {
            return;
        }
        $now ??= CarbonImmutable::now();
        $fields = [
            'kind' => $message->type,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data(),
            'read' => false,
            'created_at' => $now,
            'expires_at' => $now->addDays(self::RETENTION_DAYS),
        ];
        // One id per event: the same notice under every recipient.
        $id = self::newId();
        $ops = [];
        foreach ($userIds as $userId) {
            $ops[] = ['path' => FirestoreUid::path($userId).'/notices/'.$id, 'fields' => $fields];
        }
        foreach (array_chunk($ops, FirestoreClient::MAX_WRITES) as $chunk) {
            $this->store->batch($chunk);
        }
    }

    /**
     * Marks the user's notices read (merge: only `read` changes). Unknown ids
     * are created as empty read documents by Firestore; the app only sends
     * ids it listed, and the TTL has no `expires_at` to act on, so they are
     * harmless but the ids are validated to the ULID shape upstream.
     *
     * @param  list<string>  $ids
     */
    public function markRead(int $userId, array $ids): void
    {
        if (! $this->store->enabled() || $ids === []) {
            return;
        }
        $ops = [];
        foreach (array_unique($ids) as $id) {
            $ops[] = ['path' => FirestoreUid::path($userId).'/notices/'.$id, 'fields' => ['read' => true], 'merge' => true];
        }
        foreach (array_chunk($ops, FirestoreClient::MAX_WRITES) as $chunk) {
            $this->store->batch($chunk);
        }
    }

    /** Lowercase ULID: sorts by time, safe in a Firestore path. */
    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1;
    }
}
