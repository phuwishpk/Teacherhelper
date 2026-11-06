<?php

namespace App\Domain\Firestore;

use App\Exceptions\ApiException;

final class FirestoreErrors
{
    /** 503 firestore_not_configured: the app keeps its pull-to-refresh screens (DESIGN §38.1). */
    public static function notConfigured(): ApiException
    {
        return new ApiException('เซิร์ฟเวอร์นี้ไม่ได้เปิดใช้ข้อมูลสด (Firestore)', 'firestore_not_configured', 503);
    }

    public static function unavailable(string $detail): ApiException
    {
        return new ApiException('ออก token ของ Firebase ไม่ได้ในขณะนี้: '.$detail, 'firestore_unavailable', 503);
    }
}
