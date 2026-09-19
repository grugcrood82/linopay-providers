<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * WordPress-flavoured logger that satisfies the SDK's
 * {@see \Linotech\Sdk\LoggerInterface}.
 *
 * <p>The SDK accepts an optional PSR-3-style logger. We don't pull
 * in {@code psr/log} as a dependency; instead we provide a minimal
 * implementation that:</p>
 * <ul>
 *   <li>Writes to the WordPress debug log ({@code error_log()}) only
 *       when {@code WP_DEBUG} and {@code WP_DEBUG_LOG} are both true.
 *       Otherwise it's a no-op.</li>
 *   <li>Redacts known-sensitive keys ({@code pem}, {@code secret},
 *       {@code signature}, {@code token}) from the context array
 *       before logging. The message itself is taken as-is from the
 *       SDK — the SDK promises no secrets in any log line (see
 *       {@code NoSecretLeakTest}).</li>
 *   <li>Prefixes every line with a stable tag ({@code [linopay-woocommerce]})
 *       so log entries are greppable.</li>
 * </ul>
 *
 * <p><strong>Why not psr/log?</strong> it's a 30-file dependency for
 * three method signatures we already declare inline. The SDK
 * deliberately avoids the dependency; the plugin follows suit.</p>
 */
final class WPLogger implements \Linotech\Sdk\LoggerInterface
{
    /**
     * Keys whose values must NEVER appear in log output, regardless of
     * what the SDK passes us. The SDK itself promises not to log these
     * (NoSecretLeakTest pins it), but defence in depth.
     */
    private const REDACT_KEYS = [
        'pem', 'private_key_pem', 'privatekeypem',
        'secret', 'webhook_secret', 'webhooksecret', 'hmac_secret',
        'signature', 'x-linopay-signature',
        'token', 'access_token', 'authorization', 'bearer',
        'key_id', 'keyid',
    ];

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Emit the log line. {@code $message} is taken as-is from the
     * SDK; the SDK promises no secret material in the message string
     * (see {@code NoSecretLeakTest} in the linotech/sdk package).
     * We additionally redact any {@code $context} key whose name
     * looks like a credential.
     */
    private function log(string $level, string $message, array $context): void
    {
        if (! $this->logging_enabled()) {
            return;
        }

        $safeContext = $this->redact($context);
        $contextString = $safeContext === [] ? '' : ' ' . wp_json_encode($safeContext);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('[linopay-woocommerce] ' . $level . ' ' . $message . $contextString);
    }

    private function logging_enabled(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG
            && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
    }

    /**
     * Walk the context array and replace values for any redactable key
     * with the literal {@code '[redacted]'}. We match on the FULL key
     * name AND on common substrings (case-insensitive) so e.g.
     * {@code webhook_signature} is caught even though the canonical
     * key in {@see self::REDACT_KEYS} is just {@code signature}.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && $this->looks_like_secret($key)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function looks_like_secret(string $key): bool
    {
        $needle = strtolower($key);
        foreach (self::REDACT_KEYS as $candidate) {
            if (str_contains($needle, strtolower($candidate))) {
                return true;
            }
        }
        return false;
    }
}
