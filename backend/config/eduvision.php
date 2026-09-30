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

    // Whole-page submissions (DESIGN §19.4, §19.6): files a Classroom
    // hand-in, a student's hand-in in the app or a teacher's upload may carry. A PDF counts
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

    // Answers decided by code before any Gemini call (DESIGN §21.3):
    // - blank_ink_max: an answer box whose ink_ratio is below this gets 0
    //   points as "ไม่ได้ตอบ" (auto_rule blank_ink) and lands in the `look`
    //   band. Stricter than the 0.02 the review priority uses (§11.8).
    // - cnn_skip_*: a numeric `short` answer the on-device digit reader
    //   reads with at least min_confidence, exactly as an accepted answer,
    //   gets full marks as "อ่านด้วย CNN" (auto_rule cnn_match); sample_rate
    //   of them go to the `look` band for the teacher. OFF until the
    //   calibration harness (§21.10) passes on the team's real handwriting.
    'grading' => [
        'blank_ink_max' => (float) env('GRADING_BLANK_INK_MAX', 0.005),
        'cnn_skip_enabled' => (bool) env('GRADING_CNN_SKIP_ENABLED', false),
        'cnn_skip_min_confidence' => (float) env('GRADING_CNN_SKIP_MIN_CONFIDENCE', 0.97),
        'cnn_skip_sample_rate' => (float) env('GRADING_CNN_SKIP_SAMPLE_RATE', 0.10),
    ],

    // "ผ่าน" of an indicator (DESIGN §20.3): mastery >= pass_threshold. 0.5 is
    // the 50% pass mark Thai schools use.
    'mastery' => [
        'pass_threshold' => min(1.0, max(0.0, (float) env('MASTERY_PASS_THRESHOLD', 0.5))),
    ],

    // The nightly student analysis through the Gemini Batch API (DESIGN §20.8):
    // at most batch_max inline requests per batch (one batch per key).
    'analysis' => [
        'batch_max' => max(1, (int) env('ANALYSIS_BATCH_MAX', 200)),
    ],

    // Baht per US dollar for the cost estimate (DESIGN §19.5); empty = no baht figure.
    'usd_thb_rate' => env('USD_THB_RATE'),

    // Exams (DESIGN §22.2, §22.5): at most max_questions questions in
    // max_sections sections, 1..max_versions shuffled versions (ก ข ค ง),
    // at most max_blank_questions blank questions per section request, and
    // question/option images up to image_max_kb, stored as JPEG with the
    // long side at most image_max_px.
    'exams' => [
        'max_questions' => 200,
        'max_sections' => 10,
        'max_versions' => max(1, min(10, (int) env('EXAM_MAX_VERSIONS', 4))),
        'max_blank_questions' => 100,
        'image_max_kb' => 5120,
        'image_max_px' => 1600,
        // Answer sheets rendered per RenderAnswerSheetsJob (DESIGN §22.6: 20 students).
        'sheet_batch_size' => max(1, (int) env('EXAM_SHEET_BATCH_SIZE', 20)),
    ],

    // Gradebook (DESIGN §23.2): the templates a teacher starts a course's
    // categories from (then edits freely; no table), and the default cutoffs
    // of grades 4, 3.5, 3, 2.5, 2, 1.5 and 1 (below the last one: 0).
    'gradebook' => [
        'default_cutoffs' => [80, 75, 70, 65, 60, 55, 50],
        'templates' => [
            'collect_final' => [
                'name' => 'คะแนนเก็บ 70 : ปลายภาค 30',
                'categories' => [
                    ['name' => 'คะแนนเก็บ', 'weight' => 70, 'is_homework_default' => true],
                    ['name' => 'ปลายภาค', 'weight' => 30, 'is_homework_default' => false],
                ],
            ],
            'hw_mid_final_affective' => [
                'name' => 'การบ้าน 30, กลางภาค 20, ปลายภาค 30, จิตพิสัย 20',
                'categories' => [
                    ['name' => 'การบ้าน', 'weight' => 30, 'is_homework_default' => true],
                    ['name' => 'กลางภาค', 'weight' => 20, 'is_homework_default' => false],
                    ['name' => 'ปลายภาค', 'weight' => 30, 'is_homework_default' => false],
                    ['name' => 'จิตพิสัย', 'weight' => 20, 'is_homework_default' => false],
                ],
            ],
        ],
    ],

    // Sanctum token lifetimes in days per DESIGN §7.4.
    'token_ttl_days' => [
        'teacher' => 30,
        'student' => 180,
    ],
];
