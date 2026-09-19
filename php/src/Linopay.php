<?php

declare(strict_types=1);

namespace Linotech\Sdk;

use GuzzleHttp\ClientInterface;

/**
 * Top-level SDK entry point.
 *
 *   $linopay = Linopay::create($config);
 *   $saga = $linopay->payments->create(new CreatePaymentInput(199, 'NZD'));
 *   $invoice = $linopay->invoices->create(new CreateInvoiceInput(1000));
 *   $key = $linopay->channels->keyStatus();
 *   WebhookSignature::verify($secret, $ts, $body, $sig);
 *
 * No method touches the network without going through the shared
 * {@see HttpClient}, so the `Authorization` header shape, the
 * `X-Channel-KeyId` header shape, the `{ data: ... }` envelope
 * unwrap, and the error-translation rules are all in exactly one
 * place.
 */
final class Linopay
{
    public readonly HttpClient $http;
    public readonly PaymentsClient $payments;
    public readonly InvoicesClient $invoices;
    public readonly ChannelsClient $channels;

    private function __construct(
        Config $config,
        HttpClient $http,
        PaymentsClient $payments,
        InvoicesClient $invoices,
        ChannelsClient $channels,
    ) {
        $this->http = $http;
        $this->payments = $payments;
        $this->invoices = $invoices;
        $this->channels = $channels;
    }

    public static function create(Config $config, ?ClientInterface $guzzle = null): self
    {
        $http = new HttpClient($config, $guzzle);
        $tokens = new ChannelTokenCache($config, $http);
        return new self(
            $config,
            $http,
            new PaymentsClient($config, $http),
            new InvoicesClient($config, $http, $tokens),
            new ChannelsClient($config, $http, $tokens),
        );
    }
}
