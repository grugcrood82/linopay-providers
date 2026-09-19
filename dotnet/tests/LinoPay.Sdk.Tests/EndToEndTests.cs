using System.IO;
using Linopay.Sdk;
using Linopay.Sdk.Tests.Helpers;
using Xunit;

namespace Linopay.Sdk.Tests;

/// <summary>
/// End-to-end tests against a real WireMock server. Skipped unless
/// <c>LINOPAY_E2E_BASE_URL</c> is set — the docker-compose self-check
/// sets it.
/// </summary>
public class EndToEndTests
{
    private const string BaseUrlEnvVar = "LINOPAY_E2E_BASE_URL";
    private const string PemPathEnvVar = "LINOPAY_E2E_PEM_PATH";

    private static bool ShouldRun()
    {
        var url = Environment.GetEnvironmentVariable(BaseUrlEnvVar);
        return !string.IsNullOrWhiteSpace(url);
    }

    public static IEnumerable<object[]> EndToEndMemberData => ShouldRun()
        ? new[] { new object[] { Environment.GetEnvironmentVariable(BaseUrlEnvVar)! } }
        : Array.Empty<object[]>();

    [SkippableFact]
    public async Task Creates_a_one_off_payment_against_wiremock()
    {
        Skip.IfNot(ShouldRun(), $"Set {BaseUrlEnvVar} to run this test.");
        var baseUrl = Environment.GetEnvironmentVariable(BaseUrlEnvVar)!;
        var pemPath = Environment.GetEnvironmentVariable(PemPathEnvVar) ?? "/tmp/test-key.pem";
        Directory.CreateDirectory(Path.GetDirectoryName(pemPath)!);
        if (!File.Exists(pemPath))
        {
            await File.WriteAllTextAsync(pemPath, TestPemGenerator.GenerateRsaPem());
        }
        var pem = await File.ReadAllTextAsync(pemPath);

        var cfg = new Config
        {
            BaseUrl = baseUrl,
            KeyId = "kid_e2e",
            PrivateKeyPem = pem,
            BankCode = "ANZ",
            Environment = "sandbox",
        };
        var client = LinopayClient.Create(cfg);
        var payment = await client.Payments.CreateAsync(new CreatePaymentArgs(199, "NZD"));
        Assert.StartsWith("wire_saga_", payment.SagaId);
        Assert.StartsWith("data:image/png", payment.QrCodeImage);
        Assert.Contains("oauth/v2.0/authorize", payment.ConsentUrl);
        Assert.Equal("Pending", payment.Status);
    }

    [SkippableFact]
    public async Task Creates_an_invoice_after_token_exchange()
    {
        Skip.IfNot(ShouldRun(), $"Set {BaseUrlEnvVar} to run this test.");
        var baseUrl = Environment.GetEnvironmentVariable(BaseUrlEnvVar)!;
        var pemPath = Environment.GetEnvironmentVariable(PemPathEnvVar) ?? "/tmp/test-key.pem";
        var pem = await File.ReadAllTextAsync(pemPath);

        var cfg = new Config
        {
            BaseUrl = baseUrl,
            KeyId = "kid_e2e_invoice",
            PrivateKeyPem = pem,
            BankCode = "ASB",
            Environment = "sandbox",
        };
        var client = LinopayClient.Create(cfg);
        var invoice = await client.Invoices.CreateAsync(new CreateInvoiceArgs(1000, Reference: "wire-test-ref"));
        Assert.StartsWith("wire_inv_", invoice.InvoiceId);
        Assert.Equal("Outstanding", invoice.Status);
    }

    [SkippableFact]
    public async Task Reads_the_active_channel_key_status()
    {
        Skip.IfNot(ShouldRun(), $"Set {BaseUrlEnvVar} to run this test.");
        var baseUrl = Environment.GetEnvironmentVariable(BaseUrlEnvVar)!;
        var pemPath = Environment.GetEnvironmentVariable(PemPathEnvVar) ?? "/tmp/test-key.pem";
        var pem = await File.ReadAllTextAsync(pemPath);

        var cfg = new Config
        {
            BaseUrl = baseUrl,
            KeyId = "kid_e2e_keystatus",
            PrivateKeyPem = pem,
            BankCode = "BNZ",
            Environment = "sandbox",
        };
        var client = LinopayClient.Create(cfg);
        var status = await client.Channels.KeyStatusAsync();
        Assert.StartsWith("wire_kid_", status.ActiveKeyId);
        Assert.Equal("Active", status.EffectiveStatus);
        Assert.NotNull(status.DaysRemaining);
    }
}
