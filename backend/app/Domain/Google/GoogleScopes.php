<?php

namespace App\Domain\Google;

/**
 * The OAuth scopes the teacher grants (DESIGN §18.5). The app asks for the
 * same list (app/lib/features/google_classroom/google_config.dart); the
 * server refuses a connection that lacks any of them (422
 * google_scope_missing), because each one backs a feature:
 *
 *   classroom.courses.readonly        GET /google/courses
 *   classroom.rosters.readonly        GET /classrooms/{id}/google-roster
 *   classroom.profile.emails          ... with the students' e-mail addresses
 *   classroom.coursework.students     post courseWork, read submissions, grade, return
 *   drive.file                        upload the spare worksheet PDF
 *   drive.readonly                    the server downloads the students' pictures
 *   classroom.announcements           the per-student private announcement of a
 *                                     published result (DESIGN §19.7) and the
 *                                     announcements of class posts (DESIGN §30.4)
 *   classroom.courseworkmaterials     the materials of class posts (DESIGN §30.4)
 *   classroom.topics                  the topics the posts are filed under (DESIGN §30.4)
 *
 * An account connected before a scope was added lacks it: it counts as
 * needs_reconnect (GoogleAccount::needsReconnect(), and the migration that
 * added the scope marks it scope_missing) until the teacher connects again.
 */
final class GoogleScopes
{
    public const PREFIX = 'https://www.googleapis.com/auth/';

    /** courses.announcements.create (DESIGN §19.7), added in Phase 8 build step 6. */
    public const ANNOUNCEMENTS = self::PREFIX.'classroom.announcements';

    /** courseWorkMaterials and topics of class posts (DESIGN §30.4), added in §30 step 2. */
    public const MATERIALS = self::PREFIX.'classroom.courseworkmaterials';

    public const TOPICS = self::PREFIX.'classroom.topics';

    /** The scopes of class posts: an account missing any of them connects again (§30.4). */
    public const CLASS_POSTS = [self::ANNOUNCEMENTS, self::MATERIALS, self::TOPICS];

    public const REQUIRED = [
        self::PREFIX.'classroom.courses.readonly',
        self::PREFIX.'classroom.rosters.readonly',
        self::PREFIX.'classroom.profile.emails',
        self::PREFIX.'classroom.coursework.students',
        self::PREFIX.'drive.file',
        self::PREFIX.'drive.readonly',
        self::ANNOUNCEMENTS,
        self::MATERIALS,
        self::TOPICS,
    ];

    /** What each scope is for, in Thai (the browser flow's error page). */
    public const LABELS = [
        self::PREFIX.'classroom.courses.readonly' => 'ดูรายการคอร์สที่สอนใน Google Classroom',
        self::PREFIX.'classroom.rosters.readonly' => 'ดูรายชื่อนักเรียนในคอร์ส',
        self::PREFIX.'classroom.profile.emails' => 'ดูอีเมลของนักเรียนในคอร์ส',
        self::PREFIX.'classroom.coursework.students' => 'สร้างงาน ดูงานที่ส่ง ให้คะแนน และส่งคืนงานใน Classroom',
        self::PREFIX.'drive.file' => 'อัปโหลดไฟล์ที่ครูแนบในโพสต์ไปที่ Google Drive ของครู',
        self::PREFIX.'drive.readonly' => 'เปิดไฟล์ใน Google Drive (รูปงานที่นักเรียนแนบมา)',
        self::ANNOUNCEMENTS => 'ส่งประกาศถึงนักเรียนใน Classroom (ประกาศของห้องและผลตรวจรายคน)',
        self::MATERIALS => 'ส่งเอกสารประกอบของห้องไป Classroom',
        self::TOPICS => 'จัดหัวข้อใน Classroom ตามหน่วยการเรียนรู้',
    ];

    public static function label(string $scope): string
    {
        return self::LABELS[$scope] ?? $scope;
    }

    /**
     * @param  list<string>  $granted
     * @return list<string> required scopes that were not granted
     */
    public static function missing(array $granted): array
    {
        return array_values(array_diff(self::REQUIRED, $granted));
    }

    /**
     * @return list<string>
     */
    public static function parse(?string $scope): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/', trim((string) $scope)) ?: [])));
    }
}
