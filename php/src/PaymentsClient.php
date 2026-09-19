<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * One-off payment (Phase A in the demo).
 *
 * `create()` is the only SDK entry point that uses the raw
 * channel-signed instruction JWT — the one carrying `amount`,
 * `currency`, and `txnRef` as signed claims.
 */
final class PaymentsClient
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
    ) {
    }

    public function create(CreatePaymentInput $input): CreatePaymentResult
    {
        if ($input->amountCents <= 0) {
            throw new LinopayConfigException(
                "amountCents must be a positive integer (got {$input->amountCents})."
            );
        }

        $reference = $input->reference ?? self::uuid();
        $currency = $input->currency ?? 'NZD';
        $targetBank = $input->targetBank ?? $this->config->bankCode;
        if ($targetBank === null || $targetBank === '') {
            throw new LinopayConfigException(
                'No bank code configured. Set Config.bankCode or pass targetBank explicitly.'
            );
        }

        $token = ChannelJwtSigner::sign(
            $this->config->privateKeyPem,
            $this->config->keyId,
            [
                'channelKeyId' => $this->config->keyId,
                'txnRef' => $reference,
                'amount' => number_format($input->amountCents / 100, 2, '.', ''),
                'currency' => $currency,
            ],
        );

        $response = $this->http->callJson(
            '/v1/payments/qrcode',
            new CallOptions(
                method: 'POST',
                token: $token,
                body: [
                    'riskNote' => null,
                    'targetBank' => $targetBank,
                    'imageDimensions' => $input->imageDimensions ?? ['width' => 320, 'height' => 320],
                ],
            ),
        );

        if (!is_array($response)) {
            throw new LinopayApiException(
                'Payments.create returned an unexpected response.',
                500,
            );
        }

        return new CreatePaymentResult(
            sagaId: $response['transactionSagaId'] ?? '',
            qrCodeImage: $response['qrCodeImage'] ?? '',
            consentUrl: $response['consentUrl'] ?? '',
            status: $response['status'] ?? '',
            reference: $reference,
        );
    }

    public function get(string $sagaId): PaymentStatus
    {
        if ($sagaId === '') {
            throw new LinopayConfigException('sagaId is required to look up a payment.');
        }

        $token = ChannelJwtSigner::sign(
            $this->config->privateKeyPem,
            $this->config->keyId,
            [
                'channelKeyId' => $this->config->keyId,
                'txnRef' => $sagaId,
            ],
        );

        $response = $this->http->callJson(
            "/v1/payments/transactions/" . rawurlencode($sagaId) . '/channel-status',
            new CallOptions(method: 'POST', token: $token, body: []),
        );

        if (!is_array($response)) {
            throw new LinopayApiException(
                'Payments.get returned an unexpected response.',
                500,
            );
        }

        return new PaymentStatus(
            transactionSagaId: $response['transactionSagaId'] ?? $sagaId,
            status: $response['status'] ?? '',
            amount: (float) ($response['amount'] ?? 0),
            currency: $response['currency'] ?? '',
        );
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

final class CreatePaymentInput
{
    /**
     * @param array{width:int, height:int}|null $imageDimensions
     */
    public function __construct(
        public readonly int $amountCents,
        public readonly ?string $currency = null,
        public readonly ?string $reference = null,
        public readonly ?string $targetBank = null,
        public readonly ?array $imageDimensions = null,
    ) {
    }
}

final class CreatePaymentResult
{
    public function __construct(
        public readonly string $sagaId,
        public readonly string $qrCodeImage,
        public readonly string $consentUrl,
        public readonly string $status,
        public readonly string $reference,
    ) {
    }
}

final class PaymentStatus
{
    public function __construct(
        public readonly string $transactionSagaId,
        public readonly string $status,
        public readonly float $amount,
        public readonly string $currency,
    ) {
    }
}
