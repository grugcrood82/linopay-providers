<?php
/**
 * Plugin Name:       LinoPay for WooCommerce
 * Plugin URI:        https://github.com/grugcrood82/linopay-providers
 * Description:       Accept LinoPay (open banking / CIBA) payments via the official LinoPay PHP SDK. Merchants configure a sandbox/live channel key + private key in <em>WooCommerce → Settings → Payments</em>; customers complete authorisation at their bank; webhooks mark orders paid automatically.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   8.0
 * Author:            LinoTech Ltd.
 * Author URI:        https://linotech.nz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linopay-woocommerce
 * Domain Path:       /languages
 *
 * @package Linopay_WooCommerce
 */

// Block direct file access. This file is loaded by WordPress during plugin
// activation; anything reading it directly is doing something the plugin
// doesn't support. Match the WP.org plugin-check convention.
defined( 'ABSPATH' ) || exit;

// Plugin constants. Naming convention: LINOPAY_WOOCOMMERCE_*
define( 'LINOPAY_WOOCOMMERCE_VERSION', '0.1.0' );
define( 'LINOPAY_WOOCOMMERCE_FILE', __FILE__ );
define( 'LINOPAY_WOOCOMMERCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINOPAY_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );

// Autoload Composer dependencies (linotech/sdk + phpunit in dev). Composer's
// autoloader is idempotent — multiple calls are no-ops. We register it inside
// the ABSPATH guard so any tooling that includes this file outside WordPress
// (the unit tests do) still gets the autoloader.
if ( file_exists( LINOPAY_WOOCOMMERCE_DIR . 'vendor/autoload.php' ) ) {
    require_once LINOPAY_WOOCOMMERCE_DIR . 'vendor/autoload.php';
}

/**
 * Load the plugin text domain for translations.
 *
 * WordPress.org plugin directory reviews require this hook to exist even if no
 * translations are bundled — it's how WP knows the plugin is i18n-ready.
 */
function linopay_woocommerce_load_textdomain() {
    load_plugin_textdomain(
        'linopay-woocommerce',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'init', 'linopay_woocommerce_load_textdomain' );

/**
 * Register the gateway with WooCommerce.
 *
 * The `woocommerce_payment_gateways` filter is the canonical hook for adding
 * a new gateway. We add our class here so it shows up in the admin settings
 * and is selectable at checkout.
 *
 * @param array<int, string> $gateways Existing gateway class names.
 * @return array<int, string> Modified gateway list.
 */
function linopay_woocommerce_register_gateway( $gateways ) {
    // Only register if WooCommerce is active. WooCommerce's own bootstrap
    // guarantees the WC_Payment_Gateway base class is loaded before this
    // filter fires — see `plugins_loaded` priority below.
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return $gateways;
    }
    $gateways[] = '\\Linopay\\WooCommerce\\Gateway';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'linopay_woocommerce_register_gateway' );

/**
 * Hook the gateway registration after plugins are loaded but before init.
 *
 * `plugins_loaded` runs after all plugins are included; priority 11 puts us
 * after WooCommerce's own bootstrap (priority 10 on the same hook), so the
 * WC_Payment_Gateway base class is guaranteed to be available when our
 * registration function runs.
 */
add_action( 'plugins_loaded', function () {
    // No-op marker hook — WooCommerce's `plugins_loaded` priority ordering
    // ensures the WC_Payment_Gateway base class is loaded before our filter
    // callback runs (see `linopay_woocommerce_register_gateway`).
}, 11 );

/**
 * Plugin activation hook.
 *
 * Sanity-checks the environment (WooCommerce present, PHP 8.1+) and creates
 * the default options rows so the gateway renders cleanly on first install.
 */
function linopay_woocommerce_activate() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__(
                'LinoPay for WooCommerce requires WooCommerce to be installed and active.',
                'linopay-woocommerce'
            ),
            '',
            [ 'back_link' => true ]
        );
    }

    // Default options. The PEM is intentionally empty — merchants must
    // upload their own key. The settings page enforces this.
    add_option( 'woocommerce_linopay_settings', [
        'enabled'      => 'no',
        'title'        => __( 'LinoPay (bank-to-bank)', 'linopay-woocommerce' ),
        'description'  => __( 'Pay securely via your bank. Authorisation happens at your bank; you\'ll be redirected back when complete.', 'linopay-woocommerce' ),
        'environment'  => 'sandbox',
        'key_id'       => '',
        'bank_code'    => 'ANZ',
    ] );
}
register_activation_hook( __FILE__, 'linopay_woocommerce_activate' );

/**
 * Plugin deactivation hook.
 *
 * We deliberately do NOT delete options on deactivation — the merchant
 * might be temporarily disabling the plugin to debug. A separate "reset
 * plugin" button on the settings page is the documented way to wipe state.
 * (WordPress.org plugin-review guidelines: do not silently destroy data
 * on deactivation.)
 */
register_deactivation_hook( __FILE__, function () {
    // No-op: keep settings across deactivations.
} );
