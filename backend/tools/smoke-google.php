<?php

/**
 * Google Classroom leg of tools/smoke.sh: runs API requests and worker passes
 * IN THIS PROCESS with Google's OAuth, Classroom v1 and Drive v3 answered by
 * Http::fake (nothing reaches Google). The HTTP server that smoke.sh starts
 * cannot be faked from outside, and it runs with the Google client unset
 * (503 google_not_configured); here the OAuth client is set to a fake value
 * so the same code paths run as with a connected teacher.
 *
 * The fake Google is a JSON state file that smoke.sh edits between calls:
 *
 *   {"course": {"id", "name", "section"}, "students": [{"id", "name", "email"}],
 *    "course_work": [CourseWork], "submissions": {"<courseWorkId>": [StudentSubmission]},
 *    "drive": {"<fileId>": {"name", "mime", "path"}}, "recorded": [...]}
 *
 * Every Google request is appended to "recorded" as {method, url, body}.
 *
 * Usage (from backend/):
 *   php tools/smoke-google.php STATE request METHOD PATH [JSON]   prints the HTTP status, body to $SMOKE_BODY
 *   php tools/smoke-google.php STATE work                         one queue:work pass
 *
 * The worker pass is the queue:work that eduvision:queue-work runs, without
 * its periodic hooks: a cron-wide ClassroomSyncJob round would ask this fake
 * about every linked classroom of the dev database. The smoke queues the
 * sync of its own classroom with POST /classrooms/{id}/google-sync instead.
 *
 * The bearer token comes from $SMOKE_TOKEN. Local smoke runs only.
 */

use App\Domain\Google\GoogleScopes;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

[$self, $statePath, $command] = array_pad($argv, 3, null);
if ($statePath === null || ! in_array($command, ['request', 'work'], true)) {
    fwrite(STDERR, "usage: php tools/smoke-google.php STATE request METHOD PATH [JSON] | STATE work\n");
    exit(2);
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

config([
    'services.google.client_id' => 'smoke-client.apps.googleusercontent.com',
    'services.google.client_secret' => 'smoke-client-secret-not-real',
    // Each call is its own process: rate limits live in an array store here
    // so these requests do not eat the smoke teacher's per-minute budget.
    'cache.limiter' => 'array',
]);

$state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
$state['recorded'] ??= [];

$json = fn (array $body, int $status = 200) => Http::response($body, $status);
$notFound = fn () => Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND']], 404);

Http::fake(function (ClientRequest $request) use (&$state, $json, $notFound) {
    $url = $request->url();
    $path = (string) parse_url($url, PHP_URL_PATH);
    $host = (string) parse_url($url, PHP_URL_HOST);
    $state['recorded'][] = ['method' => $request->method(), 'url' => $url, 'body' => $request->data()];
    $course = $state['course'];
    $cid = preg_quote(rawurlencode((string) $course['id']), '#');

    if ($host === 'oauth2.googleapis.com') {
        return $json(str_ends_with($path, '/token') ? [
            'access_token' => 'ya29.smoke-access-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => implode(' ', GoogleScopes::REQUIRED),
            'refresh_token' => '1//smoke-refresh-token',
        ] : []);
    }
    if ($host === 'www.googleapis.com' && preg_match('#/drive/v3/files/([^/]+)$#', $path, $m)) {
        $file = $state['drive'][rawurldecode($m[1])] ?? null;
        if ($file === null) {
            return $notFound();
        }
        $bytes = (string) file_get_contents($file['path']);
        if (str_contains($url, 'alt=media')) {
            return Http::response($bytes, 200, ['Content-Type' => $file['mime']]);
        }

        return $json(['mimeType' => $file['mime'], 'name' => $file['name'], 'size' => (string) strlen($bytes)]);
    }
    if ($host !== 'classroom.googleapis.com') {
        return $notFound();
    }
    if ($path === '/v1/userProfiles/me') {
        return $json(['id' => 'smoke-teacher-'.$course['id'], 'name' => ['fullName' => 'ครูทดสอบ smoke'], 'emailAddress' => 'smoke-teacher@example.com']);
    }
    if ($path === '/v1/courses') {
        return $json(['courses' => [['id' => $course['id'], 'name' => $course['name'], 'section' => $course['section'], 'courseState' => 'ACTIVE', 'alternateLink' => 'https://classroom.google.com/c/smoke']]]);
    }
    if (preg_match("#^/v1/courses/{$cid}/students$#", $path)) {
        return $json(['students' => array_map(fn (array $s) => [
            'courseId' => $course['id'],
            'userId' => $s['id'],
            'profile' => ['id' => $s['id'], 'name' => ['fullName' => $s['name']], 'emailAddress' => $s['email']],
        ], $state['students'])]);
    }
    if (preg_match("#^/v1/courses/{$cid}/courseWork/([^/]+)/studentSubmissions$#", $path, $m)) {
        return $json(['studentSubmissions' => $state['submissions'][rawurldecode($m[1])] ?? []]);
    }
    if (preg_match("#^/v1/courses/{$cid}/courseWork/([^/]+)/studentSubmissions/([^/]+)$#", $path) && $request->method() === 'PATCH') {
        return $json($request->data());
    }
    if (preg_match("#^/v1/courses/{$cid}/courseWork$#", $path)) {
        return $json(['courseWork' => $state['course_work']]);
    }
    if (preg_match("#^/v1/courses/{$cid}/announcements$#", $path) && $request->method() === 'POST') {
        $n = count(array_filter($state['recorded'], fn (array $r) => str_ends_with((string) parse_url($r['url'], PHP_URL_PATH), '/announcements')));

        return $json(['id' => "smoke-announcement-{$course['id']}-{$n}", 'alternateLink' => "https://classroom.google.com/c/smoke/p/{$n}", 'state' => 'PUBLISHED']);
    }

    return $notFound();
});

$exit = 0;
if ($command === 'work') {
    $exit = Artisan::call('queue:work', [
        '--queue' => 'grading,default,pdf',
        '--stop-when-empty' => true,
        '--max-time' => 50,
    ]);
    echo Artisan::output();
} else {
    [$method, $path, $body] = array_pad(array_slice($argv, 3), 3, '');
    $server = ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
    if (($token = getenv('SMOKE_TOKEN')) !== false && $token !== '') {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }
    if ($body !== '') {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $kernel = $app->make(HttpKernel::class);
    $request = Request::create('/api/v1'.$path, strtoupper($method), [], [], [], $server, $body !== '' ? $body : null);
    $response = $kernel->handle($request);
    file_put_contents((string) getenv('SMOKE_BODY'), (string) $response->getContent());
    echo $response->getStatusCode();
    $kernel->terminate($request, $response);
}

// Keep the maps JSON objects when empty (json_encode writes [] for an empty array).
$state['drive'] = (object) ($state['drive'] ?? []);
$state['submissions'] = (object) ($state['submissions'] ?? []);
file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
exit($exit);
