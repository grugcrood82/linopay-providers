<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests\Helpers;

/**
 * Tiny stub that returns a never-actually-parsed PEM. The HTTP
 * tests stub the Guzzle layer, so the PEM never reaches the JWT
 * signer — it just needs to be non-empty.
 */
final class TestPemStub
{
    public static function pem(): string
    {
        return '-----BEGIN PRIVATE KEY-----\nnot really a key\n-----END PRIVATE KEY-----';
    }
}
