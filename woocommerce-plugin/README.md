# LinoPay for WooCommerce — `woocommerce-plugin/`

WordPress payment-gateway plugin for LinoPay. Wraps the
[`linotech/sdk`](https://github.com/grugcrood82/linopay-providers/tree/main/php)
PHP SDK; merchants configure a channel key + encrypted PEM in
**WooCommerce → Settings → Payments**; webhooks complete orders
automatically.

> **License:** GPL-2.0-or-later (the WordPress plugin directory
> requires it). The bundled `linotech/sdk` dependency is MIT — MIT is
> GPL-compatible.
>
> **Status:** v0.1.0 — Phase 2 part 2 of the four-phase
> `linopay-providers` roadmap. See
> [the docs hub](https://github.com/grugcrood82/lime-payments/tree/main/docs/master_plans/merchant-sdks-cli-woocommerce)
> for cross-language parity contract and what's deliberately out of
> scope (subscriptions, scheduled payments, etc.).

## What's in this folder

```
woocommerce-plugin/
  linopay-woocommerce.php       # WP plugin metadata + bootstrap hooks.
                                 # The whole entry point; loaded by WP
                                 # on every page request.
  src/
    Gateway.php                 # WC_Payment_Gateway subclass.
                                 # Constructor is cheap (lazy SDK
                                 # client); process_payment() and
                                 # process_admin_options() are the
                                 # only network-touching entry points.
    Settings.php                # Persist posted settings; encrypts
                                 # the PEM into a separate option row
                                 # with autoload=no.
    WebhookHandler.php          # Incoming-webhook dispatch (pure
                                 # PHP, callable-injected order
                                 # lookup so it's unit-testable
                                 # without WordPress).
    Crypto.php                  # AES-256-GCM encryption for the
                                 # channel PEM; keyed off
                                 # wp_salt('auth').
    OrderLookupResult.php       # Adapter for the WC_Order duck-type;
                                 # lets the webhook handler stay
                                 # WP-free at compile time.
    WPLogger.php                # WP_DEBUG-gated PSR-3-style logger
                                 # for the SDK; redacts known-
                                 # sensitive context keys.
  bin/
    wp-init.sh                  # WP-CLI bootstrap (mount, install WP
                                 # if missing, install + activate WC,
                                 # activate the LinoPay plugin).
    install-test-stack.sh       # Smoke test assertions (gateway
                                 # registered, gateway instantiable,
                                 # webhook endpoint reachable, no
                                 # PHP warnings in debug.log).
  tests/
    bootstrap.php               # Loads Composer autoloader; provides
                                 # test stubs for the WordPress
                                 # functions the pure-PHP classes call.
    Unit/                       # Pure-PHP tests (no WordPress).
    WpStubs.php                 # Test-only function stubs in the
                                 # Linopay\WooCommerce namespace so
                                 # Settings.php can call
                                 # update_option() / delete_option()
                                 # / get_option() / __() / error_log()
                                 # without WordPress.
    TestStores.php              # Process-global registries the stubs
                                 # read from / write to.
    CryptoTest.php              # Encryption round-trip, wrong-salt
                                 # failure, tampered-ciphertext
                                 # detection, malformed input,
                                 # error-message leak prevention.
    SettingsTest.php            # PEM encryption, sanitisation, no-
                                 # plaintext-leak into the WC-managed
                                 # settings row.
    WebhookHandlerTest.php       # Pure dispatch logic: signature
                                 # check, replay window, event
                                 # dispatch, missing order, missing
                                 # event, tampered body.
    WPLoggerTest.php            # SDK LoggerInterface conformance,
                                 # WP_DEBUG gating, known-sensitive
                                 # key redaction.
    NoSecretLeakTest.php        # Cross-cutting guard: a known
                                 # plaintext PEM must NEVER appear
                                 # in the encrypted blob, in the
                                 # WC-managed settings row, in any
                                 # error message, or in any log line.
  Dockerfile                    # Multi-stage: composer (vendor +
                                 # SDK path repo) -> test (PHPUnit).
  docker-compose.test.yml       # Two profiles: `--profile unit`
                                 # (PHPUnit, runs on every PR) and
                                 # `--profile smoke` (WordPress +
                                 # WooCommerce + plugin end-to-end;
                                 # see "Smoke test" below).
  composer.json                 # Path-repo dependency on
                                 # `linotech/sdk`; `linotech/sdk` is
                                 # not yet on Packagist (impediment
                                 # A1 in the docs hub).
  phpunit.xml.dist              # PHPUnit config (unit suite only).
```

## Local development

### Prerequisites

- Docker 24+ with Compose v2
- That's it — PHP itself is not required on the host.

### Running the unit suite

```bash
cd linopay-providers
docker compose -f woocommerce-plugin/docker-compose.test.yml \
  --profile unit up --build --abort-on-container-exit \
  --exit-code-from php-unit-tests
docker compose -f woocommerce-plugin/docker-compose.test.yml down -v
```

Expected output: `OK (41 tests, 96 assertions)`, exit code 0.

### Smoke test (WordPress + WooCommerce + plugin)

```bash
docker compose -f woocommerce-plugin/docker-compose.test.yml \
  --profile smoke up --build --abort-on-container-exit \
  --exit-code-from smoke-test
docker compose -f woocommerce-plugin/docker-compose.test.yml down -v
```

> ⚠ **Known issue:** the wp-cli mariadb PHP client (used by the
> `wp-init` container to run WP-CLI) sometimes requires SSL by default
> against MariaDB/MySQL servers. The smoke stack uses the
> `mariadb:10.11` image (wire-compatible with the production MySQL 8
> server) but `mariadb-check` still raises `TLS/SSL error: SSL is
> required, but the server does not support it` on first connect. The
> unit suite (above) is the primary gate; the smoke profile is
> pre-built and ready to wire once the mariadb-client / mariadb-server
> SSL behaviour is sorted out. See commit history for the diagnostics
> trail (`bin/wp-init.sh`, `bin/install-test-stack.sh`).

### Iterating without Docker

If you have PHP 8.1+ + Composer locally:

```bash
cd linopay-providers/woocommerce-plugin
composer install
vendor/bin/phpunit --testsuite unit
```

The unit suite is pure PHP — no WordPress required.

## Architecture decisions

1. **Path-repo for `linotech/sdk`** — the SDK isn't on Packagist yet
   (see docs hub impediment A1). The composer path-repo entry points
   at `../php` and resolves the SDK from the on-disk directory. When
   the SDK is eventually published to Packagist, the
   `repositories:` block in `composer.json` can be removed.

2. **AES-256-GCM encryption for the PEM** — keyed off
   `wp_salt('auth')`, stored in a SEPARATE option row
   (`woocommerce_linopay_settings_pem_encrypted`) with `autoload=no`.
   The PEM must never appear in the WC-managed settings array. See
   `Settings::persist_posted_settings()` and the NoSecretLeakTest.

3. **Signature check first, replay-window check second** — the SDK's
   `Linopay\Sdk\WebhookSignature::verify()` enforces this order; the
   plugin's `WebhookHandler::dispatch()` is a thin wrapper that
   surfaces the result. A merchant who forges a signature must fail
   with `wrong-secret`, never the misleading `replay-out-of-window`.

4. **Pure-PHP webhook dispatch + adapter for WP** — the dispatch
   logic (`dispatch()`) takes the body + signature + timestamp +
   order-lookup callable as inputs and returns a structured result.
   `handle()` is the thin WP wrapper that reads `php://input` +
   `$_SERVER` and writes the HTTP response. The unit suite exercises
   `dispatch()` directly; the smoke test exercises `handle()`.

5. **`WC_Payment_Gateway` is the only WP-bound class.** All the
   plugin's logic lives in pure-PHP classes that can be unit-tested
   without WordPress. The Gateway is a thin adapter that delegates
   to the SDK + WPLogger + WebhookHandler.

## Cross-language parity

The plugin's method surface mirrors the Node TS SDK + PHP SDK +
.NET SDK 1:1 (see the
[docs hub](https://github.com/grugcrood82/lime-payments/tree/main/docs/master_plans/merchant-sdks-cli-woocommerce)).
The webhook signature scheme is identical across all four SDKs;
the plugin's `WebhookHandler` is the merchant-facing entry point
on the WooCommerce side.

## Out of scope (deliberate)

- **Subscription enrollment** — backend CIBA flow isn't on `main`
  yet (PR #317 is the Flexi-Payment deferred collection work, still
  open).
- **Scheduled / future-dated payments** — backend work isn't on
  `main`; building an SDK method would be guessing.
- **Refund / dispute webhook handlers** — backend doesn't emit these
  events yet.
- **Playwright e2e against a real WP+Woo** — per SOW §3, deferred
  to follow-up.
- **Submission to wordpress.org plugin directory** — out of scope
  for this PR; that has its own review process.

## Verification log

```
$ docker compose -f woocommerce-plugin/docker-compose.test.yml \
    --profile unit up --build --abort-on-container-exit \
    --exit-code-from php-unit-tests
...
linotech-woocommerce-unit-tests | ................................. 41 / 41 (100%)
linotech-woocommerce-unit-tests | OK (41 tests, 96 assertions)
linotech-woocommerce-unit-tests exited with code 0
```
