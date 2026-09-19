<?php

declare(strict_types=1);

namespace Linotech\Sdk;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Channel-side request signing.
 *
 * Mirrors the issuer/audience/lifetime set by
 * `lime-payments/.../ChannelAccessToken.cs` upstream. Issuer and
 * audience are constants on purpose: moving them onto Config would
 * let a caller silently send tokens the server rejects.
 */
final class ChannelJwtSigner
{
    public const ISSUER = 'linotech-pay';
    public const AUDIENCE = 'linotech-pay-api';
    private const LIFETIME_SECONDS = 300;

    /**
     * Signs a payload with the channel's private key and returns the
     * compact-serialised JWT. Throws `LinopayConfigException` if
     * either the Key ID or the PEM is empty.
     *
     * @param array<string,mixed> $claims
     */
    public static function sign(string $privateKeyPem, string $keyId, array $claims): string
    {
        if ($keyId === '') {
            throw new LinopayConfigException('Channel Key ID is required to sign a request.');
        }
        if ($privateKeyPem === '') {
            throw new LinopayConfigException('Channel private key is required to sign a request.');
        }

        // Always pin channelKeyId to the configured one — never trust
        // the caller to set it correctly.
        $payload = $claims;
        $payload['channelKeyId'] = $keyId;

        $now = time();
        $payload['iat'] = $now;
        $payload['nbf'] = $now;
        $payload['exp'] = $now + self::LIFETIME_SECONDS;
        // Pin iss/aud on every token. firebase/php-jwt encodes whatever's in
        // the payload verbatim, so without these, the upstream verifier
        // (which compares against ISS = 'linotech-pay' and AUD =
        // 'linotech-pay-api') rejects the token.
        $payload['iss'] = self::ISSUER;
        $payload['aud'] = self::AUDIENCE;

        return JWT::encode($payload, $privateKeyPem, 'RS256', $keyId, [
            'typ' => 'JWT',
        ]);
    }
}
