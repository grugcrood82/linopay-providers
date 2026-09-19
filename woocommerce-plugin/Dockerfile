# Multi-stage Dockerfile for the WooCommerce plugin test stack.
#
# Stage 1 (composer): install PHP deps. The build context (set by
# docker-compose) is the linopay-providers root, so we can COPY the
# SDK from `php/`. The path repo declared in `composer.json` makes
# Composer resolve `linotech/sdk` from `../php` (i.e. `/src/php` in
# this image), which is where we COPY it.
#
# The classmap entry `src/Crypto.php` in composer.json needs the file
# to exist on disk BEFORE composer install runs (so the classmap
# generator can resolve it). So we COPY src/ before the install.
#
# Stage 2 (test): run PHPUnit against the unit suite. Pure PHP — no
# WordPress needed.
#
# Stage 3 (smoke): full WordPress + WooCommerce + plugin stack. Runs
# `bin/install-test-stack.sh` which verifies the plugin is active,
# the gateway is registered, the webhook endpoint is reachable, and
# no PHP warnings are logged.

FROM composer:2.7 AS composer
WORKDIR /src
# Copy composer.json + the SDK + the plugin source FIRST so the
# path repo entry in composer.json can resolve during install AND
# the classmap generator can scan src/.
COPY woocommerce-plugin/composer.json ./woocommerce-plugin/
COPY woocommerce-plugin/src ./woocommerce-plugin/src
COPY php ./php
# Install from inside the plugin dir so the path-repo
# `url: ../php` resolves to /src/php.
WORKDIR /src/woocommerce-plugin
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

# Stage 2 — pure-PHP unit tests (no WP dependency).
FROM php:8.3-cli AS test
WORKDIR /src
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer /src/woocommerce-plugin/vendor /src/woocommerce-plugin/vendor
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY woocommerce-plugin /src/woocommerce-plugin
WORKDIR /src/woocommerce-plugin
CMD ["vendor/bin/phpunit", "--testdox"]

# Stage 3 — WordPress smoke test. Uses the official `wordpress:cli`
# image so we get wp-cli preinstalled. The image is Debian-based and
# ships with bash + curl + jq, so no extra packages are needed.
FROM wordpress:cli-php8.3 AS smoke
USER root
# Copy the plugin source so the smoke script can `cp -r` it into the
# WP plugins directory after WordPress is up.
COPY woocommerce-plugin /plugin-source
# Make the smoke script executable (git doesn't always preserve mode).
RUN chmod +x /plugin-source/bin/install-test-stack.sh
USER www-data
CMD ["/plugin-source/bin/install-test-stack.sh"]
