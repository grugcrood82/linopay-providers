<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

use Linopay\WooCommerce\WPLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of {@see WPLogger}.
 *
 *  - Satisfies the SDK's {@see \Linotech\Sdk\LoggerInterface}.
 *  - When WP_DEBUG is off, every method is a no-op (zero error_log
 *    writes).
 *  - When WP_DEBUG + WP_DEBUG_LOG are on, error_log is called once
 *    per call, with the message + a JSON-encoded (redacted) context.
 *  - Sensitive context keys (`pem`, `secret`, `signature`, `token`,
 *    `key_id`, ...) are replaced with `[redacted]`.
 *
 * `error_log()` itself is shadowed by a test stub via the same
 * namespace-shim trick as {@see WpStubs}. Defined in
 * {@see WpFunctionShims} (loaded by the test bootstrap).
 */
final class WPLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void
    {
        $this->logLines = [];
        reset_log_capture();
    }

    public function testImplementsSdkLoggerInterface(): void
    {
        $logger = new WPLogger();
        self::assertInstanceOf(\Linotech\Sdk\LoggerInterface::class, $logger);
    }

    public function testNoOpWhenWpDebugOff(): void
    {
        // WP_DEBUG is not defined in the unit test bootstrap, so the
        // logger should be a no-op regardless of WP_DEBUG_LOG.
        $logger = new WPLogger();
        $logger->info('should not appear', ['key' => 'value']);
        $logger->warn('should not appear either');
        $logger->error('still no');

        self::assertSame([], captured_log_lines());
    }

    public function testLogsWhenWpDebugOn(): void
    {
        if (! defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }
        if (! defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', true);
        }

        $logger = new WPLogger();
        $logger->info('user checked out', ['order_id' => 1234]);

        $lines = captured_log_lines();
        self::assertCount(1, $lines);
        self::assertStringContainsString('[linopay-woocommerce]', $lines[0]);
        self::assertStringContainsString('INFO', $lines[0]);
        self::assertStringContainsString('user checked out', $lines[0]);
        self::assertStringContainsString('"order_id":1234', $lines[0]);
    }

    public function testRedactsKnownSensitiveContextKeys(): void
    {
        if (! defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }
        if (! defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', true);
        }

        $logger = new WPLogger();
        $logger->info(
            'webhook received',
            [
                'order_id'   => 42,
                'pem'        => 'fake-pem-body',
                'private_key_pem' => 'fake-pem-body-2',
                'webhook_secret'  => 'super-secret-value',
                'signature'       => 'v1=deadbeef',
                'token'           => 'eyJ...',
                'authorization'   => 'Bearer eyJ...',
                'key_id'          => 'kid_abc',
            ],
        );

        $line = captured_log_lines()[0] ?? '';
        self::assertStringContainsString('"order_id":42', $line);
        // Sensitive values must NOT appear in the output.
        self::assertStringNotContainsString('fake-pem-body', $line);
        self::assertStringNotContainsString('fake-pem-body-2', $line);
        self::assertStringNotContainsString('super-secret-value', $line);
        self::assertStringNotContainsString('deadbeef', $line);
        self::assertStringNotContainsString('eyJ', $line);
        self::assertStringNotContainsString('kid_abc', $line);
        // Redaction markers should be present instead.
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testRedactionWorksOnNestedContext(): void
    {
        if (! defined('WP_DEBUG')) {
            define('WP_DEBUG', true);
        }
        if (! defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', true);
        }

        $logger = new WPLogger();
        $logger->warn('deeply nested', [
            'request' => [
                'headers' => [
                    'X-LinoPay-Signature' => 'v1=deadbeef',
                ],
                'body' => [
                    'amount_cents' => 199,
                    'webhook_secret' => 'should-be-redacted',
                ],
            ],
        ]);

        $line = captured_log_lines()[0] ?? '';
        self::assertStringContainsString('"amount_cents":199', $line);
        self::assertStringNotContainsString('deadbeef', $line);
        self::assertStringNotContainsString('should-be-redacted', $line);
    }
}
