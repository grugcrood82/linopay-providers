<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * Settings persistence helper for the LinoPay gateway.
 *
 * <p>WooCommerce's {@see \WC_Payment_Gateway::process_admin_options()}
 * persists every field in {@code form_fields} as one big option row.
 * That's fine for primitive fields like the bank code and the key ID,
 * but the PEM must be encrypted at rest — storing it in the same
 * option row would leave it plaintext in {@code wp_options}.</p>
 *
 * <p>{@see Settings::persist_posted_settings()} handles the
 * post-submission transformation:</p>
 * <ol>
 *   <li>Pull the PEM out of the posted form.</li>
 *   <li>Encrypt it with {@see Crypto}, keyed off {@code wp_salt('auth')}.</li>
 *   <li>Store the ciphertext in a separate option row,
 *       {@code woocommerce_linopay_settings_pem_encrypted}, with
 *       {@code autoload='no'} so it doesn't load on every page
 *       request.</li>
 *   <li>Strip the plaintext PEM from the array that WC is about to
 *       save into {@code woocommerce_linopay_settings} (a string
 *       array of primitives that the gateway reads via
 *       {@code get_option()}).</li>
 * </ol>
 *
 * <p>The class is intentionally static — the gateway calls
 * {@see Settings::persist_posted_settings()} from
 * {@see Gateway::process_admin_options()}, no state.</p>
 */
final class Settings
{
    /**
     * Option key for the encrypted PEM blob. Lives next to
     * {@code woocommerce_linopay_settings} (the standard WC option
     * the gateway persists via {@see \WC_Payment_Gateway}) but
     * separately so the PEM never appears in the plaintext settings
     * array.
     */
    public const OPTION_PEM_ENCRYPTED = 'woocommerce_linopay_settings_pem_encrypted';

    /**
     * Process the POSTed admin-settings form for the LinoPay gateway.
     *
     * <p>Returns the cleaned settings array — the form-field values
     * for the WC-managed option row, with the plaintext PEM
     * stripped. The caller (typically
     * {@see Gateway::process_admin_options()}) then hands this array
     * to {@code WC_Payment_Gateway::update_option()} as usual.</p>
     *
     * <p>The encrypted PEM is persisted separately via
     * {@code update_option()} with {@code autoload='no'}.</p>
     *
     * @param array<string, mixed> $posted The full {@code $_POST} superglobal
     *                                       (or any nested array containing
     *                                       the form-field values under
     *                                       {@code woocommerce_linopay_<key>}).
     * @param Crypto $crypto The crypto helper, keyed off {@code wp_salt('auth')}.
     * @return array<string, mixed> Cleaned settings array suitable for the
     *                                WC-managed option row (no plaintext PEM).
     */
    public static function persist_posted_settings(array $posted, Crypto $crypto): array
    {
        // WooCommerce's process_admin_options() builds the cleaned array
        // by reading every key in $this->get_post_data() that's also in
        // $this->form_fields. We replicate that filter here so we don't
        // leak the PEM.
        //
        // Form fields map to POST keys prefixed with the gateway ID:
        //   form_fields['pem']      => $_POST['woocommerce_linopay_pem']
        //   form_fields['key_id']   => $_POST['woocommerce_linopay_key_id']
        //   ...

        $clean = [];
        $pemPlaintext = null;

        // Walk the posted array looking for fields prefixed with our
        // gateway id. We intentionally do this on the whole POST array
        // rather than on $this->form_fields because we need to access
        // the raw POST anyway to decide which key is the PEM.
        foreach ($posted as $rawKey => $rawValue) {
            if (! is_string($rawKey) || ! str_starts_with($rawKey, 'woocommerce_linopay_')) {
                continue;
            }
            // Strip the prefix; what's left is the form-field key.
            $fieldKey = substr($rawKey, strlen('woocommerce_linopay_'));
            // Skip the magic _nonce / _wp_http_referer fields that
            // WP sprinkles into the form.
            if ($fieldKey === '' || $fieldKey[0] === '_') {
                continue;
            }

            // Sanitise every value through WooCommerce's per-type
            // cleaner (this is what process_admin_options does
            // internally). For unknown field types we fall back to
            // sanitize_text_field so we don't store arbitrary HTML.
            $cleanValue = self::sanitize_field($fieldKey, $rawValue);

            if ($fieldKey === 'pem') {
                // Save the PEM plaintext for encryption below. Don't
                // add it to the $clean array — that's the plaintext row.
                $pemPlaintext = (string) $cleanValue;
                continue;
            }

            $clean[$fieldKey] = $cleanValue;
        }

        // Encrypt the PEM and persist it as a separate option with
        // autoload='no'. An empty PEM is fine — it just deletes the
        // existing encrypted row, which is the merchant's "reset" path.
        if ($pemPlaintext === '') {
            delete_option(self::OPTION_PEM_ENCRYPTED);
        } elseif ($pemPlaintext !== null) {
            $encrypted = $crypto->encrypt($pemPlaintext);
            update_option(self::OPTION_PEM_ENCRYPTED, $encrypted, autoload: false);
        }

        return $clean;
    }

    /**
     * Field-by-field sanitiser. Mirrors the WC_Payment_Gateway
     * behaviour so our settings array matches what WC's own
     * process_admin_options would have produced.
     *
     * @param mixed $value The raw POST value.
     */
    private static function sanitize_field(string $fieldKey, mixed $value): mixed
    {
        // The fields we care about; everything else passes through
        // sanitize_text_field as a safe default.
        return match ($fieldKey) {
            'enabled'     => ($value === '1' || $value === 'yes') ? 'yes' : 'no',
            'environment' => in_array($value, ['sandbox', 'live'], true) ? $value : 'sandbox',
            'bank_code'   => preg_replace('/[^A-Za-z0-9]/', '', (string) $value),
            'key_id'      => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value),
            'title', 'description' => sanitize_text_field((string) $value),
            'pem'         => (string) $value, // we handle this separately
            default       => sanitize_text_field((string) $value),
        };
    }
}
