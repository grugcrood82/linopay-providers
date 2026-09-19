<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Linotech\Sdk\ChannelTokenCache;
use Linotech\Sdk\Config;
use Linotech\Sdk\HttpClient;
use Linotech\Sdk\LinopayApiException;
use PHPUnit\Framework\TestCase;
use Linotech\Sdk\Tests\Helpers\TestPem;

final class TokensTest extends TestCase
{
    public function testCachesATokenWithinTheExpiryGuardWindow(): void
    {
        $pem = TestPem::generate();
        // One canned response — both `exchange` calls return the same
        // token (we verify it's served twice as a structural proxy for
        // caching: the second call would have produced a fresh one if
        // the cache did not kick in).
        $responseBody = json_encode([
            'data' => [
                'accessToken' => 'tok_1',
                'expiresIn' => 300,
                'merchantId' => 'm',
                'channelId' => 'c',
                'scope' => ['invoices:write'],
            ],
        ], JSON_THROW_ON_ERROR);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
            new Response(200, ['Content-Type' => 'application/json'], $responseBody),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new HttpClient(
            new Config(
                baseUrl: 'http://x',
                keyId: 'kid',
                privateKeyPem: $pem,
                environment: 'sandbox',
            ),
            new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]),
        );

        $cache = new ChannelTokenCache(
            new Config(
                baseUrl: 'http://x',
                keyId: 'kid',
                privateKeyPem: $pem,
                environment: 'sandbox',
            ),
            $http,
        );
        $a = $cache->exchange(['invoices:write']);
        $b = $cache->exchange(['invoices:write']);
        $c = $cache->exchange(['invoices:write']);
        self::assertSame('tok_1', $a->accessToken);
        self::assertFalse($a->fromCache);
        self::assertTrue($b->fromCache);
        self::assertTrue($c->fromCache);
    }

    public function testRejectsUnsupportedScopes(): void
    {
        $pem = TestPem::generate();
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => []])),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new HttpClient(
            new Config(baseUrl: 'http://x', keyId: 'kid', privateKeyPem: $pem, environment: 'sandbox'),
            new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]),
        );
        $cache = new ChannelTokenCache(
            new Config(baseUrl: 'http://x', keyId: 'kid', privateKeyPem: $pem, environment: 'sandbox'),
            $http,
        );

        $this->expectException(LinopayApiException::class);
        $cache->exchange(['payments:write']);
    }
}
