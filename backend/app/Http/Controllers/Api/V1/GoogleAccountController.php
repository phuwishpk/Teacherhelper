<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Google\GoogleAccounts;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleOAuth;
use App\Domain\Google\GoogleOAuthStates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleConnectRequest;
use App\Models\ClassroomGoogleLink;
use App\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The signed-in teacher's Google account (DESIGN §18.5, §18.6). Answers
 * {data: {connected, email, scopes: [...], needs_reconnect, last_error,
 * connected_at, configured, server_configured}}; no token ever leaves the
 * server.
 */
class GoogleAccountController extends Controller
{
    public function __construct(private readonly GoogleAccounts $accounts) {}

    /** GET /api/v1/google/status */
    public function status(Request $request): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);

        return response()->json(['data' => $this->accounts->status($request->user())]);
    }

    /**
     * POST /api/v1/google/connect {server_auth_code}: 422 google_code_invalid
     * (code used/expired), google_scope_missing (errors.scopes = the missing
     * ones), google_refresh_token_missing; 409 google_account_in_use;
     * 503 google_not_configured / google_unavailable.
     */
    public function connect(GoogleConnectRequest $request): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);

        return response()->json(['data' => $this->accounts->connect($request->user(), (string) $request->validated('server_auth_code'))]);
    }

    /**
     * POST /api/v1/google/oauth/url -> {data: {url}}: Google's consent page
     * for the browser flow, for devices without the app's server auth code
     * (Flutter web, a build without GOOGLE_SERVER_CLIENT_ID). The app opens
     * it in a browser; Google returns to GET /google/oauth/callback
     * (GoogleOAuthCallbackController) with a single-use state that maps to
     * this teacher for 10 minutes. The app polls GET /google/status.
     */
    public function oauthUrl(Request $request, GoogleOAuth $oauth, GoogleOAuthStates $states): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        $teacher = $request->user();

        $url = $oauth->authorizationUrl($states->issue($teacher));
        Log::info('google.oauth_url_issued', ['user_id' => $teacher->id]);

        return response()->json(['data' => ['url' => $url]]);
    }

    /** DELETE /api/v1/google/disconnect: revoke at Google (best effort) and forget the account. */
    public function disconnect(Request $request): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);

        return response()->json(['data' => $this->accounts->disconnect($request->user())]);
    }

    /**
     * GET /api/v1/google/courses -> {data: [{course_id, name, section,
     * linked_classroom_id, linked_classroom}]}: ACTIVE courses the teacher
     * teaches. linked_classroom = {id, name} of the classroom already linked
     * to the course (DESIGN §19.2: shown faded, cannot be imported);
     * for another teacher's classroom (a co-taught course) id is null and
     * name is generic, and linked_classroom_id (kept for older clients)
     * names only the teacher's own classroom (or the one the teacher linked
     * the course to as a subject teacher, DESIGN §24.10).
     */
    public function courses(Request $request): JsonResponse
    {
        Gate::authorize('manage', GoogleAccount::class);
        $teacher = $request->user();

        $courses = $this->accounts->call($teacher, fn (GoogleApi $api) => $api->teacherCourses());
        $links = ClassroomGoogleLink::query()
            ->with('classroom:id,teacher_id,name')
            ->whereIn('course_id', array_column($courses, 'course_id'))
            ->get()
            ->keyBy('course_id');

        return response()->json(['data' => array_map(function (array $course) use ($links, $teacher) {
            $link = $links[$course['course_id']] ?? null;
            $classroom = $link?->classroom;
            // The teacher's own classroom, or one they linked their course to as a subject teacher (§24.10).
            $own = $classroom !== null && ($classroom->teacher_id === $teacher->id || $link->owner_user_id === $teacher->id);

            return [
                ...$course,
                'linked_classroom_id' => $own ? $classroom->id : null,
                'linked_classroom' => $classroom === null ? null : [
                    'id' => $own ? $classroom->id : null,
                    'name' => $own ? $classroom->name : 'ห้องเรียนของครูท่านอื่น',
                ],
            ];
        }, $courses)]);
    }
}
