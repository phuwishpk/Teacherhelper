<?php

namespace App\Domain\Google;

use App\Models\GoogleEvent;
use App\Models\UserGoogleIdentity;

/**
 * Thai wording of a `google_events` row for the admin (DESIGN §32): what
 * happened, and what to do about it when it is a problem.
 */
final class GoogleEventLabels
{
    public const FLOWS = [
        GoogleEvent::FLOW_SIGNIN => 'เข้าสู่ระบบด้วย Google',
        GoogleEvent::FLOW_CLASSROOM => 'Google Classroom',
    ];

    public const VIA = [
        UserGoogleIdentity::VIA_TEACHER_EMAIL => 'อีเมลตรงกับบัญชีครู',
        UserGoogleIdentity::VIA_REGISTRATION => 'ตอนสมัคร',
        UserGoogleIdentity::VIA_SELF => 'เชื่อมเองในแอป',
        UserGoogleIdentity::VIA_PIN_CONFIRM => 'ยืนยันด้วยรหัสผ่านหรือบัตร QR',
        UserGoogleIdentity::VIA_CLASSROOM_ROSTER => 'จับคู่จากรายชื่อ Classroom',
        UserGoogleIdentity::VIA_GOOGLE_SIGNUP => 'สมัครด้วย Google',
    ];

    /** Results that are not problems (shown in grey, left out of the "problems only" filter). */
    public const OK_RESULTS = ['ok', 'login_ticket', 'link_ticket'];

    public static function isProblem(GoogleEvent $event): bool
    {
        return ! in_array($event->result, self::OK_RESULTS, true);
    }

    /** @return array{title: string, advice: ?string} */
    public static function describe(GoogleEvent $event): array
    {
        $detail = (string) $event->detail;
        [$title, $advice] = match ($event->flow) {
            GoogleEvent::FLOW_CLASSROOM => self::classroom($event->event, $event->result, $detail),
            default => self::signin($event->event, $event->result, $detail),
        };

        return ['title' => $title, 'advice' => $advice];
    }

    /** @return array{0: string, 1: ?string} */
    private static function signin(string $event, string $result, string $detail): array
    {
        return match (true) {
            $result === 'google_not_linked' && $detail === 'unknown_student' => [
                'บัญชี Google นี้ยังไม่ได้ผูกกับนักเรียนคนไหน (แท็บนักเรียน)',
                'ถ้าห้องผูก Google Classroom ให้ครูกด "ซิงก์รายชื่อ" และตรวจว่าอีเมลนี้คือบัญชีที่อยู่ในคอร์ส ไม่อย่างนั้นให้นักเรียนยืนยันตัวตนครั้งแรกด้วยชื่อผู้ใช้และรหัสผ่าน หรือเข้าห้องด้วยรหัสห้อง',
            ],
            $result === 'google_not_linked' && $detail === 'admin_email' => [
                'อีเมลนี้เป็นของผู้ดูแลระบบ จึงไม่เชื่อมอัตโนมัติ',
                'เข้าสู่ระบบด้วยรหัสผ่าน แล้วเชื่อม Google ในหน้าผู้ดูแลระบบ',
            ],
            $result === 'google_not_linked' && $detail === 'other_identity' => [
                'ครูที่ใช้อีเมลนี้ผูกกับบัญชี Google อื่นอยู่แล้ว',
                'ใช้บัญชี Google ที่ผูกไว้เดิม หรือเข้าด้วยรหัสผ่านแล้วยกเลิกการเชื่อมเดิมก่อน',
            ],
            $result === 'google_not_linked' => ['บัญชี Google นี้ยังไม่ได้ผูกกับบัญชี Krucheck', null],
            $result === 'account_not_active' => [
                match ($detail) {
                    'deleted' => 'บัญชีที่ผูกกับ Google นี้ถูกลบแล้ว',
                    'merged' => 'บัญชีที่ผูกกับ Google นี้ถูกรวมเข้าบัญชีอื่นแล้ว',
                    'pending' => 'บัญชีที่ผูกกับ Google นี้ยังรออนุมัติ',
                    default => 'บัญชีที่ผูกกับ Google นี้ถูกระงับ',
                },
                'กู้คืน เปิดใช้งาน หรืออนุมัติบัญชีในหน้าผู้ใช้ ถ้าบัญชีถูกรวม ให้ใช้บัญชีที่เก็บไว้',
            ],
            $event === 'signup' && $result === 'ok' => ['สมัครเป็นครูด้วย Google', null],
            $event === 'signup_student' && $result === 'ok' => ['สมัครเป็นนักเรียนด้วย Google และรหัสห้อง', null],
            $result === 'email_taken' => [
                'อีเมลนี้เป็นของบัญชีอื่นอยู่แล้ว (เช่น บัญชีนักเรียน) จึงสร้างบัญชีครูให้ไม่ได้',
                'เข้าสู่ระบบด้วยบัญชีเดิมแล้วเชื่อม Google หรือใช้บัญชี Google อื่น',
            ],
            $result === 'join_closed' => ['ห้องของรหัสห้องนี้ปิดรับสมาชิก', 'ให้ครูเปิดรับนักเรียนในห้อง หรือเพิ่มชื่อให้เอง'],
            $result === 'conflict' => ['มีคำขอสองครั้งพร้อมกัน บันทึกไม่สำเร็จ', 'ลองใหม่อีกครั้ง'],
            $event === 'link' && $result === 'ok' => ['เชื่อมบัญชี Google แล้ว ('.(self::VIA[$detail] ?? $detail).')', null],
            $event === 'unlink' && $result === 'ok' => ['ยกเลิกการเชื่อม Google', null],
            $result === 'google_already_linked' => [
                'บัญชี Google นี้ผูกกับผู้ใช้คนอื่นอยู่แล้ว',
                'ยกเลิกการเชื่อมที่ผู้ใช้คนนั้นก่อน หรือใช้บัญชี Google อื่น',
            ],
            $result === 'google_identity_exists' => [
                'บัญชีนี้ผูกกับบัญชี Google อื่นอยู่แล้ว',
                'ยกเลิกการเชื่อมเดิมก่อนแล้วจึงเชื่อมใหม่',
            ],
            $result === 'google_unlink_needs_password' => [
                'ยกเลิกการเชื่อมไม่ได้ เพราะบัญชียังไม่มีรหัสผ่าน',
                'ตั้งรหัสผ่านให้ในหน้าผู้ใช้ก่อน',
            ],
            $result === 'google_token_invalid' => [
                'Google ยืนยันตัวตนไม่ผ่าน'.($detail !== '' ? " ({$detail})" : ''),
                'มักเกิดจาก client ID ในแอปหรือ .env ไม่ตรงกับ project sign-in หรือเวลาในเครื่องคลาด ลองใหม่ ถ้ายังเป็น ตรวจ GOOGLE_SIGNIN_CLIENT_IDS',
            ],
            $result === 'google_email_unverified' => ['อีเมลของบัญชี Google นี้ยังไม่ได้รับการยืนยัน', 'ใช้บัญชี Google อื่น'],
            $result === 'cancelled' => ['ผู้ใช้กดยกเลิกที่หน้าของ Google', null],
            $result === 'google_error' => ['Google ตอบข้อผิดพลาด'.($detail !== '' ? " ({$detail})" : ''), null],
            $result === 'state_invalid' => [
                'ลิงก์เข้าสู่ระบบหมดอายุหรือถูกเปิดซ้ำ',
                'กดปุ่ม Google ใหม่แล้วเลือกบัญชีภายใน 10 นาที อย่าเปิดจากแท็บเก่า',
            ],
            $result === 'code_missing' => ['Google ไม่ได้ส่งรหัสยืนยันกลับมา', 'ลองใหม่อีกครั้ง'],
            $result === 'code_exchange_failed' => [
                'แลกรหัสกับ Google ไม่สำเร็จ',
                'ตรวจ GOOGLE_SIGNIN_CLIENT_SECRET และ redirect URI ของ Web client ใน project sign-in',
            ],
            $result === 'not_configured' => ['เซิร์ฟเวอร์ยังไม่ได้ตั้งค่าการเข้าสู่ระบบด้วย Google ทางเว็บ', 'ตั้งค่า GOOGLE_SIGNIN_* ใน .env'],
            $result === 'ticket_invalid' || $result === 'google_ticket_invalid' => ['ลิงก์ของ Google หมดอายุหรือถูกใช้ไปแล้ว', 'ลองใหม่อีกครั้ง'],
            $result === 'ticket_other_user' => ['ลิงก์เชื่อม Google ถูกเปิดจากบัญชีอื่น', 'เปิดลิงก์จากบัญชีที่กดเชื่อม'],
            default => [$result, null],
        };
    }

    /** @return array{0: string, 1: ?string} */
    private static function classroom(string $event, string $result, string $detail): array
    {
        return match (true) {
            $result === 'ok' => ['เชื่อม Google Classroom แล้ว', null],
            $result === 'needs_reconnect' && $detail === 'invalid_grant' => [
                'การเชื่อม Classroom หลุด (Google ไม่รับ token แล้ว)',
                'ให้ครูกดเชื่อม Google ใหม่ในหน้าตั้งค่า (project ที่ยังเป็นโหมด Testing หลุดทุก 7 วัน หรือผู้ใช้ถอนสิทธิ์เอง)',
            ],
            $result === 'needs_reconnect' => ['การเชื่อม Classroom ขาดสิทธิ์ที่ระบบต้องใช้', 'ให้ครูกดเชื่อมใหม่และติ๊กทุกช่อง'],
            $result === 'cancelled' => [
                'ครูกดยกเลิก หรือไม่อนุญาตที่หน้าของ Google',
                'ถ้าเห็นหน้า "Google hasn\'t verified this app" ให้กด Advanced แล้ว Go to … (unsafe)',
            ],
            $result === 'google_scope_missing' => [
                'ติ๊กสิทธิ์ไม่ครบ'.($detail !== '' ? ": {$detail}" : ''),
                'กดเชื่อมใหม่แล้วติ๊กทุกช่อง (หรือ "เลือกทั้งหมด")',
            ],
            $result === 'google_account_in_use' => [
                'บัญชี Google นี้เชื่อม Classroom กับครูคนอื่นอยู่แล้ว',
                'ใช้บัญชี Google อื่น หรือให้ครูคนนั้นยกเลิกการเชื่อมก่อน',
            ],
            $result === 'google_code_invalid' => ['รหัสจาก Google หมดอายุหรือใช้ไม่ได้', 'กดเชื่อมใหม่อีกครั้ง'],
            $result === 'state_invalid' => ['ลิงก์เชื่อม Classroom หมดอายุหรือถูกเปิดซ้ำ', 'กดเชื่อมใหม่จากแอป อย่าเปิดจากแท็บเก่า'],
            $result === 'forbidden' => ['บัญชีนี้ไม่มีสิทธิ์เชื่อม Google Classroom', 'เชื่อมได้เฉพาะบัญชีครูที่ใช้งานได้'],
            $result === 'code_missing' => ['Google ไม่ได้ส่งรหัสยืนยันกลับมา', 'ลองใหม่อีกครั้ง'],
            $result === 'google_not_configured' => ['เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Google Classroom', 'ตั้งค่า GOOGLE_OAUTH_* ใน .env'],
            $result === 'google_error' => ['Google ตอบข้อผิดพลาด'.($detail !== '' ? " ({$detail})" : ''), 'ลองใหม่อีกครั้งในอีกสักครู่'],
            default => [$result, null],
        };
    }
}
