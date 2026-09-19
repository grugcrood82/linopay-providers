<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * Test-only namespace shims for the WordPress / WooCommerce
 * functions used by the plugin's pure-PHP classes.
 *
 * <p>PHP resolves an unqualified function call inside a namespace by
 * looking up the function in the current namespace FIRST, then the
 * global namespace. By declaring `update_option` / `delete_option`
 * / `get_option` / `error_log` inside {@see \Linopay\WooCommerce}, we
 * shadow the (non-existent in the test environment) global ones
 * whenever the plugin's source files are autoloaded during a unit
 * test.</p>
 *
 * <p>The shims read / write process-global registries so the test
 * can assert on what the plugin persisted. Production code never
 * loads this file; the test bootstrap includes it before composer's
 * autoloader runs.</p>
 *
 * @internal Test-only. Never loaded in production.
 */

if (! function_exists(__NAMESPACE__ . '\\update_option')) {
    function update_option(string $key, mixed $value, ?bool $autoload = null): bool
    {
        $store = & \Linopay\WooCommerce\Tests\test_options_store();
        $store[$key] = $value;
        if ($autoload !== null) {
            $hints = & \Linopay\WooCommerce\Tests\test_autoload_hints();
            $hints[] = ['key' => $key, 'autoload' => $autoload];
        }
        return true;
    }
}

if (! function_exists(__NAMESPACE__ . '\\delete_option')) {
    function delete_option(string $key): bool
    {
        $store = & \Linopay\WooCommerce\Tests\test_options_store();
        $deleted = & \Linopay\WooCommerce\Tests\test_deleted_options();
        $deleted[] = $key;
        unset($store[$key]);
        return true;
    }
}

if (! function_exists(__NAMESPACE__ . '\\get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        $store = & \Linopay\WooCommerce\Tests\test_options_store();
        return $store[$key] ?? $default;
    }
}

if (! function_exists(__NAMESPACE__ . '\\error_log')) {
    /**
     * Shadow for `error_log()` that captures the message instead of
     * writing to the SAPI log. {@see WPLogger} writes to the SDK
     * logger via this — capturing here lets the tests assert on
     * exactly what would have hit `wp-content/debug.log`.
     */
    function error_log(string $message, int $message_type = 0, ?string $destination = null, ?string $extra_headers = null): bool
    {
        $lines = & \Linopay\WooCommerce\Tests\captured_log_lines();
        $lines[] = $message;
        return true;
    }
}

if (! function_exists(__NAMESPACE__ . '\\__')) {
    /**
     * WordPress i18n function. Stub: returns the input string
     * unchanged so unit tests don't have to register text domains.
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
