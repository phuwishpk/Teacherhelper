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
];
