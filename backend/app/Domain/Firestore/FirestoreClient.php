<?php

namespace App\Domain\Firestore;

use App\Domain\Notifications\Fcm\AccessTokens;
use App\Domain\Notifications\Fcm\FcmAuthFailed;
use App\Domain\Notifications\Fcm\ServiceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Firestore REST API with the FCM service account (DESIGN §38.4):
 * POST https://firestore.googleapis.com/v1/projects/{p}/databases/{db}/documents:commit
 * with a bearer token from AccessTokens (scope datastore). Every call is one
 * commit (atomic, up to 500 writes); a 401 drops the cached token and retries
 * once. Failures throw FirestoreUnavailable; LiveStore swallows and logs them,
 * because a mirror must never fail the job that writes it (§38.1).
 */
final class FirestoreClient
{
    public const BASE_URL = 'https://firestore.googleapis.com/v1';

    public const SCOPE = 'https://www.googleapis.com/auth/datastore';

    /** Firestore's limit per commit. */
    public const MAX_WRITES = 500;

    public function __construct(
        private readonly ServiceAccount $account,
        private readonly AccessTokens $tokens,
        private readonly string $database = '(default)',
        private readonly int $timeout = 10,
    ) {}

    public static function fromConfig(): self
    {
        $account = FirestoreConfig::account();
        $timeout = FirestoreConfig::timeout();

        return new self($account, new AccessTokens($account, $timeout, self::SCOPE), FirestoreConfig::database(), $timeout);
    }

    public function projectId(): string
    {
        return $this->account->projectId;
    }

    public function database(): string
    {
        return $this->database;
    }

    /** `projects/{p}/databases/{db}/documents` */
    public function root(): string
    {
        return "projects/{$this->account->projectId}/databases/{$this->database}/documents";
    }

    /**
     * Writes the fields of `path` (e.g. `users/u12/notices/abc`); with $merge
     * only the given fields change, otherwise the document is replaced.
     *
     * @param  array<string, mixed>  $fields
     */
    public function set(string $path, array $fields, bool $merge = false): void
    {
        $this->commit([self::setWrite($path, $fields, $merge)]);
    }

    public function delete(string $path): void
    {
        $this->commit([self::deleteWrite($path)]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function setWrite(string $path, array $fields, bool $merge = false): array
    {
        $write = ['update' => [
            'name' => $this->root().'/'.ltrim($path, '/'),
            'fields' => FirestoreValues::fields($fields),
        ]];
        if ($merge) {
            $write['updateMask'] = ['fieldPaths' => array_map(self::fieldPath(...), array_keys($fields))];
        }

        return $write;
    }

    /** @return array<string, mixed> */
    public function deleteWrite(string $path): array
    {
        return ['delete' => $this->root().'/'.ltrim($path, '/')];
    }

    /**
     * One atomic commit of the given writes (from setWrite / deleteWrite).
     *
     * @param  list<array<string, mixed>>  $writes
     *
     * @throws FirestoreUnavailable
     */
    public function commit(array $writes): void
    {
        if ($writes === []) {
            return;
        }
        if (count($writes) > self::MAX_WRITES) {
            throw new \InvalidArgumentException('A Firestore commit takes at most '.self::MAX_WRITES.' writes');
        }
        $response = $this->request('post', $this->root().':commit', ['writes' => $writes]);
        if (! $response->successful()) {
            throw new FirestoreUnavailable('commit failed: '.self::describe($response));
        }
    }

    /**
     * Reads one document: its fields as plain PHP values, or null when it
     * does not exist (for firestore-check; the app reads with listeners).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $path): ?array
    {
        $response = $this->request('get', $this->root().'/'.ltrim($path, '/'));
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new FirestoreUnavailable('read failed: '.self::describe($response));
        }
        $fields = $response->json('fields');

        return FirestoreValues::decodeFields(is_array($fields) ? $fields : []);
    }

    /**
     * The ids of the subcollections directly under a document (the document
     * itself need not exist: users/u12 has none, only its notices).
     *
     * @return list<string>
     *
     * @throws FirestoreUnavailable
     */
    public function collectionIds(string $documentPath): array
    {
        $ids = [];
        $pageToken = null;
        do {
            $body = ['pageSize' => 100] + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
            $response = $this->request('post', $this->root().'/'.trim($documentPath, '/').':listCollectionIds', $body);
            if (! $response->successful()) {
                throw new FirestoreUnavailable('listCollectionIds failed: '.self::describe($response));
            }
            foreach ((array) $response->json('collectionIds', []) as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
            $next = $response->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null);

        return $ids;
    }

    /**
     * One page of a collection's document paths (relative to root()), with
     * the token of the next page or null.
     *
     * @return array{0: list<string>, 1: string|null}
     *
     * @throws FirestoreUnavailable
     */
    public function documentPaths(string $collectionPath, int $pageSize = 300, ?string $pageToken = null): array
    {
        $query = ['pageSize' => $pageSize, 'mask.fieldPaths' => 'read'];
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }
        $response = $this->request('get', $this->root().'/'.trim($collectionPath, '/'), $query);
        if ($response->status() === 404) {
            return [[], null];
        }
        if (! $response->successful()) {
            throw new FirestoreUnavailable('list failed: '.self::describe($response));
        }
        $prefix = $this->root().'/';
        $paths = [];
        foreach ((array) $response->json('documents', []) as $doc) {
            $name = is_array($doc) ? ($doc['name'] ?? null) : null;
            if (is_string($name) && str_starts_with($name, $prefix)) {
                $paths[] = substr($name, strlen($prefix));
            }
        }
        $next = $response->json('nextPageToken');

        return [$paths, is_string($next) && $next !== '' ? $next : null];
    }

    /**
     * Deletes every document of every subcollection under $documentPath, one
     * level deep (the live layer keeps no deeper nesting, DESIGN §38.2), in
     * commits of up to MAX_WRITES. Returns how many documents were deleted.
     *
     * @throws FirestoreUnavailable
     */
    public function deleteSubcollections(string $documentPath): int
    {
        $deleted = 0;
        foreach ($this->collectionIds($documentPath) as $collection) {
            $collectionPath = trim($documentPath, '/').'/'.$collection;
            // Always read the first page again: the previous one is gone.
            do {
                [$paths] = $this->documentPaths($collectionPath, self::MAX_WRITES);
                $this->commit(array_map($this->deleteWrite(...), $paths));
                $deleted += count($paths);
            } while (count($paths) === self::MAX_WRITES);
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>|null  $body  POST body, or the query of a GET
     *
     * @throws FirestoreUnavailable
     */
    private function request(string $method, string $resource, ?array $body = null): Response
    {
        try {
            $response = $this->send($method, $resource, $body);
            if ($response->status() === 401) {
                $this->tokens->forget();
                $response = $this->send($method, $resource, $body);
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new FirestoreUnavailable('unreachable: '.$e->getMessage());
        } catch (FcmAuthFailed $e) {
            throw new FirestoreUnavailable('auth: '.$e->getMessage());
        }
    }

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $resource, ?array $body): Response
    {
        $pending = Http::withToken($this->tokens->get())->acceptJson()->timeout($this->timeout);
        $url = self::BASE_URL.'/'.$resource;

        return $method === 'get' ? $pending->get($url, $body ?? []) : $pending->post($url, $body ?? []);
    }

    private static function describe(Response $response): string
    {
        $status = $response->json('error.status');
        $message = $response->json('error.message');

        return 'HTTP '.$response->status()
            .(is_string($status) ? ' '.$status : '')
            .(is_string($message) ? ' '.mb_substr($message, 0, 200) : '');
    }

    /** Field names with characters outside [A-Za-z0-9_] need backticks in a mask. */
    private static function fieldPath(string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : '`'.str_replace('`', '\\`', $name).'`';
    }
}
