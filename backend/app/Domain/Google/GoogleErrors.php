<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;

/**
 * Turns a GoogleApiException into the API error the app handles by `code`
 * (DESIGN §9, §18.6), with a Thai message for the teacher. The app switches
 * its Google card to "ต้องเชื่อมใหม่" on google_not_connected and
 * google_reconnect_required (app/lib/features/google_classroom/google_repository.dart).
 */
final class GoogleErrors
{
    public static function toApi(GoogleApiException $e, ?string $notFound = null): ApiException
    {
        return match ($e->kind) {
            GoogleApiException::NOT_CONFIGURED => self::notConfigured(),
            GoogleApiException::INVALID_GRANT => self::reconnectRequired(),
            GoogleApiException::SCOPE_MISSING => new ApiException(
                'บัญชี Google ไม่ได้ให้สิทธิ์ครบตามที่แอปต้องใช้ ไปที่ ตั้งค่า → Google Classroom แล้วกดเชื่อมใหม่ และติ๊กอนุญาตทุกข้อ',
                'google_scope_missing',
                422,
            ),
            GoogleApiException::PROJECT_PERMISSION_DENIED => new ApiException(
                'งานนี้ไม่ได้สร้างจากแอป EduVision จึงส่งคะแนนหรือส่งคืนงานผ่านแอปไม่ได้ ต้องสั่งงานด้วยปุ่ม "โพสต์ลง Classroom" ในแอป',
                'project_permission_denied',
                409,
            ),
            GoogleApiException::PERMISSION_DENIED => new ApiException(
                'Google ไม่อนุญาตให้ทำรายการนี้ บัญชี Google ที่เชื่อมไว้อาจไม่ได้เป็นครูของคอร์สนี้',
                'google_permission_denied',
                409,
            ),
            GoogleApiException::API_DISABLED => new ApiException(
                'ยังใช้ Google Classroom API หรือ Drive API ไม่ได้ (ยังไม่ได้เปิดใน Google Cloud project หรือโรงเรียนปิดไว้) กรุณาแจ้งผู้ดูแลระบบ',
                'google_api_disabled',
                503,
            ),
            GoogleApiException::NOT_FOUND => new ApiException(
                $notFound ?? 'ไม่พบข้อมูลนี้ใน Google Classroom แล้ว อาจถูกลบไปแล้ว',
                'google_not_found',
                409,
            ),
            GoogleApiException::FAILED_PRECONDITION => new ApiException(
                'Google Classroom ไม่ยอมให้ทำรายการนี้กับงานในสถานะปัจจุบัน'.self::detail($e),
                'google_failed_precondition',
                409,
            ),
            GoogleApiException::UNAVAILABLE => new ApiException(
                'ติดต่อ Google ไม่ได้ในขณะนี้ ลองใหม่อีกครั้งในอีกสักครู่',
                'google_unavailable',
                503,
            ),
            default => new ApiException(
                'Google ปฏิเสธคำขอ'.self::detail($e),
                'google_error',
                502,
            ),
        };
    }

    public static function notConfigured(): ApiException
    {
        return new ApiException(
            'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom (GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET) กรุณาแจ้งผู้ดูแลระบบ',
            'google_not_configured',
            503,
        );
    }

    public static function notConnected(): ApiException
    {
        return new ApiException(
            'ยังไม่ได้เชื่อมบัญชี Google ไปที่ ตั้งค่า → Google Classroom ก่อน',
            'google_not_connected',
            409,
        );
    }

    public static function reconnectRequired(): ApiException
    {
        return new ApiException(
            'สิทธิ์ที่ให้ Google ไว้หมดอายุหรือถูกยกเลิกแล้ว ไปที่ ตั้งค่า → Google Classroom แล้วกด "เชื่อมใหม่"',
            'google_reconnect_required',
            409,
        );
    }

    /**
     * A short Thai sentence for google_accounts / import rows (last_error is
     * shown to the teacher in the submissions list).
     */
    public static function shortText(GoogleApiException $e): string
    {
        return match ($e->kind) {
            GoogleApiException::NOT_CONFIGURED => 'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom',
            GoogleApiException::INVALID_GRANT => 'ต้องเชื่อมบัญชี Google ใหม่ แล้วกดส่งคะแนนอีกครั้ง',
            GoogleApiException::SCOPE_MISSING => 'บัญชี Google ไม่ได้ให้สิทธิ์ครบ ต้องเชื่อมใหม่',
            GoogleApiException::PROJECT_PERMISSION_DENIED => 'งานนี้ไม่ได้สร้างจากแอป จึงส่งคะแนนกลับไม่ได้',
            GoogleApiException::PERMISSION_DENIED => 'Google ไม่อนุญาต (บัญชีอาจไม่ได้เป็นครูของคอร์ส)',
            GoogleApiException::API_DISABLED => 'Google Classroom API ถูกปิดอยู่',
            GoogleApiException::NOT_FOUND => 'ไม่พบงานหรือการส่งงานนี้ใน Google Classroom แล้ว',
            GoogleApiException::FAILED_PRECONDITION => 'Classroom ไม่รับคะแนนในสถานะปัจจุบันของงาน',
            GoogleApiException::UNAVAILABLE => 'ติดต่อ Google ไม่ได้ ลองส่งคะแนนอีกครั้งภายหลัง',
            default => mb_substr('Google ปฏิเสธคำขอ'.self::detail($e), 0, 250),
        };
    }

    private static function detail(GoogleApiException $e): string
    {
        return $e->googleMessage !== null && $e->googleMessage !== '' ? ': '.$e->googleMessage : '';
    }
}
