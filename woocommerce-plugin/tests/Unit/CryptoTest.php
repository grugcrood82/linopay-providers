<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

use Linopay\WooCommerce\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of {@see Crypto}:
 *
 *  - encrypt → decrypt round-trips for arbitrary non-empty plaintexts.
 *  - two encrypts of the same plaintext produce different ciphertexts
 *    (random IV).
 *  - decryption with a different salt fails (GCM tag check).
 *  - malformed stored values fail with clear error messages; they do
 *    NOT leak the salt or any internal key material in the message.
 *  - encrypt() refuses empty plaintexts (defence against a bug that
 *    would otherwise delete a stored PEM silently).
 *  - the constructor refuses empty salts.
 */
final class CryptoTest extends TestCase
{
    private const FIXTURE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDV2k4hzV7NhX5m
fake-rsa-2048-private-key-for-unit-tests-only-not-real-secret
-----END PRIVATE KEY-----
PEM;

    public function testConstructorRejectsEmptySalt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto('');
    }

    public function testEncryptRefusesEmptyPlaintext(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $this->expectException(\InvalidArgumentException::class);
        $crypto->encrypt('');
    }

    public function testRoundTrip(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $stored = $crypto->encrypt(self::FIXTURE_PEM);
        self::assertSame(self::FIXTURE_PEM, $crypto->decrypt($stored));
    }

    public function testEncryptProducesDifferentCiphertextsEachTime(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $a = $crypto->encrypt(self::FIXTURE_PEM);
        $b = $crypto->encrypt(self::FIXTURE_PEM);
        // Same plaintext + same salt should still produce different
        // ciphertexts — the IV is random per call. This is what makes
        // the storage format safe to keep in version control (the
        // stored value gives away no information about the plaintext).
        self::assertNotSame($a, $b);
        // …but both still decrypt to the same plaintext.
        self::assertSame(self::FIXTURE_PEM, $crypto->decrypt($a));
        self::assertSame(self::FIXTURE_PEM, $crypto->decrypt($b));
    }

    public function testDecryptionFailsWithWrongSalt(): void
    {
        $encrypter = new Crypto('salt-A');
        $decrypter = new Crypto('salt-B');

        $stored = $encrypter->encrypt(self::FIXTURE_PEM);

        $this->expectException(\RuntimeException::class);
        $decrypter->decrypt($stored);
    }

    public function testDecryptionFailsOnTamperedCiphertext(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $stored = $crypto->encrypt(self::FIXTURE_PEM);

        // Flip a byte in the middle of the stored value. The GCM tag
        // is at the END of the blob (see Crypto::VERSION layout), so
        // flipping a byte in the middle hits the ciphertext itself.
        $raw = base64_decode($stored, strict: true);
        self::assertNotFalse($raw);
        $raw[20] = chr(ord($raw[20]) ^ 0xff);
        $tampered = base64_encode($raw);

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($tampered);
    }

    public function testDecryptionRejectsEmptyString(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $this->expectException(\InvalidArgumentException::class);
        $crypto->decrypt('');
    }

    public function testDecryptionRejectsInvalidBase64(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt('!!! not base64 !!!');
    }

    public function testDecryptionRejectsTooShortBlob(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $this->expectException(\RuntimeException::class);
        // 5 bytes — shorter than the minimum (version + 12-byte IV + 16-byte tag = 29).
        $crypto->decrypt(base64_encode('12345'));
    }

    public function testDecryptionRejectsUnknownVersionByte(): void
    {
        $crypto = new Crypto('unit-test-salt');
        // 30 bytes with a non-zero-non-`\x01` version prefix.
        $raw = "\x99" . str_repeat('A', 29);
        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(base64_encode($raw));
    }

    public function testDecryptionErrorMessagesNeverLeakSaltOrKeyMaterial(): void
    {
        $crypto = new Crypto('super-secret-salt-12345');
        // Try a deliberately-wrong payload and capture the exception
        // message. The salt must NOT appear anywhere in the message.
        try {
            $crypto->decrypt(base64_encode("\x01" . str_repeat('B', 29)));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('super-secret-salt-12345', $msg);
            self::assertStringNotContainsString('super-secret', $msg);
            self::assertStringNotContainsString('salt-12345', $msg);
        }
    }
}
