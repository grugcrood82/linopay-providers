<?php

declare(strict_types=1);

namespace Linopay\WooCommerce;

/**
 * Symmetric encryption for channel private keys stored in `wp_options`.
 *
 * <p>The LinoPay channel's RSA private key is a long-lived secret. Storing
 * it as a plain option row would expose it to any DB dump, log scrape,
 * or compromised admin account that has `wp_options` read access.
 * {@see Crypto} encrypts the key with AES-256-GCM using a key derived
 * from WordPress's `auth` salt ({@code wp_salt('auth')}), which is set
 * per-install in `wp-config.php` and never leaves the server.</p>
 *
 * <p><strong>Threat model:</strong> protects against a database-only breach
 * (e.g. a leaked backup or a read-only SQL injection). It does <em>not</em>
 * protect against a full server compromise where an attacker can read
 * `wp-config.php` — for that, you need a KMS or HSM, which is out of scope
 * for a WooCommerce plugin.</p>
 *
 * <p><strong>Storage format:</strong> a single base64 string holding
 * {@code IV (12 bytes) || ciphertext || GCM tag (16 bytes)}. The
 * version byte prefix {@code 0x01} is reserved so we can bump the
 * format later without a flag day.</p>
 */
final class Crypto
{
    /**
     * Format version byte. Increment if the ciphertext layout changes;
     * the decrypt path MUST stay backward-compatible for at least one
     * major version of the plugin.
     */
    private const VERSION = "\x01";

    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;       // GCM standard
    private const TAG_LENGTH = 16;      // GCM standard

    /**
     * @param string $authSalt The auth salt. Production code passes
     *                          {@code wp_salt('auth')} (WordPress's per-install
     *                          random salt from {@code wp-config.php}). Tests pass
     *                          a fixed value so the test vector is reproducible.
     */
    public function __construct(
        private readonly string $authSalt,
    ) {
        if ($this->authSalt === '') {
            throw new \InvalidArgumentException('authSalt is required to derive an encryption key.');
        }
    }

    /**
     * Encrypt a plaintext (typically a PEM-encoded private key) and return
     * the storage-ready base64 blob.
     *
     * <p>The output is deterministic-enough-for-debugging (same input + same
     * key = different output, because the IV is random) but never leaks the
     * plaintext length beyond the ciphertext's natural length.</p>
     *
     * @param string $plaintext PEM-encoded private key (or any other secret string).
     * @return string base64(VERSION || IV || CIPHERTEXT || TAG), suitable for
     *                  {@code update_option()} or any other opaque storage.
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('Refusing to encrypt an empty string.');
        }

        $iv = random_bytes(self::IV_LENGTH);
        $key = $this->deriveKey();
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',           // no aad
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException(
                'Failed to encrypt channel secret. The PHP openssl extension may be misconfigured.'
            );
        }

        return base64_encode(self::VERSION . $iv . $ciphertext . $tag);
    }

    /**
     * Decrypt a base64 blob produced by {@see encrypt}.
     *
     * @param string $stored base64(VERSION || IV || CIPHERTEXT || TAG).
     * @return string The original plaintext.
     * @throws \RuntimeException If the blob is malformed, the version byte
     *                           is unrecognised, or the GCM tag fails to
     *                           authenticate (tampering or wrong key).
     */
    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            throw new \InvalidArgumentException('Stored value is empty.');
        }
        $raw = base64_decode($stored, strict: true);
        if ($raw === false) {
            throw new \RuntimeException('Stored value is not valid base64.');
        }
        if (\strlen($raw) < 1 + self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Stored value is too short to contain a valid envelope.');
        }
        if ($raw[0] !== self::VERSION) {
            throw new \RuntimeException(
                "Unknown envelope version: 0x" . bin2hex($raw[0])
                . '. The plugin and the stored value may be out of sync.'
            );
        }

        $iv = substr($raw, 1, self::IV_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr($raw, 1 + self::IV_LENGTH, 0 - self::TAG_LENGTH);

        $key = $this->deriveKey();
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            // The most common cause here is the auth salt changing (wp-config.php
            // was regenerated, or this DB row was copied to a different install).
            // Don't leak which.
            throw new \RuntimeException('Failed to decrypt channel secret. The stored value may be corrupted or the encryption key may have changed.');
        }

        return $plaintext;
    }

    /**
     * Derive the 32-byte AES key from the auth salt. SHA-256 is in
     * ext-openssl so we don't need ext-hash (the SHA-256 in ext-hash is
     * faster but a redundant dependency for our use).
     */
    private function deriveKey(): string
    {
        $key = openssl_digest($this->authSalt, 'sha256', true);
        if ($key === false) {
            throw new \RuntimeException('Failed to derive encryption key from auth salt.');
        }
        // 32 bytes = AES-256. SHA-256 always returns 32 bytes, so no truncation.
        return $key;
    }
}
