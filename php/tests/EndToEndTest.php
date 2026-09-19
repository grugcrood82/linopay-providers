<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use Linotech\Sdk\Config;
use Linotech\Sdk\Linopay;
use Linotech\Sdk\LinopayConfigException;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests against a real WireMock server. Skipped unless
 * `LINOPAY_E2E_BASE_URL` is set — the docker-compose self-check sets
 * it. Mirrors the Phase 1 vitest e2e surface exactly.
 *
 * The `Linopay::create` factory will throw before any network call
 * if `LINOPAY_E2E_PEM_PATH` doesn't exist or its contents aren't a
 * valid RSA PEM — that is itself a useful surface, so we assert
 * positively on the "you ran this without env set up" path too.
 *
 * @group e2e
 */
final class EndToEndTest extends TestCase
{
    private static function baseUrl(): ?string
    {
        $v = getenv('LINOPAY_E2E_BASE_URL');
        return ($v === false || $v === '') ? null : $v;
    }

    private static function pemPath(): string
    {
        return getenv('LINOPAY_E2E_PEM_PATH') ?: '/tmp/test-key.pem';
    }

    public function testCreatesAOneOffPaymentAgainstWiremock(): void
    {
        if (self::baseUrl() === null) {
            self::markTestSkipped('Set LINOPAY_E2E_BASE_URL to run this test.');
        }
        $pem = self::ensurePem(self::pemPath());
        $linopay = Linopay::create(new Config(
            baseUrl: self::baseUrl(),
            keyId: 'kid_e2e',
            privateKeyPem: $pem,
            bankCode: 'ANZ',
            environment: 'sandbox',
        ));

        $payment = $linopay->payments->create(new \Linotech\Sdk\CreatePaymentInput(199, 'NZD'));
        self::assertStringStartsWith('wire_saga_', $payment->sagaId);
        self::assertStringStartsWith('data:image/png', $payment->qrCodeImage);
        self::assertStringContainsString('oauth/v2.0/authorize', $payment->consentUrl);
        self::assertSame('Pending', $payment->status);
    }

    public function testCreatesAnInvoiceAfterTokenExchange(): void
    {
        if (self::baseUrl() === null) {
            self::markTestSkipped('Set LINOPAY_E2E_BASE_URL to run this test.');
        }
        $pem = self::ensurePem(self::pemPath());
        $linopay = Linopay::create(new Config(
            baseUrl: self::baseUrl(),
            keyId: 'kid_e2e_invoice',
            privateKeyPem: $pem,
            bankCode: 'ASB',
            environment: 'sandbox',
        ));

        $invoice = $linopay->invoices->create(new \Linotech\Sdk\CreateInvoiceInput(1000, 'NZD', 'wire-test-ref'));
        self::assertStringStartsWith('wire_inv_', $invoice->invoiceId);
        self::assertSame('Outstanding', $invoice->status);
    }

    public function testReadsTheActiveChannelKeyStatus(): void
    {
        if (self::baseUrl() === null) {
            self::markTestSkipped('Set LINOPAY_E2E_BASE_URL to run this test.');
        }
        $pem = self::ensurePem(self::pemPath());
        $linopay = Linopay::create(new Config(
            baseUrl: self::baseUrl(),
            keyId: 'kid_e2e_keystatus',
            privateKeyPem: $pem,
            bankCode: 'BNZ',
            environment: 'sandbox',
        ));

        $status = $linopay->channels->keyStatus();
        self::assertStringStartsWith('wire_kid_', $status->activeKeyId);
        self::assertSame('Active', $status->effectiveStatus);
        self::assertIsInt($status->daysRemaining);
    }

    private static function ensurePem(string $path): string
    {
        if (is_file($path) && filesize($path) > 0) {
            return (string) file_get_contents($path);
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new LinopayConfigException('Could not generate test PEM.');
        }
        openssl_pkey_export($resource, $pem);
        file_put_contents($path, $pem);
        chmod($path, 0600);
        return $pem;
    }
}
