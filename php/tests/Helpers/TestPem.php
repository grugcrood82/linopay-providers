<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests\Helpers;

/**
 * Generates a fresh RSA-2048 PKCS#8 PEM for use in tests. The matching
 * public half is implicit — the SDK only signs; verification happens
 * at integration boundaries, not in unit tests.
 */
final class TestPem
{
    public static function generate(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Failed to generate RSA key in test.');
        }
        openssl_pkey_export($resource, $pem);
        // PHP 8.0+ deprecates explicit openssl_pkey_free; the resource
        // is released when the function returns. Leaving the implicit
        // GC avoids the deprecation noise without changing semantics.
        return $pem;
    }
}
