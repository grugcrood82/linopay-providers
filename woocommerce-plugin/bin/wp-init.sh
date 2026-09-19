#!/bin/bash
# WP-CLI bootstrap for the WooCommerce plugin smoke test.
#
# Runs INSIDE the `wp-init` container after the `wordpress` and `db`
# services are healthy. Installs WordPress (if first boot), installs
# + activates WooCommerce, and activates the LinoPay plugin. Exits
# non-zero on any failure so `--abort-on-container-exit` tears the
# stack down.

set -e

echo "==> Waiting for WordPress to be reachable..."
ATTEMPTS=0
while [ "$ATTEMPTS" -lt 60 ]; do
    if curl -sf -o /dev/null http://wordpress/wp-login.php; then
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    sleep 2
done
if [ "$ATTEMPTS" -ge 60 ]; then
    echo "!! WordPress never came up" >&2
    exit 1
fi

echo "==> Waiting for the DB connection to settle..."
ATTEMPTS=0
while [ "$ATTEMPTS" -lt 30 ]; do
    # `wp db check` is the canonical "can I talk to MySQL?" probe
    # from inside WP's runtime. It picks up DB_HOST etc. from
    # wp-config.php.
    if wp --path=/var/www/html db check >/dev/null 2>&1; then
        break
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    sleep 2
done
if [ "$ATTEMPTS" -ge 30 ]; then
    echo "!! Database connection never came up" >&2
    echo "   Run 'docker compose logs db wordpress' to diagnose." >&2
    exit 1
fi

if [ ! -f /var/www/html/wp-config.php ]; then
    echo "==> Installing WordPress..."
    wp --path=/var/www/html core install \
        --url=http://wordpress \
        --title="LinoPay Smoke Test" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=admin@example.com \
        --skip-email
fi
if ! wp --path=/var/www/html plugin is-installed woocommerce; then
    echo "==> Installing WooCommerce..."
    wp --path=/var/www/html plugin install woocommerce --activate
fi
if wp --path=/var/www/html plugin is-installed linopay-woocommerce; then
    echo "==> Activating the LinoPay plugin..."
    wp --path=/var/www/html plugin activate linopay-woocommerce
fi

echo "==> wp-init done."
