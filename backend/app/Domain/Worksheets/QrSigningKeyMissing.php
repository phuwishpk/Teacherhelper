<?php

namespace App\Domain\Worksheets;

use RuntimeException;

/**
 * QR_SIGNING_KEY is empty in the server .env: no worksheet can be printed and
 * no scanned QR can be verified (DESIGN §5.4). A configuration error for the
 * operator, not something the teacher can fix, so callers turn it into a
 * message that says so instead of a generic "try again".
 */
class QrSigningKeyMissing extends RuntimeException
{
    /** Thai message shown to the teacher (API 503 and failed print jobs). */
    public const USER_MESSAGE = 'ยังพิมพ์ใบงานไม่ได้ เพราะเซิร์ฟเวอร์ยังไม่ได้ตั้งค่า QR_SIGNING_KEY กรุณาแจ้งผู้ดูแลระบบ';

    public function __construct()
    {
        parent::__construct('QR_SIGNING_KEY is not set; worksheets cannot be printed or verified.');
    }
}
