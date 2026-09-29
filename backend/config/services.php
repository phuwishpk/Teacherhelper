<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Gemini (DESIGN §10). Called with the Laravel HTTP client, no SDK.
    | api_key is the optional server-wide key: a teacher's own key (stored in
    | teacher_api_keys) always comes first, and with neither the answer goes
    | to the teacher as `manual` / ai_key_missing (GeminiKeyResolver).
    | fake = true swaps in FakeGeminiClient: offline, deterministic, free. It
    | still needs "a key" (any teacher key or GEMINI_API_KEY value) so the
    | missing-key flow behaves like production. Ignored in production
    | (AppServiceProvider binds the real client and logs an error).
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'fake' => (bool) env('GEMINI_FAKE', false),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'concurrency' => (int) env('GEMINI_CONCURRENCY', 8),
        // Gemini 3+ replaces temperature with thinking levels (low | medium | high).
        // Leave empty for a model without thinking levels.
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
        // Gemini 3+ deprecates temperature/top_p/top_k. The per-purpose values of
        // DESIGN §10.1 stay in the prompt files and are sent only when this is true
        // (for a 2.x model).
        'send_temperature' => (bool) env('GEMINI_SEND_TEMPERATURE', false),
        // Media resolution per image part (DESIGN §21.5): low | medium | high.
        // Every student image stays `high` until the calibration harness
        // (§21.10) shows a lower level reads as well; then lower it here.
        'media' => [
            'short' => env('GEMINI_MEDIA_SHORT', 'high'),       // short crops and the show_work final-answer box (target low)
            'work' => env('GEMINI_MEDIA_WORK', 'high'),         // show_work and open crops (target medium)
            'page' => env('GEMINI_MEDIA_PAGE', 'high'),         // every whole page of student work, PDF pages included
            'document' => env('GEMINI_MEDIA_DOCUMENT', 'medium'), // teachers' documents only
        ],
        // true: send mediaResolution on each part (needs an API version that
        // accepts it, e.g. GEMINI_BASE_URL=.../v1alpha). false (default):
        // generationConfig.mediaResolution per call at the highest level of
        // the call's parts (the fallback of §21.5, works on v1beta).
        'media_per_part' => (bool) env('GEMINI_MEDIA_PER_PART', false),
        // At most this many questions per extract_batch / extract_page call (§21.4).
        'page_max_questions' => (int) env('GEMINI_PAGE_MAX_QUESTIONS', 15),
    ],

    /*
    | Firebase Cloud Messaging (DESIGN §7.6, §9.9), HTTP v1 API with a service
    | account (firebase/php-jwt signs the OAuth assertion; no Firebase SDK).
    | credentials: path to the service-account JSON key, outside the document
    | root (relative paths resolve against the Laravel base path). Empty = no
    | pushes: LogNotifier writes them to the log instead.
    | project_id: optional override of the key file's project_id.
    */
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 10),
    ],

    /*
    | Google Classroom + Drive (DESIGN §18), REST through the Laravel HTTP
    | client (no google/apiclient). client_id / client_secret: the OAuth
    | client of type "Web application" (KICKOFF part 6, G4). Two ways to
    | connect a teacher, both exchanged here: the Android app sends its
    | server auth code to POST /google/connect (no redirect URI), or any
    | device opens the consent page from POST /google/oauth/url in a browser
    | and Google comes back to GET /google/oauth/callback. The secret stays
    | on the server. Both empty = Classroom is off: the endpoints answer 503
    | google_not_configured.
    | redirect_uri: the browser flow's callback. Register exactly this value
    | under "Authorized redirect URIs" of the Web client. Empty = APP_URL +
    | /google/oauth/callback.
    | app_link: optional link (Play Store page, school site) added to the
    | instructions of every courseWork, where students find the per-question
    | explanations.
    */
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/google/oauth/callback',
        'timeout' => (int) env('GOOGLE_TIMEOUT', 20),
        'app_link' => env('GOOGLE_CLASSROOM_APP_LINK'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
