<?php

namespace Tests\Unit;

use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\QrSigningKeyMissing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN §5.4: EV1.{assignment}.{student}.{page}.{layout_version}.{sig},
 * sig = base32(first 5 bytes of HMAC-SHA256(prefix, QR_SIGNING_KEY)).
 */
class QrSignerTest extends TestCase
{
    private const KEY = 'testing-qr-signing-key-not-a-secret';

    public function test_signature_matches_an_independent_hmac_base32_reference(): void
    {
        // Expected values from Python: base64.b32encode(hmac.new(key, prefix, sha256).digest()[:5]).
        $signer = new QrSigner(self::KEY);

        $this->assertSame('EV1.123.4567.1.2.PUT4XFLB', $signer->sign(123, 4567, 1, 2));
        $this->assertSame('EV1.9.0.3.1.JUPVFRN4', $signer->sign(9, 0, 3, 1));
    }

    public function test_verify_returns_the_identifiers_of_a_signed_payload(): void
    {
        $signer = new QrSigner(self::KEY);
        $qr = $signer->verify($signer->sign(123, 4567, 2, 7));

        $this->assertNotNull($qr);
        $this->assertSame([123, 4567, 2, 7], [$qr->assignmentId, $qr->studentId, $qr->page, $qr->layoutVersion]);
        $this->assertFalse($qr->isAnonymous());
    }

    public function test_the_anonymous_spare_sheet_uses_student_zero(): void
    {
        $signer = new QrSigner(self::KEY);
        $qr = $signer->verify($signer->sign(55, 0, 1, 1));

        $this->assertNotNull($qr);
        $this->assertTrue($qr->isAnonymous());
    }

    /** @return array<string, array{string}> */
    public static function tamperedPayloads(): array
    {
        return [
            'other student' => ['EV1.123.4568.1.2.PUT4XFLB'],
            'other page' => ['EV1.123.4567.2.2.PUT4XFLB'],
            'other version' => ['EV1.123.4567.1.3.PUT4XFLB'],
            'other assignment' => ['EV1.124.4567.1.2.PUT4XFLB'],
            'flipped signature char' => ['EV1.123.4567.1.2.PUT4XFLC'],
            'lower-case signature' => ['EV1.123.4567.1.2.put4xflb'],
            'leading zero id' => ['EV1.0123.4567.1.2.PUT4XFLB'],
            'page zero' => ['EV1.123.4567.0.2.PUT4XFLB'],
            'extra segment' => ['EV1.123.4567.1.2.3.PUT4XFLB'],
            'short signature' => ['EV1.123.4567.1.2.PUT4XFL'],
            'login card qr' => ['EVL1.abcdefghijklmnopqrstuvwxyz012345678901234'],
            'trailing newline' => ["EV1.123.4567.1.2.PUT4XFLB\n"],
            'empty' => [''],
        ];
    }

    #[DataProvider('tamperedPayloads')]
    public function test_tampered_or_malformed_payloads_are_rejected(string $payload): void
    {
        $this->assertNull((new QrSigner(self::KEY))->verify($payload));
    }

    public function test_a_payload_signed_with_another_key_is_rejected(): void
    {
        $forged = (new QrSigner('attacker-key'))->sign(123, 4567, 1, 2);

        $this->assertNull((new QrSigner(self::KEY))->verify($forged));
    }

    public function test_a_missing_key_fails_loudly(): void
    {
        $this->expectException(QrSigningKeyMissing::class);
        $this->expectExceptionMessage('QR_SIGNING_KEY');

        new QrSigner('   ');
    }

    public function test_base32_follows_rfc_4648_without_padding(): void
    {
        $this->assertSame('MY', QrSigner::base32('f'));
        $this->assertSame('MZXQ', QrSigner::base32('fo'));
        $this->assertSame('MZXW6', QrSigner::base32('foo'));
        $this->assertSame('MZXW6YQ', QrSigner::base32('foob'));
        $this->assertSame('MZXW6YTB', QrSigner::base32('fooba'));
    }
}
