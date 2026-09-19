<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

use Linopay\WooCommerce\Crypto;
use Linopay\WooCommerce\OrderLookupResult;
use Linopay\WooCommerce\WebhookHandler;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of {@see WebhookHandler::dispatch()} — the pure,
 * injectable-dependency path. {@see WebhookHandler::handle()} (the
 * WP-bound entry point) is exercised by the containerised smoke
 * test.
 *
 *  - Valid signature + payment.completed event → 200 + order marked
 *    paid via {@code payment_complete()} + {@code save()}.
 *  - Valid signature + payment.failed event → 200 + order updated to
 *    on-hold.
 *  - Bad signature (wrong secret) → 400, no order interaction.
 *  - Expired timestamp (replay-out-of-window) → 401.
 *  - Unknown event type → 202 (acknowledged, ignored).
 *  - Missing saga_id → 400 (malformed event).
 *  - Order not found → 404.
 *  - Invalid JSON body → 400.
 */
final class WebhookHandlerTest extends TestCase
{
    private const WEBHOOK_SECRET = 'unit-test-webhook-secret-not-real';

    private Crypto $crypto;

    private WebhookHandler $handler;

    protected function setUp(): void
    {
        $this->crypto = new Crypto('unit-test-salt');
        $this->handler = new WebhookHandler($this->crypto);
    }

    /**
     * Build a signed body + signature + timestamp for tests.
     */
    private function makeSignedPayload(array $event, ?string $secret = null, ?int $timestamp = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = $timestamp ?? time();
        $sig = \Linotech\Sdk\WebhookSignature::compute($secret ?? self::WEBHOOK_SECRET, $ts, $body);
        return [$body, $sig, $ts];
    }

    public function testValidPaymentCompletedMarksOrderPaid(): void
    {
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type'    => 'payment.completed',
            'sagaId'  => 'wire_saga_test_001',
            'amount'  => '1.99',
            'currency' => 'NZD',
        ]);

        $order = $this->makeMockOrder();
        $lookup = static fn (string $sagaId) => $sagaId === 'wire_saga_test_001' ? new OrderLookupResult($order) : null;

        $result = $this->handler->dispatch($body, $sig, $ts, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(200, $result['status']);
        self::assertSame('ok', $result['message']);
        self::assertSame('wire_saga_test_001', $order->payment_complete_calls[0]);
        self::assertCount(1, $order->save_calls);
        self::assertNotEmpty($order->add_order_note_calls);
    }

    public function testValidPaymentFailedUpdatesOrderStatus(): void
    {
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type'    => 'payment.failed',
            'sagaId'  => 'wire_saga_test_failed',
            'reason'  => 'customer_declined',
        ]);

        $order = $this->makeMockOrder();
        $lookup = static fn (string $sagaId) => $sagaId === 'wire_saga_test_failed' ? new OrderLookupResult($order) : null;

        $result = $this->handler->dispatch($body, $sig, $ts, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(200, $result['status']);
        self::assertSame('ok', $result['message']);
        self::assertCount(1, $order->update_status_calls);
        self::assertSame('on-hold', $order->update_status_calls[0]['status']);
    }

    public function testBadSignatureIsRejectedWith400(): void
    {
        $event = ['type' => 'payment.completed', 'sagaId' => 'wire_x'];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $ts = time();
        // Sign with a DIFFERENT secret.
        $bogusSig = \Linotech\Sdk\WebhookSignature::compute('wrong-secret', $ts, $body);

        $order = $this->makeMockOrder();
        $lookup = static fn () => new OrderLookupResult($order);

        $result = $this->handler->dispatch($body, $bogusSig, $ts, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(400, $result['status']);
        self::assertSame('signature_verification_failed', $result['message']);
        // The order MUST NOT have been touched.
        self::assertSame([], $order->payment_complete_calls);
        self::assertSame([], $order->save_calls);
    }

    public function testExpiredTimestampIsRejectedWith401(): void
    {
        $event = ['type' => 'payment.completed', 'sagaId' => 'wire_x'];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        // Timestamp from one hour ago — well outside the 5-minute
        // replay window. The signature is computed correctly; it's
        // the TIMESTAMP that's stale.
        $expiredTs = time() - 3600;
        $sig = \Linotech\Sdk\WebhookSignature::compute(self::WEBHOOK_SECRET, $expiredTs, $body);

        $order = $this->makeMockOrder();
        $lookup = static fn () => new OrderLookupResult($order);

        $result = $this->handler->dispatch($body, $sig, $expiredTs, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(401, $result['status']);
        self::assertSame([], $order->payment_complete_calls);
    }

    public function testUnknownEventTypeReturns202(): void
    {
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type' => 'something.we.dont.handle.yet',
            'sagaId' => 'wire_x',
        ]);

        $order = $this->makeMockOrder();
        $lookup = static fn () => new OrderLookupResult($order);

        $result = $this->handler->dispatch($body, $sig, $ts, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(202, $result['status']);
        self::assertSame('ignored', $result['message']);
        self::assertSame([], $order->payment_complete_calls);
    }

    public function testMissingSagaIdReturns400(): void
    {
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type' => 'payment.completed',
            // 'sagaId' intentionally missing
        ]);

        $result = $this->handler->dispatch(
            $body,
            $sig,
            $ts,
            self::WEBHOOK_SECRET,
            static fn () => null,
        );

        self::assertSame(400, $result['status']);
        self::assertSame('missing_saga_id', $result['message']);
    }

    public function testOrderNotFoundReturns404(): void
    {
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type'   => 'payment.completed',
            'sagaId' => 'wire_saga_orphan',
        ]);

        $result = $this->handler->dispatch(
            $body,
            $sig,
            $ts,
            self::WEBHOOK_SECRET,
            static fn () => null, // order not found
        );

        self::assertSame(404, $result['status']);
        self::assertSame('order_not_found', $result['message']);
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $body = 'this is not json';
        $ts = time();
        $sig = \Linotech\Sdk\WebhookSignature::compute(self::WEBHOOK_SECRET, $ts, $body);

        $result = $this->handler->dispatch(
            $body,
            $sig,
            $ts,
            self::WEBHOOK_SECRET,
            static fn () => null,
        );

        self::assertSame(400, $result['status']);
        self::assertSame('invalid_json', $result['message']);
    }

    public function testTamperedBodyIsRejectedWith400(): void
    {
        // Sign one body, send a different one. The signature check
        // must fail and we MUST NOT call into the order (a forgery
        // attempt shouldn't mutate any state).
        $originalBody = json_encode(['type' => 'payment.completed', 'sagaId' => 'wire_1']);
        $ts = time();
        $sig = \Linotech\Sdk\WebhookSignature::compute(self::WEBHOOK_SECRET, $ts, $originalBody);

        $tamperedBody = json_encode(['type' => 'payment.completed', 'sagaId' => 'wire_other']);

        $order = $this->makeMockOrder();
        $lookup = static fn () => new OrderLookupResult($order);

        $result = $this->handler->dispatch($tamperedBody, $sig, $ts, self::WEBHOOK_SECRET, $lookup);

        self::assertSame(400, $result['status']);
        self::assertSame([], $order->payment_complete_calls);
    }

    public function testEmptySecretFailsVerification(): void
    {
        // Real-world scenario: merchant hasn't yet configured their
        // webhook secret (they might be in the middle of rotating it).
        // The SDK's signature check fails closed; we MUST NOT then
        // proceed to look up an order.
        [$body, $sig, $ts] = $this->makeSignedPayload([
            'type'   => 'payment.completed',
            'sagaId' => 'wire_x',
        ]);

        $order = $this->makeMockOrder();
        $lookup = static fn () => new OrderLookupResult($order);

        $result = $this->handler->dispatch($body, $sig, $ts, '', $lookup);

        self::assertSame(400, $result['status']);
        self::assertSame([], $order->payment_complete_calls);
    }

    /**
     * Build a mock WC_Order-like object with the four lifecycle
     * methods the adapter expects. Records every call so the tests
     * can assert on them.
     */
    private function makeMockOrder(): object
    {
        return new class () {
            /** @var list<string> */
            public array $payment_complete_calls = [];

            /** @var list<string> */
            public array $add_order_note_calls = [];

            /** @var list<array{status: string, note: string}> */
            public array $update_status_calls = [];

            /** @var list<array{0: string, 1: string}> */
            public array $save_calls = [];

            public function payment_complete(string $transactionId = ''): void
            {
                $this->payment_complete_calls[] = $transactionId;
            }

            public function add_order_note(string $note): void
            {
                $this->add_order_note_calls[] = $note;
            }

            public function update_status(string $status, string $note = ''): void
            {
                $this->update_status_calls[] = ['status' => $status, 'note' => $note];
            }

            public function save(): void
            {
                $this->save_calls[] = ['', ''];
            }
        };
    }
}
