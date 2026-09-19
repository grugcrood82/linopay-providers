namespace Linopay.Sdk;

/// <summary>
/// Top-level SDK entry point.
///
/// <para>
/// <c>var linopay = LinopayClient.Create(config);</c><br/>
/// <c>var saga = await linopay.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD"));</c><br/>
/// <c>var invoice = await linopay.Invoices.CreateAsync(new CreateInvoiceArgs(1000));</c><br/>
/// <c>var key = await linopay.Channels.KeyStatusAsync();</c><br/>
/// <c>WebhookSignature.Verifier.Verify(new WebhookVerifyArgs(...));</c>
/// </para>
///
/// <para>
/// No method touches the network without going through the shared
/// <see cref="LinopayHttp"/>, so the <c>Authorization</c> header
/// shape, the <c>X-Channel-KeyId</c> header shape, the
/// <c>{ data: ... }</c> envelope unwrap, and the error-translation
/// rules are all in exactly one place.
/// </para>
/// </summary>
public sealed class LinopayClient
{
    public LinopayHttp Http { get; }
    public PaymentsClient Payments { get; }
    public InvoicesClient Invoices { get; }
    public ChannelsClient Channels { get; }

    public LinopayClient(Config config, HttpMessageHandler? handler = null, ILogger? logger = null)
    {
        config.Validate();
        Http = new LinopayHttp(config, handler);
        var tokens = new ChannelTokenCache(config, Http);
        Payments = new PaymentsClient(config, Http, logger);
        Invoices = new InvoicesClient(config, Http, tokens);
        Channels = new ChannelsClient(config, Http, tokens);
    }

    /// <summary>Static factory — the canonical entry point.</summary>
    public static LinopayClient Create(Config config, HttpMessageHandler? handler = null, ILogger? logger = null)
        => new(config, handler, logger);
}
