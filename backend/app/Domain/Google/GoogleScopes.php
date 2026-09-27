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
 *   drive.readonly                    the phone downloads the students' pictures
 */
final class GoogleScopes
{
    public const PREFIX = 'https://www.googleapis.com/auth/';

    public const REQUIRED = [
        self::PREFIX.'classroom.courses.readonly',
        self::PREFIX.'classroom.rosters.readonly',
        self::PREFIX.'classroom.profile.emails',
        self::PREFIX.'classroom.coursework.students',
        self::PREFIX.'drive.file',
        self::PREFIX.'drive.readonly',
    ];

    /** What each scope is for, in Thai (the browser flow's error page). */
    public const LABELS = [
        self::PREFIX.'classroom.courses.readonly' => 'ดูรายการคอร์สที่สอนใน Google Classroom',
        self::PREFIX.'classroom.rosters.readonly' => 'ดูรายชื่อนักเรียนในคอร์ส',
        self::PREFIX.'classroom.profile.emails' => 'ดูอีเมลของนักเรียนในคอร์ส',
        self::PREFIX.'classroom.coursework.students' => 'สร้างงาน ดูงานที่ส่ง ให้คะแนน และส่งคืนงานใน Classroom',
        self::PREFIX.'drive.file' => 'อัปโหลดใบงานที่แอปสร้างขึ้นไปที่ Google Drive',
        self::PREFIX.'drive.readonly' => 'เปิดไฟล์ใน Google Drive (รูปงานที่นักเรียนแนบมา)',
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
