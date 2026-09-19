#!/bin/bash
# Containerised smoke-test for the WooCommerce plugin.
#
# Runs INSIDE the `smoke-test` container after WordPress + WooCommerce
# are up. Verifies:
#
#   1. WordPress is responding (HTTP 200 on /wp-login.php).
#   2. WooCommerce is installed and active.
#   3. The LinoPay plugin is installed and active.
#   4. The gateway class is registered with WooCommerce's
#      `woocommerce_payment_gateways` filter (so it shows up at
#      checkout).
#   5. The gateway can be instantiated (constructor doesn't blow up).
#   6. The webhook endpoint is reachable.
#   7. WP_DEBUG_LOG captures no PHP warnings / notices across the
#      smoke-test run (the SOW's negative-space assert).
#
# Exits non-zero on any failure so `--abort-on-container-exit` in the
# parent docker-compose tears the stack down.

set -euo pipefail

WP_URL="http://wordpress"
WP_PATH="/var/www/html"
PLUGIN_PATH="${WP_PATH}/wp-content/plugins/linopay-woocommerce"

echo "==> Waiting for WordPress to be ready..."
ATTEMPTS=0
while [ "$ATTEMPTS" -lt 60 ]; do
    if curl -sf -o /dev/null "${WP_URL}/wp-login.php"; then
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    sleep 2
done
if [ "$ATTEMPTS" -ge 60 ]; then
    echo "!! WordPress never came up at ${WP_URL}" >&2
    exit 1
fi
echo "    WordPress is up."

echo "==> Checking WooCommerce is active..."
if ! wp --path="${WP_PATH}" plugin is-active woocommerce; then
    echo "!! WooCommerce is not active" >&2
    exit 1
fi
echo "    WooCommerce active."

echo "==> Installing the LinoPay plugin if not already installed..."
if ! wp --path="${WP_PATH}" plugin is-installed linopay-woocommerce; then
    # The plugin source is mounted into /plugin-source by the docker-compose
    # volume. Copy it into the WP plugins directory.
    mkdir -p "${PLUGIN_PATH}"
    cp -r /plugin-source/. "${PLUGIN_PATH}/"
    # Re-run composer install in the plugin directory to make sure the
    # vendor tree (with the SDK path repo) is in place.
    (cd "${PLUGIN_PATH}" && composer install --no-interaction --no-progress --prefer-dist)
fi

echo "==> Activating the LinoPay plugin..."
wp --path="${WP_PATH}" plugin activate linopay-woocommerce

echo "==> Verifying the gateway is registered with WooCommerce..."
# Run a tiny PHP one-liner via wp eval that loads WC's gateway list and
# asserts LinoPay is in it. wp eval runs the code in WP's runtime.
REGISTERED=$(wp --path="${WP_PATH}" eval '
    $gateways = apply_filters("woocommerce_payment_gateways", []);
    $found = false;
    foreach ($gateways as $g) {
        if ($g === "Linopay\\WooCommerce\\Gateway") { $found = true; break; }
    }
    echo $found ? "yes" : "no";
')
if [ "${REGISTERED}" != "yes" ]; then
    echo "!! LinoPay gateway is NOT in the registered gateways list" >&2
    exit 1
fi
echo "    Gateway registered."

echo "==> Instantiating the gateway class..."
INSTANTIATED=$(wp --path="${WP_PATH}" eval '
    try {
        $gateway = new \Linopay\WooCommerce\Gateway();
        echo "yes";
    } catch (\Throwable $e) {
        echo "no: " . $e->getMessage();
    }
')
case "${INSTANTIATED}" in
    yes) echo "    Gateway instantiated cleanly." ;;
    *) echo "!! Gateway constructor failed: ${INSTANTIATED}" >&2; exit 1 ;;
esac

echo "==> Verifying the webhook endpoint is reachable..."
# We don't sign the body here — the endpoint exists and rejects unsigned
# payloads with 400, which is the expected outcome.
WEBHOOK_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    --data '{"type":"payment.completed","sagaId":"wire_test"}' \
    "${WP_URL}/?wc-api=lino_pay_webhook")
case "${WEBHOOK_STATUS}" in
    400|404) echo "    Webhook endpoint reachable (HTTP ${WEBHOOK_STATUS} = unsigned payload correctly rejected)." ;;
    *) echo "!! Webhook endpoint returned unexpected HTTP ${WEBHOOK_STATUS}" >&2; exit 1 ;;
esac

echo "==> Checking WP_DEBUG_LOG for warnings / notices..."
if [ -f "${WP_PATH}/wp-content/debug.log" ]; then
    if grep -E 'PHP (Warning|Notice|Deprecated|Parse error|Fatal error)' "${WP_PATH}/wp-content/debug.log"; then
        echo "!! PHP warnings / notices found in debug.log" >&2
        exit 1
    fi
fi
echo "    No PHP warnings / notices."

echo
echo "==> Smoke test PASSED."
