<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * SDK configuration. Construct one per-request and pass it to
 * {@see Linopay::create()}.
 *
 * Sandbox vs. live must be explicit (SOW §2.2). The SDK never infers
 * it from the shape of the key or from an ambient env var the caller
 * didn't set themselves.
 */
final class Config
{
    public function __construct(
        /** Base URL for the LinoPay merchant API. WireMock sandbox: `http://localhost:8080`. Production: published LinoPay host. */
        public readonly string $baseUrl,

        /** Channel Key ID. Sent as `X-Channel-KeyId` on every request and used as the `kid` header on the channel-signed instruction JWT. */
        public readonly string $keyId,

        /** The channel's RSA private key in PEM form. Never logged, never written to disk, never returned through an error message (SOW §2.1). */
        public readonly string $privateKeyPem,

        /** `sandbox` or `live`. */
        public readonly string $environment,

        /** Default bank code (e.g. `ANZ`). Optional. */
        public readonly ?string $bankCode = null,

        /** Optional Logger (PSR-3). If omitted, the SDK writes nothing. */
        public readonly ?LoggerInterface $logger = null,
    ) {
        if ($baseUrl === '') {
            throw new LinopayConfigException('BaseUrl is required.');
        }
        if ($keyId === '') {
            throw new LinopayConfigException('KeyId is required.');
        }
        if ($privateKeyPem === '') {
            throw new LinopayConfigException('PrivateKeyPem is required.');
        }
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            throw new LinopayConfigException(
                "Environment must be 'sandbox' or 'live' (got '{$environment}')."
            );
        }
    }
}

/**
 * Optional PSR-3-style logger. We don't depend on psr/log so this
 * stays minimal — the SDK never calls into a non-null logger by
 * default.
 */
interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
    public function warn(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}
