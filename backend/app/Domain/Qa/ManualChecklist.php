<?php

namespace App\Domain\Qa;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * The manual web test checklist (DESIGN §39.7) and its test files, kept in
 * resources/qa/manual-test so both deployments carry them (the self-hosted
 * image copies backend/ only). The Test Lab page shows README.md with its
 * `files/…` links pointing at the download route and every task-list box
 * tickable; the files are fictional data, never real students' work.
 */
final class ManualChecklist
{
    public const DIR = 'resources/qa/manual-test';

    /** Only these names are served, so a request never reaches another path. */
    public const FILE_PATTERN = '/^[a-z0-9][a-z0-9-]*\.pdf$/';

    public static function readme(): string
    {
        return base_path(self::DIR.'/README.md');
    }

    /** The full path of a test file, or null when it is not one. */
    public static function file(string $name): ?string
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1) {
            return null;
        }
        $path = base_path(self::DIR.'/files/'.$name);

        return is_file($path) ? $path : null;
    }

    /** @return list<array{name: string, size: int}> */
    public static function files(): array
    {
        $out = [];
        foreach (glob(base_path(self::DIR.'/files/*.pdf')) ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path)) === 1) {
                $out[] = ['name' => basename($path), 'size' => (int) filesize($path)];
            }
        }

        return $out;
    }

    /**
     * README.md as HTML: `files/x.pdf` links go to $fileUrl(x.pdf) and the
     * task-list boxes lose `disabled` and get data-qa-check="n" in order.
     *
     * @param  callable(string): string  $fileUrl
     */
    public static function html(callable $fileUrl): string
    {
        $markdown = (string) @file_get_contents(self::readme());
        if ($markdown === '') {
            return '';
        }
        $html = (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown);

        $html = (string) preg_replace_callback(
            '/href="files\/([^"]+)"/',
            fn (array $m) => self::file(html_entity_decode($m[1])) !== null ? 'href="'.e($fileUrl(html_entity_decode($m[1]))).'"' : $m[0],
            $html,
        );

        $n = 0;

        return (string) preg_replace_callback(
            '/<input (?:checked="" )?disabled="" type="checkbox"\s*\/?>/',
            function () use (&$n) {
                $n++;

                return '<input type="checkbox" data-qa-check="'.$n.'">';
            },
            $html,
        );
    }
}
