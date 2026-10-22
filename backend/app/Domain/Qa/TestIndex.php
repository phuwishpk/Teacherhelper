<?php

namespace App\Domain\Qa;

/**
 * The individual tests behind each automated case (DESIGN §39): the
 * `test_*` methods of a PHP test, the `test(...)`/`testWidgets(...)` names
 * of a Dart test, the job names of a workflow. Read from the repository
 * when it is there (a developer's machine, CI); production has no tests/
 * folder, so the deploy workflow writes resources/qa/test-index.json with
 * `eduvision:qa-index` and this class reads that instead.
 */
final class TestIndex
{
    public const MANIFEST = 'resources/qa/test-index.json';

    /** @var array<string, list<string>>|null */
    private static ?array $manifest = null;

    /**
     * @return list<string>|null the names, or null when neither the file nor the manifest knows the ref
     */
    public static function forRef(string $ref): ?array
    {
        $path = self::repoPath($ref);
        if (is_file($path)) {
            return self::parse($ref, (string) file_get_contents($path));
        }
        self::$manifest ??= self::loadManifest();

        return self::$manifest[$ref] ?? null;
    }

    /** @return array<string, list<string>> every ref of the catalog that exists here */
    public static function build(): array
    {
        $out = [];
        foreach (QaCatalog::ofKind(QaCatalog::AUTO) as $case) {
            foreach ($case['refs'] as $ref) {
                $path = self::repoPath($ref);
                if (is_file($path)) {
                    $out[$ref] = self::parse($ref, (string) file_get_contents($path));
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** A ref is relative to the repository root (the folder above backend/). */
    public static function repoPath(string $ref): string
    {
        return dirname(base_path()).'/'.ltrim($ref, '/');
    }

    /** @return list<string> */
    public static function parse(string $ref, string $source): array
    {
        return match (true) {
            str_ends_with($ref, '.php') => self::php($source),
            str_ends_with($ref, '.dart') => self::dart($source),
            str_ends_with($ref, '.yml'), str_ends_with($ref, '.yaml') => self::workflow($source),
            default => [],
        };
    }

    /** @return list<string> "test_a_teacher_signs_in" -> "a teacher signs in" */
    private static function php(string $source): array
    {
        preg_match_all('/function\s+test_([A-Za-z0-9_]+)\s*\(/', $source, $m);

        return array_map(fn (string $name) => str_replace('_', ' ', $name), $m[1]);
    }

    /** @return list<string> adjacent string literals are joined, as Dart does */
    private static function dart(string $source): array
    {
        preg_match_all("/\\b(?:testWidgets|test)\\(\\s*((?:'(?:[^'\\\\]|\\\\.)*'\\s*)+),/", $source, $m);
        $names = [];
        foreach ($m[1] as $literals) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $literals, $parts);
            $names[] = stripslashes(implode('', $parts[1]));
        }

        return $names;
    }

    /** @return list<string> the `name:` of each job */
    private static function workflow(string $source): array
    {
        $jobs = strstr($source, "\njobs:");
        if ($jobs === false) {
            return [];
        }
        preg_match_all('/^    name:\s*(.+)$/m', $jobs, $m);

        return array_map(fn (string $n) => trim($n, " \t'\""), $m[1]);
    }

    /** @return array<string, list<string>> */
    private static function loadManifest(): array
    {
        $path = base_path(self::MANIFEST);
        if (! is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) && is_array($json['refs'] ?? null) ? $json['refs'] : [];
    }

    /** For tests: forget the cached manifest. */
    public static function flush(): void
    {
        self::$manifest = null;
    }
}
