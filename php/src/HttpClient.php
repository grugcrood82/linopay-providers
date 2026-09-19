<?php

declare(strict_types=1);

namespace Linotech\Sdk;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

/**
 * HTTP plumbing for the SDK. One place that sets the right headers,
 * unwraps the `{ data: ... }` envelope, and converts non-2xx
 * responses into a typed error.
 */
final class HttpClient
{
    public function __construct(
        private readonly Config $config,
        private readonly ?ClientInterface $guzzle = null,
    ) {
    }

    /**
     * Performs an HTTP call against `$path` (which is appended to the
     * configured baseUrl) and returns the unwrapped payload.
     *
     *  - 2xx: returns the response body, unwrapping `{ data: ... }`
     *    when present so callers get the inner value directly.
     *  - non-2xx: throws `LinopayApiException` with the upstream
     *    status and a sanitised message.
     *
     * @param CallOptions $options
     * @return array<string,mixed>|null
     */
    public function callJson(string $path, CallOptions $options): ?array
    {
        $url = rtrim($this->config->baseUrl, '/') . (str_starts_with($path, '/') ? $path : "/{$path}");
        $method = strtoupper($options->method ?? 'GET');

        $headers = [
            'Accept' => 'application/json',
        ];
        if ($options->token !== null) {
            $headers['Authorization'] = "Bearer {$options->token}";
        }
        if ($this->config->keyId === '') {
            throw new LinopayConfigException('Channel Key ID is required to make any API call.');
        }
        $headers['X-Channel-KeyId'] = $this->config->keyId;

        $body = null;
        if ($options->body !== null) {
            $body = json_encode($options->body, JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        // http_errors=false: a non-2xx response must reach our own
        // error-translation path as a normal response, not as a
        // GuzzleException. The native Guzzle catch in this method
        // is reserved for genuine transport failures (DNS, TLS, etc.).
        $client = $this->guzzle ?? new Client(['http_errors' => false]);
        $request = new Request($method, $url, $headers, $body);

        try {
            $response = $client->send($request);
        } catch (GuzzleException) {
            // Rethrow the underlying network/transport error.
            // LinopaySdkError would mask it as "our problem"; the
            // original message is more useful.
            throw $response ?? new LinopayApiException('No response.', status: 0);
        } catch (\Throwable $e) {
            throw $e;
        }

        $text = (string) $response->getBody();
        $parsed = null;
        if ($text !== '') {
            try {
                $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
                $parsed = is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                // Non-JSON response — leave parsed as null.
            }
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $code = null;
            $detail = "{$method} {$url} failed with {$status}";
            if (is_array($parsed)) {
                if (isset($parsed['code']) && is_string($parsed['code'])) {
                    $code = $parsed['code'];
                } elseif (isset($parsed['type']) && is_string($parsed['type'])) {
                    $code = $parsed['type'];
                }
                if (isset($parsed['detail']) && is_string($parsed['detail'])) {
                    $detail = $parsed['detail'];
                } elseif (isset($parsed['title']) && is_string($parsed['title'])) {
                    $detail = $parsed['title'];
                } elseif (isset($parsed['message']) && is_string($parsed['message'])) {
                    $detail = $parsed['message'];
                }
            } elseif ($text !== '') {
                $detail = $text;
            }
            throw new LinopayApiException(
                $detail,
                $status,
                $code,
                $parsed ?? $text,
            );
        }

        // `data: ...` envelope unwrap (matches the LinoPay convention).
        if (is_array($parsed) && array_key_exists('data', $parsed)) {
            $inner = $parsed['data'];
            return is_array($inner) ? $inner : null;
        }

        return $parsed;
    }
}
