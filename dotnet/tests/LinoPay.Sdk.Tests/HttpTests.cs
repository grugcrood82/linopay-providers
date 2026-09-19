using System.Net;
using System.Text.Json;
using Linopay.Sdk;
using Linopay.Sdk.Tests.Helpers;
using Xunit;

namespace Linopay.Sdk.Tests;

/// <summary>
/// Verifies the HTTP wrapper's contract using a stubbed
/// <see cref="HttpMessageHandler"/> — never hits the network.
/// </summary>
public class HttpTests
{
    private static Config MakeConfig() => new()
    {
        BaseUrl = "http://example.test",
        KeyId = "kid_http",
        PrivateKeyPem = TestPemGenerator.GenerateRsaPem(),
        BankCode = "ANZ",
        Environment = "sandbox",
    };

    private static LinopayClient MakeClientWith(StubHandler handler)
    {
        var cfg = MakeConfig();
        return LinopayClient.Create(cfg, handler);
        // The HTTP internal isn't directly addressable; instead we drive
        // it through the public surface. We use Payments.CreateAsync
        // because it's a single, signature-using call we can fake end-to-end.
    }

    [Fact]
    public async Task Sets_authorization_and_X_Channel_KeyId_headers()
    {
        var handler = new StubHandler(req =>
        {
            var auth = req.Headers.Authorization?.ToString();
            Assert.NotNull(auth);
            // Payments.CreateAsync always sends a freshly-signed JWT as the
            // bearer (the channel-signed instruction token). The header
            // is just expected to be a Bearer scheme carrying a JWT.
            Assert.StartsWith("Bearer eyJ", auth!);  // JWT compact-serialised starts with base64 'eyJ'
            Assert.Equal("kid_http", req.Headers.GetValues("X-Channel-KeyId").Single());
            return StubHandler.Json(HttpStatusCode.OK, new {
                transactionSagaId = "abc",
                qrCodeImage = "data:image/png;base64,iVBOR",
                consentUrl = "https://x",
                status = "Pending"
            });
        });
        var cfg = MakeConfig();
        var client = LinopayClient.Create(cfg, handler);
        await client.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD"));
    }

    [Fact]
    public async Task Unwraps_data_envelope()
    {
        var handler = new StubHandler(_ =>
            StubHandler.Json(HttpStatusCode.OK, new
            {
                data = new { invoiceId = "inv_1", invoiceCode = "INV-1", amount = 10m, currency = "NZD",
                              @reference = (string?)null, status = "Outstanding", dueAt = "x", expiresAt = "x",
                              paymentWindowDays = 14, qrCodeUrl = "x", qrCodeImage = "data:," }
            }));
        var cfg = MakeConfig();
        var client = LinopayClient.Create(cfg, handler);
        // Drive through Invoices.GetAsync — needs a token first, but
        // the stub responds to token-exchange too.
        handler.RespondWithPath("/v1/auth/channel-token",
            StubHandler.Json(HttpStatusCode.OK, new
            {
                data = new { accessToken = "tok_1", expiresIn = 300, merchantId = "m", channelId = "c",
                              scope = new[] { "invoices:read", "invoices:write" } }
            }));
        var invoice = await client.Invoices.GetAsync("inv_1");
        Assert.Equal("inv_1", invoice.InvoiceId);
    }

    [Fact]
    public async Task Throws_LinopayApiException_on_non_2xx_with_sanitised_code()
    {
        var handler = new StubHandler(_ =>
            StubHandler.Json(HttpStatusCode.NotFound, new
            {
                code = "CHANNEL_NOT_FOUND",
                message = "Channel not found."
            }));
        var cfg = MakeConfig();
        var client = LinopayClient.Create(cfg, handler);
        var ex = await Assert.ThrowsAsync<LinopayApiException>(async () =>
            await client.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD")));
        Assert.Equal(404, ex.Status);
        Assert.Equal("CHANNEL_NOT_FOUND", ex.Code);
        Assert.Equal("Channel not found.", ex.Message);
    }

    [Fact]
    public async Task Throws_LinopayApiException_on_non_2xx_with_RFC_7807_detail()
    {
        var handler = new StubHandler(_ =>
            StubHandler.Json(HttpStatusCode.NotFound, new
            {
                type = "about:blank",
                title = "Not Found",
                detail = "Wrong channel id.",
            }));
        var cfg = MakeConfig();
        var client = LinopayClient.Create(cfg, handler);
        var ex = await Assert.ThrowsAsync<LinopayApiException>(async () =>
            await client.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD")));
        Assert.Equal(404, ex.Status);
        Assert.Equal("about:blank", ex.Code);
        // HTTP wrapper prefers `detail` over `title`. RFC 7807's `detail`
        // is the human-readable explanation; `title` is the summary type.
        Assert.Equal("Wrong channel id.", ex.Message);
    }
}

/// <summary>
/// Minimal HTTP handler stub that captures the request and returns a
/// pre-baked response. Matches all paths by default; <see cref="RespondWithPath"/>
/// registers per-path response builders.
/// </summary>
public class StubHandler : HttpMessageHandler
{
    private readonly Func<HttpRequestMessage, HttpResponseMessage> _default;
    private readonly Dictionary<string, Func<HttpResponseMessage>> _paths = new();

    public StubHandler(Func<HttpRequestMessage, HttpResponseMessage> @default)
        => _default = @default;

    public void RespondWithPath(string path, HttpResponseMessage resp)
        => _paths[path] = () => resp;

    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage req, CancellationToken ct)
    {
        var path = req.RequestUri?.AbsolutePath;
        if (path is not null && _paths.TryGetValue(path, out var builder))
            return Task.FromResult(builder());
        return Task.FromResult(_default(req));
    }

    public static HttpResponseMessage Json(HttpStatusCode status, object body) =>
        new(status)
        {
            Content = new StringContent(JsonSerializer.Serialize(body, new JsonSerializerOptions(JsonSerializerDefaults.Web)))
            {
                Headers = { ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("application/json") }
            }
        };
}
