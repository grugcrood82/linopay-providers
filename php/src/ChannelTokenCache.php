<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Scope-limited access token cache.
 *
 * Endpoints that act on already-issued invoices (Flexi-Payment
 * create/get/cancel) take a different token from the one that's used
 * to *issue* payments (the channel-signed JWT). The exchanged token
 * is HS256-signed by LinoPay itself, has its own audience, and is
 * short-lived (5 minutes). The cache here is deliberate: signing the
 * assertion JWT is cheap-ish but not free, and a sequence of invoice
 * operations only needs to do it once every 5 minutes. We
 * re-exchange 30 s before the real expiry so a call never starts
 * with a token that dies mid-flight.
 */
final class ChannelTokenCache
{
    /** Seconds of safety margin before the real expiry at which to refresh. */
    private const EXPIRY_GUARD_MS = 30_000;

    private ?ChannelAccessToken $cached = null;
    /** Lock-free single-threaded cache. PHP request lifecycle is one-process; no contention. */

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * Returns a still-valid scoped token, exchanging a fresh one if
     * needed. The returned object's `fromCache` boolean reports
     * whether a network round-trip happened.
     */
    public function exchange(array $scopes): ChannelAccessToken
    {
        $wanted = $this->canonicalise($scopes);

        if (
            $this->cached !== null
            && (($this->cached->expiresAtMs - time() * 1000) > self::EXPIRY_GUARD_MS)
            && $this->canonicalise($this->cached->scope) === $wanted
        ) {
            return new ChannelAccessToken(
                $this->cached->accessToken,
                $this->cached->merchantId,
                $this->cached->channelId,
                $this->cached->scope,
                $this->cached->expiresAtMs,
                fromCache: true,
            );
        }

        // Refuse non-invoice scopes. The exchange endpoint only honours
        // these scopes today (see ChannelAccessToken.AllowedScopes
        // upstream). Future scopes come with future endpoint support.
        $hasInvoice = false;
        foreach ($scopes as $s) {
            if ($s === 'invoices:write' || $s === 'invoices:read') {
                $hasInvoice = true;
                break;
            }
        }
        if (!$hasInvoice) {
            throw new LinopayApiException(
                "Channel-token exchange: requested scope '{$wanted}' is not supported by LinoPay.",
                400,
                'UNSUPPORTED_SCOPE',
            );
        }

        $assertion = ChannelJwtSigner::sign(
            $this->config->privateKeyPem,
            $this->config->keyId,
            ['channelKeyId' => $this->config->keyId],
        );

        $raw = $this->http->callJson(
            '/v1/auth/channel-token',
            new CallOptions(
                method: 'POST',
                token: $assertion,
                body: ['scope' => $scopes],
            ),
        );

        if (!is_array($raw) || !isset($raw['accessToken'])) {
            throw new LinopayApiException(
                'Channel-token exchange returned an unexpected response.',
                500,
            );
        }

        $token = new ChannelAccessToken(
            $raw['accessToken'],
            $raw['merchantId'] ?? '',
            $raw['channelId'] ?? '',
            is_array($raw['scope'] ?? null) ? $raw['scope'] : [],
            (time() + (int) ($raw['expiresIn'] ?? 0)) * 1000,
            fromCache: false,
        );
        $this->cached = $token;
        return $token;
    }

    private function canonicalise(array $scopes): string
    {
        $sorted = $scopes;
        sort($sorted);
        return implode(' ', $sorted);
    }
}

final class ChannelAccessToken
{
    /**
     * @param string[] $scope
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $merchantId,
        public readonly string $channelId,
        public readonly array $scope,
        public readonly int $expiresAtMs,
        public readonly bool $fromCache,
    ) {
    }
}
