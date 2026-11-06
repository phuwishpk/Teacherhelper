<?php

namespace App\Domain\Gemini;

/**
 * The error code of a failed AI call, one set for every AI feature (DESIGN
 * §36). The teacher sees it with the ai_calls id ("AI-QUOTA · #48213"), and
 * the admin finds the call by that id in Filament.
 */
final class AiErrorCode
{
    /** Neither the teacher nor (where allowed) the server has a key. */
    public const KEY_MISSING = 'AI-KEY-MISSING';

    /** Google refused the key (400 API_KEY_INVALID, 401, 403). */
    public const KEY_INVALID = 'AI-KEY-INVALID';

    /** 429: the key's quota or credit is used up, or too many calls at once. */
    public const QUOTA = 'AI-QUOTA';

    /** 404: the configured model does not exist for this key. */
    public const MODEL = 'AI-MODEL';

    /** No answer in time, or Google could not be reached. */
    public const TIMEOUT = 'AI-TIMEOUT';

    /** 5xx: Google's side failed. */
    public const SERVER = 'AI-SERVER';

    /** The prompt or the answer was blocked by Google's safety filters. */
    public const BLOCKED = 'AI-BLOCKED';

    /** The answer was not usable (not JSON, wrong shape, cut off). */
    public const OUTPUT = 'AI-OUTPUT';

    /** Anything else. */
    public const ERROR = 'AI-ERROR';

    public const ALL = [
        self::KEY_MISSING, self::KEY_INVALID, self::QUOTA, self::MODEL, self::TIMEOUT,
        self::SERVER, self::BLOCKED, self::OUTPUT, self::ERROR,
    ];

    /** finishReason values of an answer that Google stopped for its content. */
    private const BLOCKING_FINISH = ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_SAFETY'];

    /** The code of a judged call; null when it went well. */
    public static function forOutcome(CallOutcome $outcome, GeminiReply $reply): ?string
    {
        return match ($outcome->status) {
            CallOutcome::OK => null,
            CallOutcome::KEY_INVALID => self::KEY_INVALID,
            CallOutcome::INVALID_OUTPUT => in_array($reply->finishReason, self::BLOCKING_FINISH, true) ? self::BLOCKED : self::OUTPUT,
            default => self::forTransport($reply->httpStatus, (string) $reply->error),
        };
    }

    /**
     * A transport or HTTP failure, from the status (when there was a
     * response) and the error text of GeminiReply / HttpGeminiClient.
     */
    public static function forTransport(?int $httpStatus, string $error): string
    {
        if ($httpStatus === null && preg_match('/^HTTP (\d{3})\b/', $error, $m) === 1) {
            $httpStatus = (int) $m[1];
        }

        return match (true) {
            $httpStatus === 401, $httpStatus === 403 => self::KEY_INVALID,
            $httpStatus === 429 => self::QUOTA,
            $httpStatus === 404 => self::MODEL,
            $httpStatus === 408, $httpStatus === 504 => self::TIMEOUT,
            $httpStatus !== null && $httpStatus >= 500 => self::SERVER,
            str_starts_with($error, 'prompt blocked') => self::BLOCKED,
            str_starts_with($error, 'ConnectionException'), str_contains(strtolower($error), 'timed out') => self::TIMEOUT,
            default => self::ERROR,
        };
    }

    /** What happened and what to do, in Thai, for the teacher. */
    public static function message(string $code): string
    {
        return match ($code) {
            self::KEY_MISSING => 'ยังไม่มี Gemini API key ใส่ key ได้ที่หน้าตั้งค่า',
            self::KEY_INVALID => 'Gemini ไม่ยอมรับ key นี้แล้ว (key ผิด ถูกลบ หรือหมดอายุ) ใส่ key ใหม่ที่หน้าตั้งค่า',
            self::QUOTA => 'โควตาหรือเครดิตของ Gemini key หมด หรือเรียกถี่เกินไป รอสักครู่แล้วลองใหม่ หรือตรวจ billing ใน Google AI Studio',
            self::MODEL => 'ไม่พบโมเดล AI ที่ระบบตั้งไว้สำหรับ key นี้ แจ้งผู้ดูแลระบบพร้อมรหัสนี้',
            self::TIMEOUT => 'AI ตอบไม่ทันเวลาหรือติดต่อ Google ไม่ได้ ลองใหม่อีกครั้ง',
            self::SERVER => 'ระบบของ Google ขัดข้องชั่วคราว ลองใหม่ในอีกสักครู่',
            self::BLOCKED => 'Google ไม่ตอบเพราะตัวกรองความปลอดภัยของเนื้อหา ลองปรับข้อความหรือไฟล์',
            self::OUTPUT => 'AI ตอบกลับมาในรูปแบบที่ระบบอ่านไม่ได้ ลองใหม่อีกครั้ง',
            default => 'เรียก AI ไม่สำเร็จ ลองใหม่อีกครั้ง ถ้ายังเป็นอยู่ แจ้งผู้ดูแลระบบพร้อมรหัสนี้',
        };
    }
}
