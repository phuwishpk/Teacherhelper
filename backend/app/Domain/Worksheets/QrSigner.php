<?php

namespace App\Domain\Worksheets;

use RuntimeException;

/**
 * Worksheet QR payloads (DESIGN §5.4):
 *
 *   EV1.{assignment_id}.{student_id}.{page}.{layout_version}.{sig}
 *
 * sig = the first 5 bytes of HMAC-SHA256(prefix, QR_SIGNING_KEY) in RFC 4648
 * base32 (8 characters, no padding), where prefix is everything before the
 * last dot. The phone cannot check the signature (it has no key); the server
 * verifies it on upload, so a forged QR cannot file a page under another
 * student.
 */
class QrSigner
{
    public const PREFIX = 'EV1';

    private const SIG_BYTES = 5;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PATTERN = '/\AEV1\.(0|[1-9]\d{0,18})\.(0|[1-9]\d{0,18})\.([1-9]\d{0,2})\.([1-9]\d{0,4})\.([A-Z2-7]{8})\z/';

    private readonly string $key;

    public function __construct(?string $key)
    {
        if ($key === null || trim($key) === '') {
            throw new RuntimeException('QR_SIGNING_KEY is not set; worksheets cannot be printed or verified.');
        }
        $this->key = $key;
    }

    public static function fromConfig(): self
    {
        return new self(config('eduvision.qr_signing_key'));
    }

    public function sign(int $assignmentId, int $studentId, int $page, int $layoutVersion): string
    {
        if ($assignmentId < 1 || $studentId < 0 || $page < 1 || $layoutVersion < 1) {
            throw new \InvalidArgumentException('Worksheet QR identifiers are out of range.');
        }

        $prefix = implode('.', [self::PREFIX, $assignmentId, $studentId, $page, $layoutVersion]);

        return $prefix.'.'.$this->signature($prefix);
    }

    /**
     * Parses and verifies a scanned payload. Returns null for anything that is
     * not a well-formed, correctly signed worksheet QR.
     */
    public function verify(string $payload): ?WorksheetQr
    {
        if (preg_match(self::PATTERN, $payload, $m) !== 1) {
            return null;
        }

        $prefix = substr($payload, 0, strrpos($payload, '.'));
        if (! hash_equals($this->signature($prefix), $m[5])) {
            return null;
        }

        return new WorksheetQr((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]);
    }

    private function signature(string $prefix): string
    {
        $mac = hash_hmac('sha256', $prefix, $this->key, true);

        return self::base32(substr($mac, 0, self::SIG_BYTES));
    }

    /** RFC 4648 base32 without padding; 5 bytes -> exactly 8 characters. */
    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0');

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $out;
    }
}
