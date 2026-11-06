<?php

namespace App\Domain\Worksheets;

/**
 * Worksheet QR payloads (DESIGN §5.4) and exam answer-sheet QR payloads
 * (DESIGN §22.8):
 *
 *   EV1.{assignment_id}.{student_id}.{page}.{layout_version}.{sig}
 *   EVX1.{assignment_id}.{student_id}.{page}.{layout_version}.{sig}
 *   EVC1.{assignment_id}.0.{page}.{layout_version}.{sig}   (shared sheet, DESIGN §22.19)
 *
 * sig = the first 5 bytes of HMAC-SHA256(prefix, QR_SIGNING_KEY) in RFC 4648
 * base32 (8 characters, no padding), where prefix is everything before the
 * last dot. The phone cannot check the signature (it has no key); the server
 * verifies it on upload, so a forged QR cannot file a page under another
 * student. The prefix is part of the signed text, so a worksheet signature
 * never verifies as an answer sheet and the other way round. Student 0 of an
 * answer sheet is the teacher's key sheet (DESIGN §22.6).
 */
class QrSigner
{
    public const PREFIX = 'EV1';

    public const EXAM_PREFIX = 'EVX1';

    /** The shared answer sheet with the student-ID grid: the student field is always 0 (DESIGN §22.19). */
    public const CODE_SHEET_PREFIX = 'EVC1';

    private const SIG_BYTES = 5;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PATTERN = '/\A(EV1|EVX1|EVC1)\.(0|[1-9]\d{0,18})\.(0|[1-9]\d{0,18})\.([1-9]\d{0,2})\.([1-9]\d{0,4})\.([A-Z2-7]{8})\z/';

    private readonly string $key;

    public function __construct(?string $key)
    {
        if ($key === null || trim($key) === '') {
            throw new QrSigningKeyMissing;
        }
        $this->key = $key;
    }

    public static function fromConfig(): self
    {
        return new self(config('eduvision.qr_signing_key'));
    }

    /** Whether QR_SIGNING_KEY is set, checked before a print is queued. */
    public static function isConfigured(): bool
    {
        return trim((string) config('eduvision.qr_signing_key')) !== '';
    }

    public function sign(int $assignmentId, int $studentId, int $page, int $layoutVersion): string
    {
        return $this->signWith(self::PREFIX, $assignmentId, $studentId, $page, $layoutVersion);
    }

    /** The QR of an exam answer sheet (student 0 = the teacher's key sheet), DESIGN §22.8. */
    public function signExamSheet(int $assignmentId, int $studentId, int $page, int $layoutVersion): string
    {
        return $this->signWith(self::EXAM_PREFIX, $assignmentId, $studentId, $page, $layoutVersion);
    }

    /** The QR of the shared answer sheet of an exam with sheet_identity = code (DESIGN §22.19). */
    public function signCodeSheet(int $assignmentId, int $page, int $layoutVersion): string
    {
        return $this->signWith(self::CODE_SHEET_PREFIX, $assignmentId, 0, $page, $layoutVersion);
    }

    /**
     * Parses and verifies a scanned payload. Returns null for anything that is
     * not a well-formed, correctly signed worksheet QR.
     */
    public function verify(string $payload): ?WorksheetQr
    {
        return $this->verifyWith(self::PREFIX, $payload);
    }

    /** Like verify() for an exam answer sheet (`EVX1`); a worksheet QR is null here. */
    public function verifyExamSheet(string $payload): ?WorksheetQr
    {
        return $this->verifyWith(self::EXAM_PREFIX, $payload);
    }

    /** Like verify() for a shared answer sheet (`EVC1`); its studentId is 0. */
    public function verifyCodeSheet(string $payload): ?WorksheetQr
    {
        $qr = $this->verifyWith(self::CODE_SHEET_PREFIX, $payload);

        return $qr !== null && $qr->studentId === 0 ? $qr : null;
    }

    private function signWith(string $type, int $assignmentId, int $studentId, int $page, int $layoutVersion): string
    {
        if ($assignmentId < 1 || $studentId < 0 || $page < 1 || $layoutVersion < 1) {
            throw new \InvalidArgumentException('Worksheet QR identifiers are out of range.');
        }

        $prefix = implode('.', [$type, $assignmentId, $studentId, $page, $layoutVersion]);

        return $prefix.'.'.$this->signature($prefix);
    }

    private function verifyWith(string $type, string $payload): ?WorksheetQr
    {
        if (preg_match(self::PATTERN, $payload, $m) !== 1 || $m[1] !== $type) {
            return null;
        }

        $prefix = substr($payload, 0, strrpos($payload, '.'));
        if (! hash_equals($this->signature($prefix), $m[6])) {
            return null;
        }

        return new WorksheetQr((int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
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
