<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Linotech\Sdk\Config;
use Linotech\Sdk\HttpClient;
use Linotech\Sdk\LinopayApiException;
use Linotech\Sdk\LinopayConfigException;
use PHPUnit\Framework\TestCase;
use Linotech\Sdk\Tests\Helpers\TestPemStub;

final class HttpTest extends TestCase
{
    /**
     * @param array<int,Response> $responses
     * @return array{Guzzle, list<array{0: \Psr\Http\Message\RequestInterface, 1: array}>}
     *         Returns a Guzzle client wired to a MockHandler plus a tap on the
     *         outgoing-requests list so each test can assert on what was sent.
     */
    private function makeClient(array $responses): array
    {
        $mock = new MockHandler($responses);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        // http_errors=false: the SDK does its own error translation,
        // and we don't want a 4xx to be thrown as a GuzzleException
        // before we can reach it.
        return [new Guzzle(['handler' => $stack, 'http_errors' => false]), $history];
    }

    public function testSetsAuthorizationAndChannelKeyIdHeaders(): void
    {
        $captured = null;
        $requestCapture = function ($request) use (&$captured) {
            $captured = $request;
            return new Response(200, ['Content-Type' => 'application/json'], '{"data":{"x":1}}');
        };
        $mock = new MockHandler([$requestCapture]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new Guzzle(['handler' => $stack]);

        $http = new HttpClient(
            new Config(
                baseUrl: 'http://example.test',
                keyId: 'kid_http',
                privateKeyPem: TestPemStub::pem(),
                environment: 'sandbox',
            ),
            $guzzle,
        );

        $http->callJson('/v1/test', new \Linotech\Sdk\CallOptions(method: 'GET', token: 'my-test-token'));

        self::assertNotNull($captured);
        self::assertSame('Bearer my-test-token', $captured->getHeaderLine('Authorization'));
        self::assertSame('kid_http', $captured->getHeaderLine('X-Channel-KeyId'));
    }

    public function testUnwrapsDataEnvelope(): void
    {
        [$guzzle] = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'],
                json_encode(['data' => ['x' => 1, 'y' => 2]], JSON_THROW_ON_ERROR)),
        ]);
        $http = $this->makeHttp($guzzle);
        $result = $http->callJson('/v1/test', new \Linotech\Sdk\CallOptions(token: 't'));
        self::assertSame(['x' => 1, 'y' => 2], $result);
    }

    public function testReturnsNonEnvelopedResponseAsIs(): void
    {
        [$guzzle] = $this->makeClient([
            new Response(200, ['Content-Type' => 'application/json'],
                json_encode(['transactionSagaId' => 'abc', 'status' => 'Pending'], JSON_THROW_ON_ERROR)),
        ]);
        $http = $this->makeHttp($guzzle);
        $result = $http->callJson('/v1/payments/qrcode', new \Linotech\Sdk\CallOptions(token: 't'));
        self::assertSame('abc', $result['transactionSagaId']);
        self::assertSame('Pending', $result['status']);
    }

    public function testThrowsLinopayApiExceptionOnNon2xxWithSanitisedCode(): void
    {
        [$guzzle] = $this->makeClient([
            new Response(404, ['Content-Type' => 'application/json'],
                json_encode(['code' => 'CHANNEL_NOT_FOUND', 'message' => 'Channel not found.'], JSON_THROW_ON_ERROR)),
        ]);
        $http = $this->makeHttp($guzzle);
        $caught = null;
        try {
            $http->callJson('/v1/test', new \Linotech\Sdk\CallOptions(token: 't'));
        } catch (LinopayApiException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(LinopayApiException::class, $caught);
        self::assertSame(404, $caught->status);
        self::assertSame('CHANNEL_NOT_FOUND', $caught->upstreamCode());
        self::assertSame('Channel not found.', $caught->getMessage());
    }

    public function testThrowsLinopayApiExceptionOnNon2xxWithRfc7807Detail(): void
    {
        [$guzzle] = $this->makeClient([
            new Response(404, ['Content-Type' => 'application/json'],
                json_encode(['type' => 'about:blank', 'title' => 'Not Found', 'detail' => 'Wrong channel id.'], JSON_THROW_ON_ERROR)),
        ]);
        $http = $this->makeHttp($guzzle);
        $caught = null;
        try {
            $http->callJson('/v1/test', new \Linotech\Sdk\CallOptions(token: 't'));
        } catch (LinopayApiException $e) {
            $caught = $e;
        }
        self::assertSame(404, $caught->status);
        self::assertSame('about:blank', $caught->upstreamCode());
        // HTTP wrapper prefers `detail` over `title`. RFC 7807's `detail`
        // is the human-readable explanation; `title` is the summary type.
        self::assertSame('Wrong channel id.', $caught->getMessage());
    }

    public function testRefusesToSendWithoutKeyId(): void
    {
        // Refusal happens at Config construction (fail-fast): an empty
        // KeyId never reaches HttpClient. The SDK guarantees no API call
        // can be initiated without a configured key id — this assertion
        // pins that guarantee at the boundary where it lives.
        $this->expectException(LinopayConfigException::class);
        new Config(
            baseUrl: 'http://x',
            keyId: '',
            privateKeyPem: 'fake',
            environment: 'sandbox',
        );
    }

    private function makeHttp(\GuzzleHttp\ClientInterface $guzzle): HttpClient
    {
        return new HttpClient(
            new Config(
                baseUrl: 'http://example.test',
                keyId: 'kid_http',
                privateKeyPem: 'fake-pem-not-used',
                environment: 'sandbox',
            ),
            $guzzle,
        );
    }
}
