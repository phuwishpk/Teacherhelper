<?php

namespace App\Domain\Qa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The latest GitHub Actions results of main, for the automated cases of the
 * Test Lab (DESIGN §39). The repository is public, so the unauthenticated
 * API answers (60 calls an hour per server IP); the answer is cached ten
 * minutes and a failure one minute, so the page never waits on GitHub twice.
 */
final class CiStatus
{
    private const CACHE_KEY = 'qa:ci-status';

    public const WORKFLOWS = ['backend', 'app', 'deploy', 'secrets'];

    /**
     * @return array{sha: string, title: string, at: string, workflows: array<string, array{conclusion: string|null, status: string, url: string, sha: string, at: string}>}|null
     */
    public static function latest(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached['data'];
        }
        $data = self::fetch();
        Cache::put(self::CACHE_KEY, ['data' => $data], $data === null ? 60 : 600);

        return $data;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The commit production runs, from the deploy tree's DEPLOY-COMMIT.txt (null on a developer's machine). */
    public static function deployedCommit(): ?string
    {
        $path = dirname(base_path()).'/DEPLOY-COMMIT.txt';
        if (! is_file($path)) {
            return null;
        }
        $sha = trim((string) file_get_contents($path));

        return preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? $sha : null;
    }

    public static function repo(): string
    {
        return (string) config('eduvision.qa.github_repo');
    }

    /** @return array<string, mixed>|null */
    private static function fetch(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'Krucheck-Test-Lab', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(8)
                ->get('https://api.github.com/repos/'.self::repo().'/actions/runs', ['branch' => 'main', 'per_page' => 40]);
        } catch (ConnectionException) {
            return null;
        }
        $runs = $response->json('workflow_runs');
        if (! $response->successful() || ! is_array($runs) || $runs === []) {
            return null;
        }

        $workflows = [];
        foreach ($runs as $run) {
            $name = is_array($run) ? ($run['name'] ?? null) : null;
            if (! in_array($name, self::WORKFLOWS, true) || isset($workflows[$name])) {
                continue;
            }
            $workflows[$name] = [
                'conclusion' => is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
                'status' => (string) ($run['status'] ?? ''),
                'url' => (string) ($run['html_url'] ?? ''),
                'sha' => substr((string) ($run['head_sha'] ?? ''), 0, 7),
                'at' => (string) ($run['created_at'] ?? ''),
            ];
        }
        $head = $runs[0];

        return [
            'sha' => substr((string) ($head['head_sha'] ?? ''), 0, 7),
            'title' => (string) ($head['display_title'] ?? ''),
            'at' => (string) ($head['created_at'] ?? ''),
            'workflows' => $workflows,
        ];
    }
}
