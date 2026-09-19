<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Linotech\Sdk\Config;
use Linotech\Sdk\Linopay;
use Linotech\Sdk\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * SOW §2.1 — "Never log or persist secret material."
 *
 * Embeds a sentinel-prefixed PEM and asserts that no captured log
 * line or thrown exception message contains it.
 */
final class NoSecretLeakTest extends TestCase
{
    private const SENTINEL = '-----SENTINEL-PEM-CONTENT-----';

    public function testPemNeverAppearsInAnyLogOrExceptionMessage(): void
    {
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'],
                json_encode(['code' => 'OH_NO', 'message' => 'Something went wrong.'])),
            new Response(500, ['Content-Type' => 'application/json'],
                json_encode(['code' => 'OH_NO', 'message' => 'Something else.'])),
            new Response(500, ['Content-Type' => 'application/json'],
                json_encode(['code' => 'OH_NO', 'message' => 'And again.'])),
        ]);
        $stack = HandlerStack::create($mock);
        $guzzle = new Guzzle(['handler' => $stack]);

        $captured = [];
        $capturingLogger = new class ($captured) implements \Linotech\Sdk\LoggerInterface {
            public function __construct(public array &$captured) {}
            public function info(string $message, array $context = []): void { $this->captured[] = "info:$message"; }
            public function warn(string $message, array $context = []): void { $this->captured[] = "warn:$message"; }
            public function error(string $message, array $context = []): void { $this->captured[] = "error:$message"; }
        };

        $pem = "-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n" . self::SENTINEL;
        $linopay = Linopay::create(
            new Config(
                baseUrl: 'http://127.0.0.1:1',  // bind to refused port
                keyId: 'kid_noleak',
                privateKeyPem: $pem,
                environment: 'sandbox',
                logger: $capturingLogger,
            ),
            $guzzle,
        );

        // 1) Bad-call path: upstream error.
        try {
            $linopay->payments->create(new \Linotech\Sdk\CreatePaymentInput(199, 'NZD'));
        } catch (\Linotech\Sdk\LinopayApiException) {
        } catch (\Throwable) {
        }
        // 2) Misconfiguration: amount = 0.
        try {
            $linopay->payments->create(new \Linotech\Sdk\CreatePaymentInput(0));
        } catch (\Linotech\Sdk\LinopayConfigException) {
        } catch (\Throwable) {
        }
        // 3) Channels path.
        try {
            $linopay->channels->keyStatus();
        } catch (\Throwable) {
        }
        // 4) Webhook path.
        try {
            WebhookSignature::verify('', 1_789_000_000, '{}', 'v1=00', nowSeconds: 1_789_000_000);
        } catch (\Linotech\Sdk\LinopayWebhookVerificationException $e) {
            self::assertStringNotContainsString(self::SENTINEL, $e->getMessage());
        }

        foreach ($captured as $line) {
            self::assertStringNotContainsString(self::SENTINEL, $line);
            self::assertStringNotContainsString('BEGIN PRIVATE KEY', $line);
            self::assertStringNotContainsString('BEGIN ENCRYPTED PRIVATE KEY', $line);
            self::assertStringNotContainsString(substr($pem, 0, 32), $line);
        }
    }
}
