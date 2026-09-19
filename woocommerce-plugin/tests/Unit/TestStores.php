<?php

declare(strict_types=1);

namespace Linopay\WooCommerce\Tests;

/**
 * Process-global registries the WpStubs read from and write to.
 *
 * <p>PHP functions can only return scalars / arrays by value, not
 * modify references from the caller. The stubs therefore store their
 * state in static arrays on these test-only helper functions, which
 * the test setUp() resets. This keeps the stub itself stateless and
 * the test fully deterministic.</p>
 *
 * @internal Test-only.
 */

function &test_options_store(): array
{
    static $store = [];
    return $store;
}

function &test_deleted_options(): array
{
    static $deleted = [];
    return $deleted;
}

function &test_autoload_hints(): array
{
    static $hints = [];
    return $hints;
}

function &captured_log_lines(): array
{
    static $lines = [];
    return $lines;
}

function reset_test_stores(): void
{
    $store = & test_options_store();
    $store = [];
    $deleted = & test_deleted_options();
    $deleted = [];
    $hints = & test_autoload_hints();
    $hints = [];
}

function reset_log_capture(): void
{
    $lines = & captured_log_lines();
    $lines = [];
}
