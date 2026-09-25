<?php

/*
|--------------------------------------------------------------------------
| EduVision application settings
|--------------------------------------------------------------------------
| Read these through config('eduvision.*'), never env() directly: production
| runs `config:cache`, after which env() returns null outside config files.
*/

return [
    // Join code of the school created by SchoolSeeder. The public repo must
    // never contain the production value; set SEED_TEACHER_JOIN_CODE in .env.
    'seed_teacher_join_code' => env('SEED_TEACHER_JOIN_CODE', 'DEMO2569'),

    // The queue heartbeat (QueueHeartbeatJob) is considered fresh for this many
    // minutes. The Plesk Scheduled Task runs every minute, so 3 tolerates two
    // missed runs before /api/v1/health reports "degraded".
    'heartbeat_max_age_minutes' => (int) env('HEARTBEAT_MAX_AGE_MINUTES', 3),

    // System admin created by AdminSeeder (`php artisan db:seed`). Both values
    // empty = the seeder does nothing, so a deploy never creates an account
    // with a known password.
    'admin_email' => env('ADMIN_EMAIL'),
    'admin_password' => env('ADMIN_PASSWORD'),

    // HMAC-SHA256 key that signs the worksheet QR (DESIGN §5.4). Only the
    // server can verify a QR, so a forged page cannot land in another
    // student's submission. Rotating it invalidates every worksheet printed
    // before the rotation. Generate with: php -r "echo bin2hex(random_bytes(32));"
    'qr_signing_key' => env('QR_SIGNING_KEY'),

    // Worksheet PDFs (DESIGN §5.5): RenderWorksheetsJob renders this many
    // students per job so each job ends well inside the 50-second worker pass.
    'worksheets' => [
        'batch_size' => max(1, (int) env('WORKSHEET_BATCH_SIZE', 10)),
    ],

    // Sanctum token lifetimes in days per DESIGN §7.4.
    'token_ttl_days' => [
        'teacher' => 30,
        'student' => 180,
    ],
];
