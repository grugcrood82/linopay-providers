<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * Adapter that wraps an order-like object (typically a {@see \WC_Order}
 * in production, a mock in tests) so the webhook handler can call its
 * lifecycle methods without taking a hard dependency on the
 * WooCommerce class.
 *
 * <p>The class is intentionally thin — it just proxies each method
 * call to the wrapped object. The unit tests pass a small stdClass
 * with the same method names; production passes a real WC_Order.</p>
 *
 * <p>This is the same "port + adapter" pattern the SDK uses to keep
 * itself WP-free: the plugin doesn't depend on WooCommerce at
 * compile time; the webhook handler's call paths go through this
 * adapter, which can be backed by anything that implements the
 * duck-typed interface.</p>
 */
final class OrderLookupResult
{
    /**
     * @param object $order An object with these methods: {@code payment_complete(string)},
     *                       {@code add_order_note(string)}, {@code update_status(string, string)},
     *                       and {@code save()}. WC_Order satisfies this; tests pass stdClass.
     */
    public function __construct(
        private readonly object $order,
    ) {
    }

    public function payment_complete(string $transactionId = ''): void
    {
        $this->call('payment_complete', [$transactionId]);
    }

    public function add_order_note(string $note): void
    {
        $this->call('add_order_note', [$note]);
    }

    public function update_status(string $status, string $note = ''): void
    {
        $this->call('update_status', [$status, $note]);
    }

    public function save(): void
    {
        $this->call('save', []);
    }

    /**
     * Invoke a method on the wrapped order object, returning void.
     * If the method doesn't exist on the wrapped object, the test
     * fails loudly (so a missing implementation surfaces as a test
     * failure, not a silent no-op).
     *
     * @param list<mixed> $args
     */
    private function call(string $method, array $args): void
    {
        if (! method_exists($this->order, $method)) {
            throw new \LogicException(
                "Order object is missing required method '{$method}'."
            );
        }
        $this->order->{$method}(...$args);
    }
}
