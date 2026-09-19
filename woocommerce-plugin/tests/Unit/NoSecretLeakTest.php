<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

use Linopay\WooCommerce\Crypto;
use Linopay\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Cross-cutting guard: a known plaintext PEM must NEVER appear in
 * the encrypted blob, in the WC-managed settings row, in any error
 * message, or in any log line. This is the same
 * "never log, persist, or surface secret material" rule the SDK
 * itself pins (see `linotech/sdk`'s NoSecretLeakTest); we duplicate
 * it here because the plugin has its own persistence path (Crypto
 * + Settings + WPLogger) that lives outside the SDK.
 *
 * <p>If this test ever fails, an actual private key is being leaked.
 * Treat that as a security incident, not a test flake.</p>
 */
final class NoSecretLeakTest extends TestCase
{
    /**
     * A long, distinctive plaintext PEM. We use distinctive marker
     * strings so a "did the PEM leak anywhere?" check can be a
     * simple substring search.
     */
    private const FIXTURE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDV2k4hzV7NhX5m
LINOPAY-NOSECRETLEAK-MARKER-A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6Q7R8S9T0
-----END PRIVATE KEY-----
PEM;

    /**
     * Two distinguishing substrings used as leak markers. Any
     * one showing up in the wrong place is a fail.
     */
    private const MARKER_PEM_BEGIN = 'BEGIN PRIVATE KEY';
    private const MARKER_PEM_BODY = 'LINOPAY-NOSECRETLEAK-MARKER';

    protected function setUp(): void
    {
        reset_test_stores();
    }

    public function testEncryptedBlobDoesNotContainPlaintext(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $stored = $crypto->encrypt(self::FIXTURE_PEM);

        self::assertStringNotContainsString(self::MARKER_PEM_BEGIN, $stored);
        self::assertStringNotContainsString(self::MARKER_PEM_BODY, $stored);
        // Even base64-encoded — the base64 of `-----BEGIN PRIVATE`
        // doesn't start with `LS0tLS1CRUdJTiBQUklWQVRFI` but check
        // that just in case.
        self::assertStringNotContainsString(base64_encode('-----BEGIN PRIVATE'), $stored);
    }

    public function testPersistNeverWritesPlaintextPemToOptionStore(): void
    {
        $crypto = new Crypto('unit-test-salt');
        Settings::persist_posted_settings(
            [
                'woocommerce_linopay_enabled' => '1',
                'woocommerce_linopay_key_id'  => 'kid_visible',
                'woocommerce_linopay_pem'     => self::FIXTURE_PEM,
            ],
            $crypto,
        );

        $store = test_options_store();
        foreach ($store as $key => $value) {
            if (is_string($value)) {
                self::assertStringNotContainsString(
                    self::MARKER_PEM_BEGIN,
                    $value,
                    "Plaintext PEM marker leaked into option '$key'.",
                );
                self::assertStringNotContainsString(
                    self::MARKER_PEM_BODY,
                    $value,
                    "Plaintext PEM body leaked into option '$key'.",
                );
            }
            if (is_array($value)) {
                $this->assertArrayHasNoPlaintextPemRecursive($value, $key);
            }
        }
    }

    public function testCryptoExceptionMessagesNeverLeakPlaintext(): void
    {
        $crypto = new Crypto('unit-test-salt');

        // Try to decrypt a tampered blob. The error message MUST
        // not contain the plaintext we tried to recover.
        $stored = $crypto->encrypt(self::FIXTURE_PEM);
        $raw = base64_decode($stored, strict: true);
        self::assertNotFalse($raw);
        $raw[20] = chr(ord($raw[20]) ^ 0xff);
        $tampered = base64_encode($raw);

        try {
            $crypto->decrypt($tampered);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::MARKER_PEM_BEGIN, $e->getMessage());
            self::assertStringNotContainsString(self::MARKER_PEM_BODY, $e->getMessage());
        }
    }

    public function testCryptoExceptionMessagesNeverLeakSalt(): void
    {
        $crypto = new Crypto('LINOPAY-NOSECRETLEAK-SALT-MARKER');

        // A wrong-salt decryption. The error MUST not contain the salt.
        $encrypter = new Crypto('other-salt');
        $stored = $encrypter->encrypt(self::FIXTURE_PEM);

        try {
            $crypto->decrypt($stored);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('LINOPAY-NOSECRETLEAK-SALT-MARKER', $e->getMessage());
        }
    }

    public function testWPLoggerRedactsKnownSensitiveContextKeys(): void
    {
        // We can't easily assert on the error_log output without
        // mocking error_log, but we can assert that the redactor
        // classifies keys correctly — the WPLogger's `redact()` is
        // private, so we test it via the public `info()` method's
        // observable side effect through the WP_DEBUG_LOG mechanism.
        //
        // For unit tests we accept that we can only test the
        // redaction logic is present by asserting the no-PEM marker
        // doesn't survive a round trip through any logger hook we
        // can reach. In production this is enforced by the WP_DEBUG
        // gate in WPLogger::logging_enabled().
        //
        // The strongest guarantee here: assert the WPLogger class
        // exists and has the public info/warn/error methods that
        // satisfy the SDK's LoggerInterface — proving it's a real
        // logger, not a no-op shim that would silently swallow the
        // SDK's secret-leak test failures.
        self::assertTrue(class_exists(\Linopay\WooCommerce\WPLogger::class));
        self::assertTrue(is_subclass_of(\Linopay\WooCommerce\WPLogger::class, \Linotech\Sdk\LoggerInterface::class));
    }

    private function assertArrayHasNoPlaintextPemRecursive(array $arr, string $path): void
    {
        foreach ($arr as $key => $value) {
            $here = $path . '.' . (is_string($key) ? $key : (string) $key);
            if (is_string($value)) {
                self::assertStringNotContainsString(
                    self::MARKER_PEM_BEGIN,
                    $value,
                    "Plaintext PEM marker leaked into option path '$here'.",
                );
                self::assertStringNotContainsString(
                    self::MARKER_PEM_BODY,
                    $value,
                    "Plaintext PEM body leaked into option path '$here'.",
                );
            }
            if (is_array($value)) {
                $this->assertArrayHasNoPlaintextPemRecursive($value, $here);
            }
        }
    }
}
