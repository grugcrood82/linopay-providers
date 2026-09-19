using System.Text.Json.Serialization;

namespace Linopay.Sdk;

/// <summary>
/// One-off payment (Phase A in the demo).
///
/// <para>
/// <see cref="CreateAsync"/> is the only SDK entry point that uses
/// the raw channel-signed instruction JWT — the one carrying
/// <c>amount</c>, <c>currency</c>, and <c>txnRef</c> as signed claims.
/// That signature is what tells the LinoPay gateway "this channel
/// authorises this specific money movement", so it isn't
/// interchangeable with the exchanged scoped token the invoices
/// endpoints use.
/// </para>
///
/// <para>
/// <see cref="GetAsync"/> polls the channel-authenticated status
/// endpoint that the demo at <c>lime-payments/demos/server/lino.mjs</c>
/// calls <c>channel-status</c>. The poll uses the channel-signed
/// token too, so a merchant can call it without first exchanging a
/// token (good for "did the customer ever pay?" lookups where
/// there's no invoice to read).
/// </para>
/// </summary>
public sealed class PaymentsClient
{
    private readonly Config _config;
    private readonly LinopayHttp _http;
    private readonly ILogger _logger;

    public PaymentsClient(Config config, LinopayHttp http, ILogger? logger = null)
    {
        _config = config.Validate();
        _http = http;
        _logger = logger ?? new NullLogger();
    }

    public async Task<CreatePaymentResult> CreateAsync(CreatePaymentArgs args, CancellationToken ct = default)
    {
        if (args.AmountCents <= 0)
        {
            throw new LinopayConfigException(
                $"AmountCents must be a positive integer (got {args.AmountCents}).");
        }

        var reference = args.Reference ?? Guid.NewGuid().ToString("N");
        var currency = args.Currency ?? "NZD";
        var targetBank = args.TargetBank ?? _config.BankCode;
        if (string.IsNullOrWhiteSpace(targetBank))
        {
            throw new LinopayConfigException(
                "No bank code configured. Set Config.BankCode or pass TargetBank explicitly.");
        }

        var token = ChannelJwtSigner.Sign(
            _config.PrivateKeyPem,
            _config.KeyId,
            new Dictionary<string, object?>
            {
                ["channelKeyId"] = _config.KeyId,
                ["txnRef"] = reference,
                ["amount"] = (args.AmountCents / 100m).ToString("F2", System.Globalization.CultureInfo.InvariantCulture),
                ["currency"] = currency,
            });

        var body = new
        {
            riskNote = (string?)null,
            targetBank = targetBank,
            imageDimensions = new { width = 320, height = 320 },
        };

        var resp = await _http.CallAsync<WireQrResponse>(
            "/v1/payments/qrcode",
            new CallOptions { Method = "POST", Token = token, Body = body },
            ct);
        return new CreatePaymentResult(
            SagaId: resp.TransactionSagaId,
            QrCodeImage: resp.QrCodeImage,
            ConsentUrl: resp.ConsentUrl,
            Status: resp.Status,
            Reference: reference);
    }

    public async Task<PaymentStatus> GetAsync(string sagaId, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(sagaId))
        {
            throw new LinopayConfigException("SagaId is required to look up a payment.");
        }

        var token = ChannelJwtSigner.Sign(
            _config.PrivateKeyPem,
            _config.KeyId,
            new Dictionary<string, object?>
            {
                ["channelKeyId"] = _config.KeyId,
                ["txnRef"] = sagaId,
            });

        var resp = await _http.CallAsync<WireStatusResponse>(
            $"/v1/payments/transactions/{Uri.EscapeDataString(sagaId)}/channel-status",
            new CallOptions { Method = "POST", Token = token, Body = new { } },
            ct);
        return new PaymentStatus(
            TransactionSagaId: resp.TransactionSagaId,
            Status: resp.Status,
            Amount: resp.Amount,
            Currency: resp.Currency);
    }

    private sealed class WireQrResponse
    {
        [JsonPropertyName("transactionSagaId")] public string TransactionSagaId { get; set; } = "";
        [JsonPropertyName("qrCodeImage")]       public string QrCodeImage { get; set; } = "";
        [JsonPropertyName("consentUrl")]        public string ConsentUrl { get; set; } = "";
        [JsonPropertyName("status")]            public string Status { get; set; } = "";
    }

    private sealed class WireStatusResponse
    {
        [JsonPropertyName("transactionSagaId")] public string TransactionSagaId { get; set; } = "";
        [JsonPropertyName("status")]            public string Status { get; set; } = "";
        [JsonPropertyName("amount")]            public decimal Amount { get; set; }
        [JsonPropertyName("currency")]          public string Currency { get; set; } = "";
    }
}

public sealed record CreatePaymentArgs(
    int AmountCents,
    string? Currency = null,
    string? Reference = null,
    string? TargetBank = null);

public sealed record CreatePaymentResult(
    string SagaId,
    string QrCodeImage,
    string ConsentUrl,
    string Status,
    string Reference);

public sealed record PaymentStatus(
    string TransactionSagaId,
    string Status,
    decimal Amount,
    string Currency);
