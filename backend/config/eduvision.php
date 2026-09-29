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

    // POST /scans upload limits (DESIGN §9.4). The phone sends a WebP page of
    // about 1600 px on the long side (a few hundred KB) and one small WebP per
    // answer area. PHP must allow the whole request: upload_max_filesize >= the
    // page limit, post_max_size >= page + all crops (16M is plenty) and
    // max_file_uploads >= 1 + 2 x answer areas per page (set 100).
    'scans' => [
        'max_page_kb' => max(64, (int) env('SCAN_MAX_PAGE_KB', 4096)),
        'max_crop_kb' => max(16, (int) env('SCAN_MAX_CROP_KB', 1024)),
    ],

    // Whole-page submissions (DESIGN §19.4): files a Classroom hand-in (and
    // later the student's or the teacher's upload) may carry. A PDF counts
    // each of its pages. PHP must allow upload_max_filesize >= max_file_mb
    // and post_max_size >= 55M for the upload endpoints.
    'submissions' => [
        'max_pages' => max(1, (int) env('SUBMISSION_MAX_PAGES', 5)),
        'max_file_mb' => max(1, (int) env('SUBMISSION_MAX_FILE_MB', 10)),
    ],

    // Teachers' documents (DESIGN §19.5): answer keys and question sheets
    // uploaded with POST /documents and read once by Gemini. max_total_mb
    // bounds one read (its files go inline in one request); a read of more
    // than max_pages pages needs a page range (document_too_long). Files are
    // deleted after retention_days by eduvision:purge-images; the read
    // result stays in document_extractions.
    'documents' => [
        'max_file_mb' => max(1, (int) env('DOCUMENT_MAX_FILE_MB', 10)),
        'max_total_mb' => max(1, (int) env('DOCUMENT_MAX_TOTAL_MB', 20)),
        'max_files' => 10,
        'max_pages' => max(1, (int) env('DOCUMENT_MAX_PAGES', 30)),
        'retention_days' => max(1, (int) env('DOCUMENT_RETENTION_DAYS', 30)),
    ],

    // The cron sync with Google Classroom (DESIGN §19.3): at most
    // max_coursework assignments' submissions per round, and no new work
    // started after budget_seconds (the worker pass is 50 s).
    'classroom_sync' => [
        'max_coursework' => max(1, (int) env('CLASSROOM_SYNC_MAX_COURSEWORK', 20)),
        'budget_seconds' => max(1, (int) env('CLASSROOM_SYNC_BUDGET_SECONDS', 40)),
    ],

    // Baht per US dollar for the cost estimate (DESIGN §19.5); empty = no baht figure.
    'usd_thb_rate' => env('USD_THB_RATE'),

    // Sanctum token lifetimes in days per DESIGN §7.4.
    'token_ttl_days' => [
        'teacher' => 30,
        'student' => 180,
    ],
];
