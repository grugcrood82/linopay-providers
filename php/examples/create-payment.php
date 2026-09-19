<?php

declare(strict_types=1);

/**
 * Runnable example: create a one-off payment against a LinoPay-
 * compatible API. Mirrors the Phase 1 TypeScript example (`node/
 * examples/create-payment.ts`).
 *
 * Usage (against the WireMock sandbox):
 *
 *   LINOPAY_BASE_URL=http://localhost:18083 \
 *   LINOPAY_KEY_ID=kid_demo \
 *   LINOPAY_PRIVATE_KEY_PEM_PATH=./.demo-key.pem \
 *   LINOPAY_ENVIRONMENT=sandbox \
 *   LINOPAY_BANK_CODE=ANZ \
 *   php examples/create-payment.php
 *
 * The script writes a friendly summary rather than dumping the whole
 * response object — that's the audience this is for.
 */

use Linotech\Sdk\Config;
use Linotech\Sdk\Linopay;
use Linotech\Sdk\CreatePaymentInput;

require __DIR__ . '/vendor/autoload.php';

$baseUrl = getenv('LINOPAY_BASE_URL') ?: 'http://localhost:18083';
$keyId = getenv('LINOPAY_KEY_ID') ?: 'kid_demo';
$pemPath = getenv('LINOPAY_PRIVATE_KEY_PEM_PATH') ?: './.demo-key.pem';
$environment = getenv('LINOPAY_ENVIRONMENT') ?: 'sandbox';
$bankCode = getenv('LINOPAY_BANK_CODE') ?: 'ANZ';
$amountCentsRaw = getenv('LINOPAY_AMOUNT_CENTS') ?: '199';

if (!ctype_digit($amountCentsRaw) || (int) $amountCentsRaw <= 0) {
    fwrite(STDERR, "LINOPAY_AMOUNT_CENTS must be a positive integer (in cents).\n");
    exit(1);
}
$amountCents = (int) $amountCentsRaw;

if (!is_file($pemPath)) {
    $pem = generateLocalPem($pemPath);
} else {
    $pem = (string) file_get_contents($pemPath);
}

$linopay = Linopay::create(new Config(
    baseUrl: $baseUrl,
    keyId: $keyId,
    privateKeyPem: $pem,
    bankCode: $bankCode,
    environment: $environment,
));

echo "Creating a one-off payment of " . number_format($amountCents / 100, 2) . " NZD ...\n";
$payment = $linopay->payments->create(new CreatePaymentInput($amountCents, 'NZD'));
echo "Saga {$payment->sagaId} created.\n";
echo "  status:      {$payment->status}\n";
echo "  consentUrl:  {$payment->consentUrl}\n";
$qr = substr($payment->qrCodeImage, 0, 60);
echo "  qrCodeImage: {$qr}... ({$payment->qrCodeImage} bytes)\n";

function generateLocalPem(string $path): string
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new \RuntimeException('Could not generate PEM.');
    }
    openssl_pkey_export($resource, $pem);
    file_put_contents($path, $pem);
    chmod($path, 0600);
    return $pem;
}
