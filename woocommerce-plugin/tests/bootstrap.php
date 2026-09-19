<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. Order matters:
 *
 *   1. Load test-only namespace shims (update_option / delete_option
 *      / get_option) BEFORE composer's autoloader runs. This way,
 *      when Settings.php is autoloaded, the unqualified
 *      `update_option(...)` calls inside it resolve to our
 *      Linopay\WooCommerce\update_option test stub via PHP's
 *      namespace-then-global lookup.
 *
 *   2. Load composer's autoloader.
 *
 *   3. Stub WP functions used elsewhere (wp_salt, sanitize_text_field,
 *      wp_json_encode) — the unit suite doesn't touch these but the
 *      containerised smoke test relies on real WordPress being
 *      available.
 */

require __DIR__ . '/Unit/TestStores.php';
require __DIR__ . '/Unit/WpStubs.php';
require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'unit-test-wp-salt-' . $scheme;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options | JSON_THROW_ON_ERROR, $depth);
    }
}
