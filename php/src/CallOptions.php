<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Per-call options for {@see HttpClient::callJson()}.
 */
final class CallOptions
{
    public function __construct(
        public readonly ?string $method = null,
        public readonly mixed $body = null,
        public readonly ?string $token = null,
        public readonly ?string $keyIdOverride = null,
    ) {
    }
}
