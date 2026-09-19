using System.Text.Json.Serialization;

namespace Linopay.Sdk;

/// <summary>
/// Flexi-Payments (invoicing), pay-now scope only.
///
/// <para>
/// The SOW (§1, Flexi-Payment bullet) is explicit: do NOT build an
/// SDK method for the "customer commits to a future date" capability
/// the company messaging describes — that backend work isn't on
/// <c>main</c> yet. Scope is exactly:
///   - create a pay-now invoice (dormant QR)
///   - get one (re-read, so the merchant screen can settle)
///   - cancel / recall
/// </para>
///
/// <para>
/// Auth: every call in this group uses the exchanged scoped token
/// (NOT a raw channel-signed JWT). The exchanged token carries
/// <c>invoices:write</c> for create/cancel, <c>invoices:read</c> for
/// get — but <c>invoices:write</c> implies read access too (see
/// <c>ChannelAccessToken.HasScope</c>), so a single cached token
/// suffices for all three.
/// </para>
/// </summary>
public sealed class InvoicesClient
{
    private readonly Config _config;
    private readonly LinopayHttp _http;
    private readonly ChannelTokenCache _tokens;

    public InvoicesClient(Config config, LinopayHttp http, ChannelTokenCache tokens)
    {
        _config = config.Validate();
        _http = http;
        _tokens = tokens;
    }

    public async Task<Invoice> CreateAsync(CreateInvoiceArgs args, CancellationToken ct = default)
    {
        if (args.AmountCents <= 0)
        {
            throw new LinopayConfigException(
                $"AmountCents must be a positive integer (got {args.AmountCents}).");
        }

        var token = await _tokens.ExchangeAsync(new[] { "invoices:write" }, ct);
        var body = new
        {
            amount = (args.AmountCents / 100m).ToString("F2", System.Globalization.CultureInfo.InvariantCulture),
            currency = args.Currency ?? "NZD",
            reference = args.Reference ?? null,
            paymentWindowDays = args.PaymentWindowDays ?? 14,
        };
        var resp = await _http.CallAsync<Invoice>(
            $"/api/merchants/{Uri.EscapeDataString(token.MerchantId)}" +
            $"/channels/{Uri.EscapeDataString(token.ChannelId)}/invoices",
            new CallOptions { Method = "POST", Token = token.AccessToken, Body = body },
            ct);
        return resp;
    }

    public async Task<Invoice> GetAsync(string invoiceId, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(invoiceId))
        {
            throw new LinopayConfigException("InvoiceId is required to look up an invoice.");
        }

        var token = await _tokens.ExchangeAsync(new[] { "invoices:read" }, ct);
        var resp = await _http.CallAsync<Invoice>(
            $"/api/merchants/{Uri.EscapeDataString(token.MerchantId)}/invoices/{Uri.EscapeDataString(invoiceId)}",
            new CallOptions { Method = "GET", Token = token.AccessToken },
            ct);
        return resp;
    }

    public async Task<Invoice> CancelAsync(string invoiceId, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(invoiceId))
        {
            throw new LinopayConfigException("InvoiceId is required to cancel an invoice.");
        }

        var token = await _tokens.ExchangeAsync(new[] { "invoices:write" }, ct);
        var resp = await _http.CallAsync<Invoice>(
            $"/api/merchants/{Uri.EscapeDataString(token.MerchantId)}/invoices/{Uri.EscapeDataString(invoiceId)}/cancel",
            new CallOptions { Method = "POST", Token = token.AccessToken, Body = new { } },
            ct);
        return resp;
    }
}

public sealed record CreateInvoiceArgs(
    int AmountCents,
    string? Currency = null,
    string? Reference = null,
    int? PaymentWindowDays = null);

public sealed record Invoice
{
    [JsonPropertyName("invoiceId")]           public string InvoiceId { get; set; } = "";
    [JsonPropertyName("invoiceCode")]         public string InvoiceCode { get; set; } = "";
    [JsonPropertyName("amount")]              public decimal Amount { get; set; }
    [JsonPropertyName("currency")]            public string Currency { get; set; } = "";
    [JsonPropertyName("reference")]           public string? Reference { get; set; }
    [JsonPropertyName("status")]              public string Status { get; set; } = "";
    [JsonPropertyName("dueAt")]               public string DueAt { get; set; } = "";
    [JsonPropertyName("expiresAt")]           public string ExpiresAt { get; set; } = "";
    [JsonPropertyName("paymentWindowDays")]   public int PaymentWindowDays { get; set; }
    [JsonPropertyName("qrCodeUrl")]           public string QrCodeUrl { get; set; } = "";
    [JsonPropertyName("qrCodeImage")]         public string QrCodeImage { get; set; } = "";
}
