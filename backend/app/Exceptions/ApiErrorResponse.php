<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Builds the uniform API error body {message, errors, code} (DESIGN §9).
 *
 * Framework-raised HTTP errors (404 unknown route, 405, 429 throttle, abort())
 * are mapped to a stable snake_case code and a Thai message here; errors the
 * app must handle by code should be thrown as ApiException instead.
 */
final class ApiErrorResponse
{
    private const CODES = [
        400 => 'bad_request',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    private const MESSAGES = [
        403 => 'คุณไม่มีสิทธิ์ทำรายการนี้',
        404 => 'ไม่พบข้อมูลที่ร้องขอ',
        405 => 'ไม่รองรับวิธีเรียกนี้',
        413 => 'ไฟล์หรือข้อมูลใหญ่เกินกำหนด',
        429 => 'ส่งคำขอบ่อยเกินไป กรุณาลองใหม่ในอีกสักครู่',
        503 => 'ระบบปิดปรับปรุงชั่วคราว กรุณาลองใหม่ภายหลัง',
    ];

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, string>  $headers
     */
    public static function make(string $message, string $code, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ], $status, $headers);
    }

    public static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        return self::make(
            self::MESSAGES[$status] ?? ($e->getMessage() !== '' ? $e->getMessage() : 'เกิดข้อผิดพลาด'),
            self::codeFor($status),
            $status,
            [],
            $e->getHeaders(), // keeps Retry-After / X-RateLimit-* from the throttle middleware
        );
    }

    public static function codeFor(int $status): string
    {
        return self::CODES[$status] ?? 'http_'.$status;
    }

    public static function messageFor(int $status): ?string
    {
        return self::MESSAGES[$status] ?? null;
    }
}
