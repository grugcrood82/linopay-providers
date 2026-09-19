# Multi-stage Dockerfile for the @linotech/php-sdk test stack.
#
# Stage 1 (composer): install PHP deps in a separate layer for cache friendliness.
# Stage 2 (test):      run PHPUnit against the WireMock upstream.
#
# Default CMD is `vendor/bin/phpunit` against the unit suite. docker-compose
# points the `LINOPAY_E2E_BASE_URL` at the wiremock host so the e2e group
# (configured separately in phpunit.xml.dist) becomes real assertions.

FROM composer:2.7 AS composer
WORKDIR /src
# Copy the entire src/ tree so the classmap entry ("src/Errors.php")
# resolves to a real file at install time. We still copy composer.json
# FIRST so the cache busts when the manifest changes (the most likely
# reason to re-run `composer install`).
COPY composer.json composer.lock* ./
COPY src ./src
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

FROM php:8.3-cli AS test
WORKDIR /src
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer /src/vendor /src/vendor
# Composer binary itself, so the runtime can rebuild deps if needed.
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY . /src
CMD ["vendor/bin/phpunit", "--testdox", "--testsuite", "unit"]
