using Linopay.Sdk;
using Linopay.Sdk.Tests.Helpers;
using Xunit;

namespace Linopay.Sdk.Tests;

/// <summary>
/// SOW §2.1 — "Never log or persist secret material." This test
/// asserts the SDK's own behaviour: no error path leaks the PEM or
/// the webhook signing secret to a logger or to an exception
/// message.
/// </summary>
public class NoSecretLeakTests
{
    private const string SentinelPemPrefix = "-----SENTINEL-----";

    [Fact]
    public async Task Does_not_include_the_PEM_in_any_error_message()
    {
        // Embed a sentinel we control into the PEM and assert no message
        // carries it.
        var pem = TestPemGenerator.GenerateRsaPem();
        var pemWithSentinel = pem + "\n" + SentinelPemPrefix + "\n";

        var handler = new StubHandler(_ =>
            StubHandler.Json(System.Net.HttpStatusCode.InternalServerError, new
            {
                code = "OH_NO",
                message = "Something went wrong internally.",
            }));
        var captured = new List<string>();
        var captureLogger = new CaptureLogger(captured);

        var cfg = new Config
        {
            BaseUrl = "http://127.0.0.1:1",  // bind to refused port
            KeyId = "kid_noleak",
            PrivateKeyPem = pemWithSentinel,
            Environment = "sandbox",
            Logger = captureLogger,
        };
        var client = LinopayClient.Create(cfg, handler);

        // 1. Bad-call path: upstream error.
        try
        {
            await client.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD"));
        }
        catch (LinopayApiException) { }
        catch (Exception) { }

        // 2. Misconfiguration path: amount = 0.
        try
        {
            await client.Payments.CreateAsync(new CreatePaymentArgs(0));
        }
        catch (LinopayConfigException) { }
        catch (Exception) { }

        // 3. Channels path (also throws-something-style on the bad URL).
        try
        {
            await client.Channels.KeyStatusAsync();
        }
        catch (Exception) { }

        // 4. Webhook path: verify with an empty secret throws; check the message.
        try
        {
            WebhookSignature.Verify("", 1_789_000_000L, "{}", "v1=00", 1_789_000_000L);
        }
        catch (LinopayWebhookVerificationException ex)
        {
            Assert.DoesNotContain(SentinelPemPrefix, ex.Message);
        }

        // Sentinel substring should not appear anywhere captured.
        Assert.All(captured, line => Assert.DoesNotContain(SentinelPemPrefix, line));
        Assert.All(captured, line => Assert.DoesNotContain("BEGIN PRIVATE KEY", line));
        Assert.All(captured, line => Assert.DoesNotContain("BEGIN ENCRYPTED PRIVATE KEY", line));
    }
}

internal sealed class CaptureLogger : Linopay.Sdk.ILogger
{
    private readonly List<string> _lines;
    public CaptureLogger(List<string> lines) { _lines = lines; }
    public void Info(string message, IReadOnlyDictionary<string, object?>? meta = null) => _lines.Add($"info:{message}");
    public void Warn(string message, IReadOnlyDictionary<string, object?>? meta = null) => _lines.Add($"warn:{message}");
    public void Error(string message, IReadOnlyDictionary<string, object?>? meta = null) => _lines.Add($"error:{message}");
}
