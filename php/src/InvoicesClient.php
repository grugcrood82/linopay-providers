<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Flexi-Payments (invoicing), pay-now scope only.
 *
 * Every call in this group uses the exchanged scoped token (NOT a raw
 * channel-signed JWT). The exchanged token carries `invoices:write`
 * for create/cancel, `invoices:read` for get — but `invoices:write`
 * implies read access too, so a single cached token suffices for all
 * three (this is `ChannelAccessToken.HasScope` upstream).
 */
final class InvoicesClient
{
    public function __construct(
        private readonly Config $_config,
        private readonly HttpClient $http,
        private readonly ChannelTokenCache $tokens,
    ) {
    }

    public function create(CreateInvoiceInput $input): Invoice
    {
        if ($input->amountCents <= 0) {
            throw new LinopayConfigException(
                "amountCents must be a positive integer (got {$input->amountCents})."
            );
        }

        $token = $this->tokens->exchange(['invoices:write']);

        $response = $this->http->callJson(
            "/api/merchants/" . rawurlencode($token->merchantId)
            . "/channels/" . rawurlencode($token->channelId) . '/invoices',
            new CallOptions(
                method: 'POST',
                token: $token->accessToken,
                body: [
                    'amount' => number_format($input->amountCents / 100, 2, '.', ''),
                    'currency' => $input->currency ?? 'NZD',
                    'reference' => $input->reference,
                    'paymentWindowDays' => $input->paymentWindowDays ?? 14,
                ],
            ),
        );

        if (!is_array($response)) {
            throw new LinopayApiException('Invoices.create returned an unexpected response.', 500);
        }

        return Invoice::fromResponse($response);
    }

    public function get(string $invoiceId): Invoice
    {
        if ($invoiceId === '') {
            throw new LinopayConfigException('invoiceId is required to look up an invoice.');
        }

        $token = $this->tokens->exchange(['invoices:read']);

        $response = $this->http->callJson(
            "/api/merchants/" . rawurlencode($token->merchantId)
            . "/invoices/" . rawurlencode($invoiceId),
            new CallOptions(method: 'GET', token: $token->accessToken),
        );

        if (!is_array($response)) {
            throw new LinopayApiException('Invoices.get returned an unexpected response.', 500);
        }

        return Invoice::fromResponse($response);
    }

    public function cancel(string $invoiceId): Invoice
    {
        if ($invoiceId === '') {
            throw new LinopayConfigException('invoiceId is required to cancel an invoice.');
        }

        $token = $this->tokens->exchange(['invoices:write']);

        $response = $this->http->callJson(
            "/api/merchants/" . rawurlencode($token->merchantId)
            . "/invoices/" . rawurlencode($invoiceId) . '/cancel',
            new CallOptions(method: 'POST', token: $token->accessToken, body: []),
        );

        if (!is_array($response)) {
            throw new LinopayApiException('Invoices.cancel returned an unexpected response.', 500);
        }

        return Invoice::fromResponse($response);
    }
}

final class CreateInvoiceInput
{
    public function __construct(
        public readonly int $amountCents,
        public readonly ?string $currency = null,
        public readonly ?string $reference = null,
        public readonly ?int $paymentWindowDays = null,
    ) {
    }
}

final class Invoice
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceCode,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $reference,
        public readonly string $status,
        public readonly string $dueAt,
        public readonly string $expiresAt,
        public readonly int $paymentWindowDays,
        public readonly string $qrCodeUrl,
        public readonly string $qrCodeImage,
    ) {
    }

    /**
     * @param array<string,mixed> $r
     */
    public static function fromResponse(array $r): self
    {
        return new self(
            invoiceId: (string) ($r['invoiceId'] ?? ''),
            invoiceCode: (string) ($r['invoiceCode'] ?? ''),
            amount: (float) ($r['amount'] ?? 0),
            currency: (string) ($r['currency'] ?? ''),
            reference: isset($r['reference']) ? (string) $r['reference'] : null,
            status: (string) ($r['status'] ?? ''),
            dueAt: (string) ($r['dueAt'] ?? ''),
            expiresAt: (string) ($r['expiresAt'] ?? ''),
            paymentWindowDays: (int) ($r['paymentWindowDays'] ?? 0),
            qrCodeUrl: (string) ($r['qrCodeUrl'] ?? ''),
            qrCodeImage: (string) ($r['qrCodeImage'] ?? ''),
        );
    }
}
