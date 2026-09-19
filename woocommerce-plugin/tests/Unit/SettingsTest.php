<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

use Linopay\WooCommerce\Crypto;
use Linopay\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of {@see Settings::persist_posted_settings()}.
 *
 *  - The plaintext PEM never appears in the returned $cleaned array.
 *  - Sanitisation per-field: enabled, environment, bank_code, key_id,
 *    title, description.
 *  - Unknown fields are kept (passed through sanitize_text_field) so
 *    we don't drop form-field changes silently.
 *  - The encrypted PEM goes to a separate option row with
 *    autoload='no' (verified via the autoload hint recorded by the
 *    test stub).
 *
 * WordPress functions like `update_option` / `delete_option` are
 * shadowed in {@see WpStubs} (loaded by the test bootstrap before
 * composer's autoloader runs). This way the unqualified calls
 * inside `Settings.php` resolve to our stubs without needing
 * dependency injection into the Settings class itself.
 */
final class SettingsTest extends TestCase
{
    private const FIXTURE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDV2k4hzV7NhX5m
fake-rsa-2048-private-key-for-unit-tests-only-not-real-secret
-----END PRIVATE KEY-----
PEM;

    protected function setUp(): void
    {
        reset_test_stores();
    }

    public function testPersistStripsPlaintextPemFromCleanedArray(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $posted = [
            'woocommerce_linopay_enabled'     => '1',
            'woocommerce_linopay_title'       => 'Bank-to-bank',
            'woocommerce_linopay_pem'         => self::FIXTURE_PEM,
            '_wp_http_referer'                => '/wp-admin/admin.php?page=wc-settings',
            'woocommerce_linopay_nonce_field' => 'should-be-ignored',
        ];

        $cleaned = Settings::persist_posted_settings($posted, $crypto);

        self::assertArrayNotHasKey('pem', $cleaned, 'Plaintext PEM must not appear in the WC-managed settings row.');
        self::assertSame('yes', $cleaned['enabled']);
        self::assertSame('Bank-to-bank', $cleaned['title']);
    }

    public function testPersistEncryptsPemToSeparateOptionRow(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $posted = [
            'woocommerce_linopay_pem' => self::FIXTURE_PEM,
        ];

        Settings::persist_posted_settings($posted, $crypto);

        $store = test_options_store();
        self::assertArrayHasKey(Settings::OPTION_PEM_ENCRYPTED, $store);
        $stored = $store[Settings::OPTION_PEM_ENCRYPTED];
        self::assertIsString($stored);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $stored);
        self::assertSame(self::FIXTURE_PEM, $crypto->decrypt($stored));
    }

    public function testPersistStoresPemWithAutoloadNo(): void
    {
        $crypto = new Crypto('unit-test-salt');
        Settings::persist_posted_settings(['woocommerce_linopay_pem' => self::FIXTURE_PEM], $crypto);

        $hints = array_filter(
            test_autoload_hints(),
            fn ($h) => $h['key'] === Settings::OPTION_PEM_ENCRYPTED,
        );
        self::assertNotEmpty($hints, 'Expected the encrypted PEM option to be persisted with an autoload hint.');
        self::assertFalse(reset($hints)['autoload'], 'Encrypted PEM option must autoload=no.');
    }

    public function testPersistWithEmptyPemDeletesExistingOption(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $store = & test_options_store();
        $store[Settings::OPTION_PEM_ENCRYPTED] = 'preexisting';

        Settings::persist_posted_settings(['woocommerce_linopay_pem' => ''], $crypto);

        self::assertContains(Settings::OPTION_PEM_ENCRYPTED, test_deleted_options());
        self::assertArrayNotHasKey(Settings::OPTION_PEM_ENCRYPTED, $store);
    }

    public function testPersistWithoutPemDoesNotTouchOption(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $posted = [
            'woocommerce_linopay_enabled' => '1',
            'woocommerce_linopay_key_id'  => 'kid_test_abc',
        ];

        $cleaned = Settings::persist_posted_settings($posted, $crypto);

        $store = test_options_store();
        self::assertArrayNotHasKey(Settings::OPTION_PEM_ENCRYPTED, $store);
        self::assertSame([], test_deleted_options());
        // The cleaned settings array is what the caller passes to
        // update_option(OPTION_KEY, ...); it's the WC-managed row,
        // not a separate side effect of persist_posted_settings itself.
        self::assertSame('kid_test_abc', $cleaned['key_id']);
        self::assertSame('yes', $cleaned['enabled']);
    }

    public function testSanitisationForEnvironment(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $cleaned = Settings::persist_posted_settings(['woocommerce_linopay_environment' => 'live'], $crypto);
        self::assertSame('live', $cleaned['environment']);

        $cleaned = Settings::persist_posted_settings(['woocommerce_linopay_environment' => 'staging'], $crypto);
        self::assertSame('sandbox', $cleaned['environment'], 'Unknown environments fall back to sandbox.');
    }

    public function testSanitisationForBankCode(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $cleaned = Settings::persist_posted_settings(
            ['woocommerce_linopay_bank_code' => 'ANZ<script>alert(1)</script>'],
            $crypto,
        );
        self::assertSame('ANZscriptalert1script', $cleaned['bank_code']);
    }

    public function testSanitisationForKeyId(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $cleaned = Settings::persist_posted_settings(
            ['woocommerce_linopay_key_id' => 'kid_abc-123!@#'],
            $crypto,
        );
        self::assertSame('kid_abc-123', $cleaned['key_id']);
    }

    public function testPlaintextPemNeverInCleanedArrayEvenOnOversizedInput(): void
    {
        $crypto = new Crypto('unit-test-salt');
        $longPem = str_repeat('A', 5000);

        $cleaned = Settings::persist_posted_settings(
            ['woocommerce_linopay_pem' => $longPem],
            $crypto,
        );

        self::assertArrayNotHasKey('pem', $cleaned);
        $store = test_options_store();
        $stored = $store[Settings::OPTION_PEM_ENCRYPTED];
        self::assertSame($longPem, $crypto->decrypt($stored));
    }

    public function testPlaintextPemNeverInReturnedCleanedArray(): void
    {
        // The cleaned array is what the caller passes to
        // update_option(OPTION_KEY, ...) — that's the WC-managed
        // settings row. The PEM must NEVER appear there in any form.
        $crypto = new Crypto('unit-test-salt');
        $cleaned = Settings::persist_posted_settings(
            [
                'woocommerce_linopay_enabled' => '1',
                'woocommerce_linopay_pem'     => self::FIXTURE_PEM,
            ],
            $crypto,
        );

        // Walk the cleaned array recursively.
        $this->assertArrayHasNoPlaintextPemRecursive($cleaned, 'cleaned');
    }

    private function assertArrayHasNoPlaintextPemRecursive(array $arr, string $path): void
    {
        foreach ($arr as $key => $value) {
            $here = $path . '.' . (is_string($key) ? $key : (string) $key);
            if (is_string($value)) {
                self::assertStringNotContainsString(
                    'BEGIN PRIVATE KEY',
                    $value,
                    "PEM fragment leaked into settings row under key '$here'.",
                );
                self::assertStringNotContainsString(
                    'fake-rsa-2048-private-key',
                    $value,
                    "PEM body leaked into settings row under key '$here'.",
                );
            }
            if (is_array($value)) {
                $this->assertArrayHasNoPlaintextPemRecursive($value, $here);
            }
        }
    }
}
