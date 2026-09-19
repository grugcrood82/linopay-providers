<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * WooCommerce payment-gateway integration for LinoPay.
 *
 * <p>This class is the WC_Payment_Gateway subclass that WooCommerce
 * discovers via the {@code woocommerce_payment_gateways} filter (see
 * the plugin's main file). It is intentionally thin: it
 * <em>delegates</em> everything it can — JWT signing, HTTP transport,
 * error translation — to the {@code linotech/sdk} package, and only
 * contains the WooCommerce-specific glue (settings persistence,
 * order meta, webhook receiver wiring).</p>
 *
 * <p><strong>Lifecycle:</strong></p>
 * <ol>
 *   <li>WooCommerce loads this class via the {@code woocommerce_payment_gateways}
 *       filter and instantiates it once per admin request (or once per
 *       checkout request — WC calls the gateway fresh on every checkout
 *       page load, so constructors must be cheap).</li>
 *   <li>{@see Gateway::__construct()} reads the persisted settings (key ID,
 *       encrypted PEM, environment, bank code) and lazily builds a
 *       {@code Linotech\Sdk\Linopay} client from them.</li>
 *   <li>When a customer places an order, {@see Gateway::process_payment()}
 *       calls the SDK to create a one-off payment, stores the saga ID
 *       on the order, and returns the bank consent URL as the redirect
 *       target.</li>
 *   <li>When the bank authorisation completes, LinoPay's webhook
 *       receiver posts a signed event to the merchant's webhook URL;
 *       {@see WebhookHandler::handle()} verifies the signature, looks
 *       up the order by saga ID, and calls
 *       {@code $order->payment_complete()}.</li>
 * </ol>
 *
 * <p><strong>Testability:</strong> the SDK call sites and the webhook
 * handler are unit-tested without WordPress in {@code tests/Unit/}.
 * This class itself is exercised by the containerised smoke test
 * ({@code bin/install-test-stack.sh}) which boots a real WordPress +
 * WooCommerce + plugin stack and asserts the gateway is registered
 * and reachable.</p>
 */
final class Gateway extends \WC_Payment_Gateway
{
    /**
     * Option key under which the gateway's settings live. Follows the
     * WooCommerce convention {@code woocommerce_<gateway-id>_settings}.
     * Persisted by {@code WC_Payment_Gateway::process_admin_options()}.
     */
    public const OPTION_KEY = 'woocommerce_linopay_settings';

    /**
     * Order meta key holding the LinoPay transaction saga ID. Used to
     * correlate incoming webhook events with the originating order.
     * Underscore prefix marks it internal (hidden from the admin UI
     * by default in WooCommerce's order meta box).
     */
    public const META_SAGA_ID = '_linopay_saga_id';

    /**
     * Order meta key holding the bank consent URL the customer is
     * redirected to. Not strictly required to persist (the SDK
     * returns it in {@see process_payment} and we redirect
     * immediately), but stored so an admin can re-send the link if
     * the customer closes the tab.
     */
    public const META_CONSENT_URL = '_linopay_consent_url';

    private ?\Linotech\Sdk\Linopay $sdk = null;

    private ?WebhookHandler $webhookHandler = null;

    private ?Crypto $crypto = null;

    /**
     * Constructor. Called by WooCommerce on every checkout page load —
     * must be cheap.
     *
     * <p>Side effects (in order):</p>
     * <ol>
     *   <li>Set the gateway's identity fields (id, method_title, etc.).
     *       These are read by WooCommerce's admin UI and the checkout
     *       rendering pipeline.</li>
     *   <li>Define the settings form via {@see init_form_fields()}.</li>
     *   <li>Load persisted settings via {@see init_settings()}.</li>
     *   <li>Wire the WooCommerce action hooks for "save settings" and
     *       "incoming webhook".</li>
     * </ol>
     */
    public function __construct()
    {
        // 1. Identity. These three are read by WC_Payment_Gateway's
        // parent constructor, so set them BEFORE calling parent::__construct().
        $this->id                 = 'linopay';
        $this->method_title       = __('LinoPay', 'linopay-woocommerce');
        $this->method_description = __('Accept open-banking payments via LinoPay. Authorisation happens at the customer\'s bank; webhooks complete orders automatically.', 'linopay-woocommerce');
        $this->has_fields         = false;
        $this->supports           = ['products'];

        // 2. Settings + admin save hook. The parent constructor reads
        // $this->id and uses it to compute the option key; calling parent
        // here is what triggers init_form_fields() and init_settings().
        parent::__construct();

        // 3. Webhook receiver. WooCommerce's WC-API dispatcher fires the
        // `woocommerce_api_{endpoint}` action for any /?wc-api={endpoint}
        // request, so we register the handler under the endpoint name we
        // document in the settings page (`linopay_webhook`).
        add_action('woocommerce_api_lino_pay_webhook', [$this, 'handle_webhook']);
    }

    /**
     * Override the WC settings save flow to encrypt the PEM before it
     * hits {@code wp_options}.
     *
     * <p>WC's default {@code process_admin_options} would happily save
     * the PEM into the {@code woocommerce_linopay_settings} option
     * row, plaintext. We intercept the POST, encrypt the PEM with
     * {@see Crypto}, persist the ciphertext to a separate option
     * ({@see Settings::OPTION_PEM_ENCRYPTED}), and return the
     * cleaned settings array (without the PEM) to WC's update
     * machinery.</p>
     */
    public function process_admin_options(): bool
    {
        $crypto = $this->get_crypto();
        $cleaned = Settings::persist_posted_settings($_POST, $crypto);

        // WC's update_option() is the standard way to save the
        // settings array (it handles serialisation, autoload='yes',
        // and the cache invalidation).
        return update_option(self::OPTION_KEY, $cleaned);
    }

    /**
     * Define the settings form fields. WooCommerce calls this from the
     * parent constructor — do not invoke manually.
     *
     * <p>Field types follow the WooCommerce convention so the admin UI
     * renders them without any custom template work. The PEM field uses
     * a {@code textarea} (not {@code password}) so the merchant can see
     * what they pasted, but we never log or echo its contents.</p>
     */
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable / Disable', 'linopay-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable LinoPay', 'linopay-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'linopay-woocommerce'),
                'type'        => 'text',
                'description' => __('The payment method title customers see at checkout.', 'linopay-woocommerce'),
                'default'     => __('Bank-to-bank (LinoPay)', 'linopay-woocommerce'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'linopay-woocommerce'),
                'type'        => 'textarea',
                'description' => __('The payment method description customers see at checkout.', 'linopay-woocommerce'),
                'default'     => __('Pay securely via your bank. Authorisation happens at your bank; you\'ll be redirected back when complete.', 'linopay-woocommerce'),
                'desc_tip'    => true,
            ],
            'environment' => [
                'title'       => __('Environment', 'linopay-woocommerce'),
                'type'        => 'select',
                'description' => __('Sandbox uses the WireMock-compatible test sandbox; live uses the real LinoPay API.', 'linopay-woocommerce'),
                'default'     => 'sandbox',
                'options'     => [
                    'sandbox' => __('Sandbox', 'linopay-woocommerce'),
                    'live'    => __('Live', 'linopay-woocommerce'),
                ],
            ],
            'key_id' => [
                'title'       => __('Channel Key ID', 'linopay-woocommerce'),
                'type'        => 'text',
                'description' => __('The channel\'s Key ID (sent as X-Channel-KeyId on every API call). Starts with "kid_".', 'linopay-woocommerce'),
                'default'     => '',
            ],
            'bank_code' => [
                'title'       => __('Default bank code', 'linopay-woocommerce'),
                'type'        => 'select',
                'description' => __('The bank code pre-selected in the consent URL. Merchants can override per channel.', 'linopay-woocommerce'),
                'default'     => 'ANZ',
                'options'     => [
                    'ANZ'       => 'ANZ',
                    'ASB'       => 'ASB',
                    'BNZ'       => 'BNZ',
                    'Kiwibank'  => 'Kiwibank',
                    'Westpac'   => 'Westpac',
                ],
            ],
            'pem' => [
                'title'       => __('Channel private key (PEM)', 'linopay-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Paste the channel\'s RSA private key in PEM format. Encrypted with the WP auth salt before storage; never logged.', 'linopay-woocommerce'),
                'default'     => '',
            ],
        ];
    }

    /**
     * Handle a checkout submission: create the LinoPay payment and
     * redirect the customer to the bank consent URL.
     *
     * <p>WooCommerce calls this method when the customer clicks "Place
     * order" with LinoPay selected. We:</p>
     * <ol>
     *   <li>Read the order total in cents and call the SDK's
     *       {@code payments->create()}.</li>
     *   <li>Persist the saga ID and consent URL on the order so we can
     *       correlate the incoming webhook and (optionally) re-send
     *       the consent link from the admin UI.</li>
     *   <li>Mark the order "on hold" (the canonical WC state for
     *       "awaiting payment confirmation"). The webhook receiver will
     *       call {@code $order->payment_complete()} when the bank
     *       authorises.</li>
     *   <li>Return {@code ['result' => 'success', 'redirect' => $consentUrl]}.
     *       WC will redirect the browser to the consent URL; the
     *       customer authorises at their bank and the bank redirects
     *       back to our "thank you" page; the webhook arrives in
     *       parallel.</li>
     * </ol>
     *
     * @param int $order_id The WooCommerce order ID (post ID of the
     *                       {@code shop_order} post type).
     * @return array{result: string, redirect?: string, messages?: string}
     *               WC's expected return shape.
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            return [
                'result'   => 'failure',
                'messages' => __('Order not found.', 'linopay-woocommerce'),
            ];
        }

        try {
            $sdk = $this->get_sdk();
            $totalCents = (int) round((float) $order->get_total() * 100);
            $currency = $order->get_currency();

            $payment = $sdk->payments->create(
                new \Linotech\Sdk\CreatePaymentInput(
                    amountCents: $totalCents,
                    currency: $currency,
                    targetBank: $this->get_option('bank_code') ?: null,
                ),
            );

            // Persist the saga + consent URL on the order so the
            // webhook can look them up. The underscores mark these as
            // internal (not visible in the standard order meta box).
            $order->update_meta_data(self::META_SAGA_ID, $payment->sagaId);
            $order->update_meta_data(self::META_CONSENT_URL, $payment->consentUrl);
            $order->set_status('on-hold', __('Awaiting bank authorisation.', 'linopay-woocommerce'));
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $payment->consentUrl,
            ];
        } catch (\Linotech\Sdk\LinopayApiException $e) {
            // The SDK sanitises error messages; the upstream code is
            // already in the exception. Don't log the message itself —
            // it could include the key ID, which is sensitive at the
            // customer-identification boundary.
            $order->add_order_note(
                sprintf(
                    /* translators: %s: sanitised upstream error code. */
                    __('LinoPay payment creation failed: %s', 'linopay-woocommerce'),
                    $e->upstreamCode() ?? 'unknown'
                )
            );
            return [
                'result'   => 'failure',
                'messages' => __('Payment could not be created. Please try again or use a different payment method.', 'linopay-woocommerce'),
            ];
        } catch (\Linotech\Sdk\LinopayConfigException $e) {
            // Configuration error (missing key ID, empty PEM, etc.).
            // Surface to the merchant in the order note AND log to the
            // WP debug log. The PEM is never in the message.
            $order->add_order_note(
                sprintf(
                    /* translators: %s: sanitised configuration error message (no PEM). */
                    __('LinoPay configuration error: %s', 'linopay-woocommerce'),
                    $e->getMessage()
                )
            );
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[linopay-woocommerce] Config error: ' . $e->getMessage());
            }
            return [
                'result'   => 'failure',
                'messages' => __('LinoPay is not configured correctly. Please contact the store owner.', 'linopay-woocommerce'),
            ];
        }
    }

    /**
     * Webhook receiver. Bound to {@code woocommerce_api_lino_pay_webhook}
     * in the constructor. WC's WC-API dispatcher routes any
     * {@code /?wc-api=lino_pay_webhook} request here.
     *
     * <p>Delegates entirely to {@see WebhookHandler}. This thin wrapper
     * exists so the WC-API endpoint name stays discoverable from the
     * gateway's identity (admin devs reading the gateway class know
     * where to look for the webhook URL).</p>
     */
    public function handle_webhook(): void
    {
        $handler = $this->get_webhook_handler();
        $handler->handle();
    }

    /**
     * Build (or return the cached) LinoPay SDK client.
     *
     * <p>Constructed lazily so the constructor stays cheap (WC calls it
     * on every checkout page load, including ones that never reach
     * {@see process_payment}). The SDK client is stateless except for
     * the cached scoped-token cache; recreating it on every request
     * just forces a token re-exchange on the first invoice call.</p>
     */
    private function get_sdk(): \Linotech\Sdk\Linopay
    {
        if ($this->sdk !== null) {
            return $this->sdk;
        }

        $keyId = (string) $this->get_option('key_id');
        $pem = $this->get_decrypted_pem();
        $environment = (string) ($this->get_option('environment') ?: 'sandbox');
        $bankCode = (string) ($this->get_option('bank_code') ?: 'ANZ');

        $config = new \Linotech\Sdk\Config(
            baseUrl: $this->get_base_url($environment),
            keyId: $keyId,
            privateKeyPem: $pem,
            environment: $environment,
            bankCode: $bankCode,
            logger: new WPLogger(),
        );

        $this->sdk = \Linotech\Sdk\Linopay::create($config);
        return $this->sdk;
    }

    /**
     * Resolve the LinoPay API base URL from the configured environment.
     * The sandbox URL is the WireMock-compatible test sandbox; the live
     * URL is the published LinoPay merchant API. Override via filter
     * (used by the containerised smoke test).
     */
    private function get_base_url(string $environment): string
    {
        if ($environment === 'sandbox') {
            return (string) apply_filters('linopay_woocommerce_sandbox_base_url', 'https://sandbox.linopay.example');
        }
        return (string) apply_filters('linopay_woocommerce_live_base_url', 'https://api.linopay.example');
    }

    /**
     * Read the encrypted PEM from {@code wp_options} and decrypt it
     * with {@see Crypto}. Returns the empty string if no PEM is stored
     * — the SDK will throw {@see \Linotech\Sdk\LinopayConfigException}
     * on first use, which {@see process_payment} catches and surfaces
     * as a merchant-visible "not configured" error.
     */
    private function get_decrypted_pem(): string
    {
        $stored = (string) get_option(self::OPTION_KEY . '_pem_encrypted', '');
        if ($stored === '') {
            return '';
        }
        return $this->get_crypto()->decrypt($stored);
    }

    /**
     * Build (or return the cached) {@see Crypto}. The auth salt is
     * taken from {@code wp_salt('auth')} — which returns a per-install
     * random string from {@code wp-config.php}. If WP isn't loaded yet
     * (e.g. during a unit test), we fall back to a placeholder so the
     * Crypto can still be constructed for inspection but {@see decrypt}
     * will fail with a clear error.
     */
    private function get_crypto(): Crypto
    {
        if ($this->crypto !== null) {
            return $this->crypto;
        }
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'unit-test-salt';
        $this->crypto = new Crypto($salt);
        return $this->crypto;
    }

    /**
     * Build (or return the cached) {@see WebhookHandler}.
     */
    private function get_webhook_handler(): WebhookHandler
    {
        if ($this->webhookHandler !== null) {
            return $this->webhookHandler;
        }
        $this->webhookHandler = new WebhookHandler($this->get_crypto());
        return $this->webhookHandler;
    }
}
