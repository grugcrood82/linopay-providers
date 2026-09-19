<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Webhook signature scheme — HMAC-SHA256, version-prefixed.
 *
 * Mirror implementation of LinoPay's outbound-webhook signer
 * (C#: `lime-payments/.../Abstractions/WebhookSignature.cs`).
 *
 * Construct the signed payload `${timestamp}.${rawBody}` (the
 * timestamp is signed AS WELL AS sent — changing either
 * invalidates the signature, which kills replay attacks).
 * Recompute HMAC-SHA256(secret, payload) and compare the hex digest
 * to the `v1=...` value in `X-LinoPay-Signature` in constant time
 * (PHP's native `hash_equals` does this).
 */
final class WebhookSignature
{
    public const SIGNATURE_HEADER = 'X-LinoPay-Signature';
    public const TIMESTAMP_HEADER = 'X-LinoPay-Timestamp';
    public const VERSION = 'v1';
    public const REPLAY_WINDOW_SECONDS = 300;

    public static function compute(string $secret, int $unixSeconds, string $body): string
    {
        if ($secret === '') {
            throw new LinopayWebhookVerificationException(
                LinopayWebhookVerificationException::REASON_WRONG_SECRET,
                'A non-empty signing secret is required to compute a webhook signature.',
            );
        }
        $payload = $unixSeconds . '.' . $body;
        $mac = hash_hmac('sha256', $payload, $secret);
        return self::VERSION . '=' . $mac;
    }

    /**
     * Verifies a candidate signature. Throws
     * `LinopayWebhookVerificationException` on any mismatch.
     */
    public static function verify(string $secret, int $unixSeconds, string $body, ?string $candidate, ?int $nowSeconds = null): void
    {
        if ($candidate === null || trim($candidate) === '') {
            throw new LinopayWebhookVerificationException(
                LinopayWebhookVerificationException::REASON_NO_SIGNATURE,
                'No signature header was supplied.',
            );
        }
        if ($secret === '') {
            throw new LinopayWebhookVerificationException(
                LinopayWebhookVerificationException::REASON_WRONG_SECRET,
                'A non-empty signing secret is required to verify a webhook.',
            );
        }

        // Signature check FIRST, then replay-window check. A merchant
        // who forges a signature must fail with `wrong-secret`,
        // regardless of the timestamp freshness — the replay check
        // is a defence against an attacker replaying a *valid*
        // signature, not against forging one.
        $expected = self::compute($secret, $unixSeconds, $body);

        // Constant-time compare. PHP's `hash_equals` returns false
        // on length mismatch but still consumes constant time.
        if (!hash_equals($expected, $candidate)) {
            throw new LinopayWebhookVerificationException(
                LinopayWebhookVerificationException::REASON_WRONG_SECRET,
                'Webhook signature did not match.',
            );
        }

        $now = $nowSeconds ?? time();
        if (abs($now - $unixSeconds) > self::REPLAY_WINDOW_SECONDS) {
            throw new LinopayWebhookVerificationException(
                LinopayWebhookVerificationException::REASON_REPLAY_OUT_OF_WINDOW,
                "Timestamp {$unixSeconds} is outside the " . self::REPLAY_WINDOW_SECONDS . "-second replay window (now={$now})."
            );
        }
    }

    /**
     * Convenience signature to mirror Phase 1's record-style verify
     * payload.
     */
    public static function verifyArgs(string $secret, int $unixSeconds, string $body, ?string $candidate, ?int $nowSeconds = null): void
    {
        self::verify($secret, $unixSeconds, $body, $candidate, $nowSeconds);
    }
}
