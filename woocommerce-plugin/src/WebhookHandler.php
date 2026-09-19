<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * Incoming-webhook handler for LinoPay.
 *
 * <p>When the bank authorises (or declines) a customer's payment,
 * LinoPay's webhook dispatcher POSTs a signed JSON body to the
 * merchant's webhook URL. This class:</p>
 * <ol>
 *   <li>Reads the raw body and signature headers from the WP request
 *       (see {@see handle()}).</li>
 *   <li>Delegates signature verification to the SDK's
 *       {@see \Linotech\Sdk\WebhookSignature::verify()} — which does
 *       signature check FIRST, replay-window check SECOND (per the
 *       cross-language parity rule).</li>
 *   <li>On a valid signature, parses the event and dispatches by event
 *       type. The only events we handle today are
 *       {@code payment.completed} (mark the order paid) and
 *       {@code payment.failed} (mark the order on-hold). Future event
 *       types — refund, dispute — are deliberately not implemented
 *       until the backend lands them on {@code main}.</li>
 * </ol>
 *
 * <p><strong>Why a separate class?</strong> the {@see Gateway} class
 * extends {@see \WC_Payment_Gateway}, which can only be instantiated
 * inside WordPress. This handler needs to be unit-testable without
 * WordPress (see {@code tests/Unit/WebhookHandlerTest.php}), so the
 * dispatch logic is split into {@see dispatch()} (pure, injectable
 * dependencies) and {@see handle()} (WP-bound, thin wrapper).</p>
 */
final class WebhookHandler
{
    /**
     * Option key holding the per-channel webhook signing secret.
     * The secret is generated server-side by
     * {@code RotateChannelWebhookSecretHandler} and rotated on
     * merchant demand; we mirror it here as a plain string.
     *
     * <p>This is NOT the PEM. The PEM is encrypted at rest (see
     * {@see Crypto}). The webhook secret is also sensitive — but it's
     * not the customer's bank credential; the encryption-at-rest is
     * defence in depth, not a hard requirement. We document the trade-off
     * but don't encrypt it in v0.1.</p>
     */
    public const OPTION_WEBHOOK_SECRET = 'linopay_webhook_secret';

    /**
     * Header name carrying the HMAC signature. Defined as a constant
     * so the gateway (which documents the webhook URL for merchants
     * to point LinoPay at) and the SDK stay in lock-step.
     */
    public const HEADER_SIGNATURE = 'X-LinoPay-Signature';

    public const HEADER_TIMESTAMP = 'X-LinoPay-Timestamp';

    public function __construct(
        private readonly Crypto $crypto,
    ) {
    }

    /**
     * Read the WP request, dispatch the event, write the response.
     *
     * <p>This is the only WP-bound method on the class. Every other
     * public entry point is pure and unit-testable.</p>
     */
    public function handle(): void
    {
        $body = (string) file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_LINOPAY_SIGNATURE'] ?? null;
        $timestampHeader = $_SERVER['HTTP_X_LINOPAY_TIMESTAMP'] ?? null;

        if (! is_string($signature) || ! is_string($timestampHeader)) {
            $this->respond(400, 'missing_signature_header');
            return;
        }

        $secret = (string) get_option(self::OPTION_WEBHOOK_SECRET, '');
        $orderLookup = static function (string $sagaId): ?OrderLookupResult {
            return self::default_order_lookup($sagaId);
        };

        $result = $this->dispatch(
            body: $body,
            signature: $signature,
            timestamp: (int) $timestampHeader,
            secret: $secret,
            orderLookup: $orderLookup,
        );

        $this->respond($result['status'], $result['message']);
    }

    /**
     * Pure dispatch logic — takes everything it needs as inputs,
     * returns a structured result. The test suite exercises this
     * method directly; {@see handle()} is the thin WP wrapper.
     *
     * @param string             $body        Raw HTTP body.
     * @param string             $signature   The {@code X-LinoPay-Signature}
     *                                         header value.
     * @param int                $timestamp   The {@code X-LinoPay-Timestamp}
     *                                         header value (Unix seconds).
     * @param string             $secret      The per-channel webhook secret
     *                                         (stored in {@code wp_options}).
     * @param callable(string): ?OrderLookupResult $orderLookup
     *                                         Callable that, given a saga ID,
     *                                         returns the matching WC order
     *                                         (or null). Tests inject a stub.
     * @return array{status: int, message: string}
     */
    public function dispatch(
        string $body,
        string $signature,
        int $timestamp,
        string $secret,
        callable $orderLookup,
    ): array {
        try {
            \Linotech\Sdk\WebhookSignature::verify($secret, $timestamp, $body, $signature);
        } catch (\Linotech\Sdk\LinopayWebhookVerificationException $e) {
            // Signature check FIRST, replay-window check SECOND.
            // A forgery must surface as 400 (wrong-secret or
            // tampered); a replay-out-of-window is 401 (still valid
            // signature, but the merchant clock is off).
            $status = $e->reason === \Linotech\Sdk\LinopayWebhookVerificationException::REASON_REPLAY_OUT_OF_WINDOW
                ? 401
                : 400;
            return ['status' => $status, 'message' => 'signature_verification_failed'];
        }

        $event = json_decode($body, associative: true);
        if (! is_array($event)) {
            return ['status' => 400, 'message' => 'invalid_json'];
        }

        $type = $event['type'] ?? '';
        $sagaId = (string) ($event['sagaId'] ?? '');

        return match ($type) {
            'payment.completed' => $this->handle_payment_completed($sagaId, $orderLookup),
            'payment.failed'    => $this->handle_payment_failed($sagaId, $orderLookup),
            default             => ['status' => 202, 'message' => 'ignored'],
        };
    }

    /**
     * Mark the order paid. The SDK's signature check has already
     * authenticated the request — we trust the saga_id in the body.
     *
     * @param callable(string): ?OrderLookupResult $orderLookup
     * @return array{status: int, message: string}
     */
    private function handle_payment_completed(string $sagaId, callable $orderLookup): array
    {
        if ($sagaId === '') {
            return ['status' => 400, 'message' => 'missing_saga_id'];
        }

        $order = $orderLookup($sagaId);
        if ($order === null) {
            return ['status' => 404, 'message' => 'order_not_found'];
        }

        $order->payment_complete($sagaId);
        $order->add_order_note(
            sprintf(
                /* translators: %s: LinoPay transaction saga ID. */
                __('LinoPay webhook: payment completed for saga %s.', 'linopay-woocommerce'),
                $sagaId,
            ),
        );
        $order->save();

        return ['status' => 200, 'message' => 'ok'];
    }

    /**
     * Mark the order failed. Keeps the order in "on-hold" rather than
     * cancelling it — the merchant may want to retry or contact the
     * customer manually.
     *
     * @param callable(string): ?OrderLookupResult $orderLookup
     * @return array{status: int, message: string}
     */
    private function handle_payment_failed(string $sagaId, callable $orderLookup): array
    {
        $order = $sagaId !== '' ? $orderLookup($sagaId) : null;
        if ($order !== null) {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: %s: LinoPay transaction saga ID. */
                    __('LinoPay webhook: payment failed for saga %s.', 'linopay-woocommerce'),
                    $sagaId,
                ),
            );
        }
        return ['status' => 200, 'message' => 'ok'];
    }

    /**
     * The default order-lookup callable used by {@see handle()}.
     * Delegates to WooCommerce's {@code wc_get_orders()} meta query.
     * Standalone (not in {@see Gateway}) so the webhook handler has
     * no compile-time dependency on WC_Payment_Gateway.
     */
    private static function default_order_lookup(string $sagaId): ?OrderLookupResult
    {
        if (! function_exists('wc_get_orders')) {
            return null;
        }
        $orders = wc_get_orders([
            'meta_key'   => Gateway::META_SAGA_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $sagaId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'limit'      => 1,
        ]);
        $order = $orders[0] ?? null;
        if (! $order) {
            return null;
        }
        return new OrderLookupResult($order);
    }

    /**
     * Emit the HTTP response. {@code $status} is a WC-friendly HTTP
     * code (200/202/400/401/404); {@code $message} is a short token
     * that ends up in the LinoPay dispatcher logs (so a merchant
     * triaging a missed webhook can tell at a glance what we said).
     */
    private function respond(int $status, string $message): void
    {
        http_response_code($status);
        // Don't echo user input — the message is one of our own constants.
        echo esc_html($message);
        if (function_exists('wp_die')) {
            wp_die('', '', ['response' => $status]);
        }
        exit;
    }
}
