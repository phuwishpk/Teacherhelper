<?php

namespace App\Domain\Web;

/**
 * The Flutter web app when it is installed next to the API (DESIGN §25):
 * the build of tools/build-web.sh unpacked into public/app and served by the
 * web server as static files at /app/. It is uploaded by hand and is not
 * part of the repository, so whatever points at it first checks that it is
 * there.
 */
final class WebApp
{
    /** Folder under public/ and URL path; must match --base-href of the build. */
    public const PATH = 'app';

    public static function installed(): bool
    {
        return is_file(public_path(self::PATH.'/index.html'));
    }

    /** URL of a screen of the web app (hash routing), e.g. /student/results/5. */
    public static function url(string $route = '/'): string
    {
        return url('/'.self::PATH).'/#/'.ltrim($route, '/');
    }
}
