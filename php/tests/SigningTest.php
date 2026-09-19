<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use Linotech\Sdk\ChannelJwtSigner;
use Linotech\Sdk\LinopayConfigException;
use PHPUnit\Framework\TestCase;
use Linotech\Sdk\Tests\Helpers\TestPem;

/**
 * Locks in the channel JWT shape: RS256, kid, iss, aud,
 * channelKeyId-pinning, ~5-minute lifetime.
 *
 * Decodes the JWT manually so the test is independent of
 * firebase/php-jwt's internal representation and matches what an
 * upstream verifier on the LinoPay side actually sees on the wire.
 */
final class SigningTest extends TestCase
{
    public function testProducesRs256JwtWithKidIssuerAudienceAndChannelKeyId(): void
    {
        $pem = TestPem::generate();
        $token = ChannelJwtSigner::sign($pem, 'kid_test_abc', [
            'channelKeyId' => 'kid_test_abc',
            'txnRef' => 'tx_001',
            'amount' => '1.99',
            'currency' => 'NZD',
        ]);

        [$headerB64, $payloadB64, $signatureB64] = explode('.', $token);
        self::assertNotEmpty($signatureB64);

        $header = json_decode(self::b64UrlDecode($headerB64), true);
        $payload = json_decode(self::b64UrlDecode($payloadB64), true);

        self::assertSame('RS256', $header['alg'] ?? null);
        self::assertSame('kid_test_abc', $header['kid'] ?? null);
        self::assertSame('JWT', $header['typ'] ?? null);

        self::assertSame('linotech-pay', $payload['iss'] ?? null);
        // `aud` is set as a string per the JWT spec; PHPUnit 10's
        // assertContains only accepts arrays / Traversables — use the
        // string-aware assertStringContainsString for it.
        self::assertStringContainsString('linotech-pay-api', $payload['aud'] ?? '');
        self::assertSame('kid_test_abc', $payload['channelKeyId'] ?? null);
        self::assertSame('tx_001', $payload['txnRef'] ?? null);
        self::assertSame('1.99', $payload['amount'] ?? null);
        self::assertSame('NZD', $payload['currency'] ?? null);

        // 5-minute lifetime with 5s clock-skew tolerance
        $lifetime = ($payload['exp'] ?? 0) - ($payload['iat'] ?? 0);
        self::assertGreaterThanOrEqual(290, $lifetime);
        self::assertLessThanOrEqual(305, $lifetime);
    }

    public function testForcesChannelKeyIdToConfigured(): void
    {
        $pem = TestPem::generate();
        $token = ChannelJwtSigner::sign($pem, 'real_key_id', [
            'channelKeyId' => 'forged_key_id',
        ]);
        $payload = json_decode(self::b64UrlDecode(explode('.', $token)[1]), true);
        self::assertSame('real_key_id', $payload['channelKeyId']);
    }

    public function testRefusesToSignWithoutKeyId(): void
    {
        $pem = TestPem::generate();
        $this->expectException(LinopayConfigException::class);
        ChannelJwtSigner::sign($pem, '', []);
    }

    public function testRefusesToSignWithoutPrivateKey(): void
    {
        $this->expectException(LinopayConfigException::class);
        ChannelJwtSigner::sign('', 'kid', []);
    }

    private static function b64UrlDecode(string $s): string
    {
        $padded = strtr($s, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode($padded);
    }
}
