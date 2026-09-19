<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Channel-key lifecycle helper.
 *
 * Reads the channel's keys list and surfaces the active row as a
 * clean shape: `activeKeyId`, `expiresAt`, `daysRemaining`,
 * `effectiveStatus`, `fingerprint`. The days-remaining math is on
 * purpose done in the SDK — the server returns the raw `expiresAt`,
 * and a merchant's own monitoring should be able to alert before a
 * key dies, not after.
 */
final class ChannelsClient
{
    public function __construct(
        private readonly Config $_config,
        private readonly HttpClient $http,
        private readonly ChannelTokenCache $tokens,
    ) {
    }

    public function keyStatus(): ChannelKeyStatus
    {
        $token = $this->tokens->exchange(['invoices:read']);

        $response = $this->http->callJson(
            "/api/merchants/" . rawurlencode($token->merchantId)
            . "/channels/" . rawurlencode($token->channelId) . '/keys',
            new CallOptions(method: 'GET', token: $token->accessToken),
        );

        if (!is_array($response)) {
            throw new LinopayApiException('Channels.keyStatus returned an unexpected response.', 500);
        }

        // Find the active row. Prefer `effectiveStatus === 'Active'`
        // (the field the FE renders against), then fall back to
        // `status === 'ACTIVE' && effectiveStatus === 'Upcoming'`.
        $active = null;
        foreach ($response as $v) {
            if (is_array($v) && ($v['effectiveStatus'] ?? null) === 'Active') {
                $active = $v;
                break;
            }
        }
        if ($active === null) {
            foreach ($response as $v) {
                if (is_array($v)
                    && ($v['status'] ?? null) === 'ACTIVE'
                    && ($v['effectiveStatus'] ?? null) === 'Upcoming'
                ) {
                    $active = $v;
                    break;
                }
            }
        }

        $allVersions = [];
        foreach ($response as $v) {
            if (is_array($v)) {
                $allVersions[] = ChannelKeyVersion::fromResponse($v);
            }
        }

        if ($active === null) {
            return new ChannelKeyStatus(
                activeKeyId: '',
                expiresAt: null,
                daysRemaining: null,
                effectiveStatus: 'None',
                fingerprint: null,
                allVersions: $allVersions,
            );
        }

        $expiresAt = isset($active['expiresAt']) ? (string) $active['expiresAt'] : null;
        $daysRemaining = null;
        if ($expiresAt !== null) {
            try {
                $expires = new \DateTimeImmutable($expiresAt);
                $now = new \DateTimeImmutable('@' . time());
                $diffSeconds = $expires->getTimestamp() - $now->getTimestamp();
                $daysRemaining = (int) ceil($diffSeconds / 86400);
            } catch (\Exception) {
                $daysRemaining = null;
            }
        }

        return new ChannelKeyStatus(
            activeKeyId: (string) ($active['keyId'] ?? ''),
            expiresAt: $expiresAt,
            daysRemaining: $daysRemaining,
            effectiveStatus: (string) ($active['effectiveStatus'] ?? ''),
            fingerprint: isset($active['fingerprint']) ? (string) $active['fingerprint'] : null,
            allVersions: $allVersions,
        );
    }
}

final class ChannelKeyStatus
{
    /**
     * @param ChannelKeyVersion[] $allVersions
     */
    public function __construct(
        public readonly string $activeKeyId,
        public readonly ?string $expiresAt,
        public readonly ?int $daysRemaining,
        public readonly string $effectiveStatus,
        public readonly ?string $fingerprint,
        public readonly array $allVersions,
    ) {
    }
}

final class ChannelKeyVersion
{
    public function __construct(
        public readonly int $id,
        public readonly string $keyId,
        public readonly string $status,
        public readonly string $activatedAt,
        public readonly ?string $expiresAt,
        public readonly bool $isPassphraseProtected,
        public readonly ?string $fingerprint,
        public readonly string $effectiveStatus,
    ) {
    }

    /**
     * @param array<string,mixed> $r
     */
    public static function fromResponse(array $r): self
    {
        return new self(
            id: (int) ($r['id'] ?? 0),
            keyId: (string) ($r['keyId'] ?? ''),
            status: (string) ($r['status'] ?? ''),
            activatedAt: (string) ($r['activatedAt'] ?? ''),
            expiresAt: isset($r['expiresAt']) ? (string) $r['expiresAt'] : null,
            isPassphraseProtected: (bool) ($r['isPassphraseProtected'] ?? false),
            fingerprint: isset($r['fingerprint']) ? (string) $r['fingerprint'] : null,
            effectiveStatus: (string) ($r['effectiveStatus'] ?? ''),
        );
    }
}
