<?php

namespace App\Domain\Auth\Google;

use App\Exceptions\ApiException;

/**
 * The API errors of Google sign-in (DESIGN §24.9, §24.12 C), each with a
 * Thai message the app may show as is. The app branches on `code`.
 */
final class GoogleSignInErrors
{
    public static function notConfigured(): ApiException
    {
        return new ApiException(
            'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google กรุณาใช้รหัสผ่าน PIN หรือบัตร QR',
            'google_signin_not_configured',
            503,
        );
    }

    public static function webNotConfigured(): ApiException
    {
        return new ApiException(
            'เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google ทางเว็บ กรุณาใช้แอปบน Android หรือรหัสผ่าน',
            'google_signin_web_not_configured',
            503,
        );
    }

    public static function unavailable(): ApiException
    {
        return new ApiException('ติดต่อ Google ไม่ได้ในขณะนี้ ลองใหม่อีกครั้งในอีกสักครู่', 'google_unavailable', 503);
    }

    /** One neutral message whatever check failed (DESIGN §24.9.2 step 9). */
    public static function tokenInvalid(): ApiException
    {
        return new ApiException(
            'ยืนยันบัญชี Google ไม่สำเร็จ กรุณาลองเข้าสู่ระบบด้วย Google อีกครั้ง',
            'google_token_invalid',
            422,
            ['id_token' => ['ยืนยันบัญชี Google ไม่สำเร็จ']],
        );
    }

    public static function emailUnverified(): ApiException
    {
        return new ApiException(
            'อีเมลของบัญชี Google นี้ยังไม่ได้รับการยืนยัน กรุณาใช้บัญชีอื่น',
            'google_email_unverified',
            422,
            ['id_token' => ['อีเมลของบัญชี Google ยังไม่ได้รับการยืนยัน']],
        );
    }

    public static function domainNotAllowed(): ApiException
    {
        return new ApiException(
            'โรงเรียนไม่อนุญาตให้ใช้บัญชี Google ของโดเมนนี้ กรุณาใช้บัญชี Google ของโรงเรียน',
            'google_domain_not_allowed',
            403,
        );
    }

    public static function studentDisabled(): ApiException
    {
        return new ApiException(
            'โรงเรียนยังไม่เปิดให้นักเรียนเข้าสู่ระบบด้วย Google กรุณาใช้บัตร QR หรือ PIN',
            'student_google_disabled',
            403,
        );
    }

    public static function alreadyLinked(): ApiException
    {
        return new ApiException(
            'บัญชี Google นี้เชื่อมกับผู้ใช้อื่นอยู่แล้ว กรุณาใช้บัญชี Google อื่น',
            'google_already_linked',
            409,
        );
    }

    public static function identityExists(): ApiException
    {
        return new ApiException(
            'บัญชีนี้เชื่อมกับบัญชี Google อื่นอยู่แล้ว ยกเลิกการเชื่อมเดิมก่อนแล้วจึงเชื่อมใหม่',
            'google_identity_exists',
            409,
        );
    }

    /** A teacher made by Google sign-up (#71) has no password to fall back on. */
    public static function unlinkNeedsPassword(): ApiException
    {
        return new ApiException(
            'บัญชีนี้ยังไม่มีรหัสผ่าน ถ้ายกเลิกการเชื่อม Google จะเข้าสู่ระบบไม่ได้ ขอให้ผู้ดูแลโรงเรียนตั้งรหัสผ่านให้ก่อน',
            'google_unlink_needs_password',
            409,
        );
    }

    public static function noticeRequired(): ApiException
    {
        return new ApiException(
            'กรุณาอ่านและกดยอมรับข้อความแจ้งเรื่องข้อมูลส่วนบุคคลก่อนเชื่อมบัญชี Google',
            'notice_required',
            422,
            ['accept_notice' => ['ต้องยอมรับข้อความแจ้งก่อนเชื่อมบัญชี Google']],
        );
    }

    public static function linkTicketInvalid(string $field = 'link_ticket'): ApiException
    {
        return new ApiException(
            'การยืนยันบัญชี Google หมดอายุหรือถูกใช้ไปแล้ว กรุณากดเข้าสู่ระบบด้วย Google อีกครั้ง',
            'link_ticket_invalid',
            422,
            [$field => ['การยืนยันบัญชี Google หมดอายุหรือถูกใช้ไปแล้ว']],
        );
    }

    /** The web link ticket is unknown, expired, spent or was made for another user. */
    public static function webLinkTicketInvalid(): ApiException
    {
        return new ApiException(
            'ลิงก์เชื่อมบัญชี Google หมดอายุ ถูกใช้ไปแล้ว หรือเปิดจากบัญชีอื่น กดเชื่อมบัญชี Google ใหม่จากหน้าตั้งค่าหรือหน้าบัญชีของฉัน',
            'google_ticket_invalid',
            422,
            ['ticket' => ['ลิงก์เชื่อมบัญชี Google ใช้ไม่ได้แล้ว']],
        );
    }

    public static function ticketInvalid(): ApiException
    {
        return new ApiException(
            'ลิงก์เข้าสู่ระบบด้วย Google หมดอายุหรือถูกใช้ไปแล้ว กรุณาลองใหม่',
            'google_ticket_invalid',
            422,
            ['ticket' => ['ลิงก์เข้าสู่ระบบหมดอายุหรือถูกใช้ไปแล้ว']],
        );
    }

    public static function accountNotActive(string $status): ApiException
    {
        return new ApiException(
            $status === 'pending' ? 'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ' : 'บัญชีของคุณถูกระงับการใช้งาน',
            'account_not_active',
            403,
        );
    }

    /**
     * 404 google_not_linked: the Google account is linked to nobody.
     *
     * @param  array<string, mixed>  $extra  link_ticket and registration (DESIGN §24.9.3)
     */
    public static function notLinked(string $message, array $extra = []): ApiException
    {
        return new ApiException($message, 'google_not_linked', 404, [], $extra);
    }
}
