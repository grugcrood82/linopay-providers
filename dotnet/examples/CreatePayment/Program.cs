using System.Security.Cryptography;
using Linopay.Sdk;

namespace LinoPay.Sdk.Example.CreatePayment;

// Runnable example: create a one-off payment against a LinoPay-
// compatible API. Used by `dotnet run --project examples/CreatePayment`
// in the containerised self-check.
//
// Usage (against the WireMock sandbox):
//   LINOPAY_BASE_URL=http://localhost:18080 \
//   LINOPAY_KEY_ID=kid_demo \
//   LINOPAY_PRIVATE_KEY_PEM_PATH=./.demo-key.pem \
//   LINOPAY_ENVIRONMENT=sandbox \
//   LINOPAY_BANK_CODE=ANZ \
//   dotnet run --project examples/CreatePayment
//
// The script writes a friendly summary rather than dumping the whole
// response object — that's the audience this is for.
public static class Program
{
    public static async Task<int> Main()
    {
        var baseUrl = Environment.GetEnvironmentVariable("LINOPAY_BASE_URL") ?? "http://localhost:18080";
        var keyId = Environment.GetEnvironmentVariable("LINOPAY_KEY_ID") ?? "kid_demo";
        var pemPath = Environment.GetEnvironmentVariable("LINOPAY_PRIVATE_KEY_PEM_PATH") ?? "./.demo-key.pem";
        var environment = Environment.GetEnvironmentVariable("LINOPAY_ENVIRONMENT") ?? "sandbox";
        var bankCode = Environment.GetEnvironmentVariable("LINOPAY_BANK_CODE") ?? "ANZ";
        var amountCentsRaw = Environment.GetEnvironmentVariable("LINOPAY_AMOUNT_CENTS") ?? "199";

        if (!int.TryParse(amountCentsRaw, out var amountCents) || amountCents <= 0)
        {
            Console.Error.WriteLine("LINOPAY_AMOUNT_CENTS must be a positive integer (in cents).");
            return 1;
        }

        // Generate a throwaway PEM if none exists. The sandbox doesn't
        // care which key pair we present — the token-exchange endpoint
        // returns a stub token regardless. For a real LinoPay exchange,
        // the public half must be on the channel's record.
        var pem = EnsurePem(pemPath);

        var cfg = new Config
        {
            BaseUrl = baseUrl,
            KeyId = keyId,
            PrivateKeyPem = pem,
            BankCode = bankCode,
            Environment = environment,
        };

        Console.WriteLine($"Creating a one-off payment of {(amountCents / 100m):F2} NZD ...");
        var client = LinopayClient.Create(cfg);
        var payment = await client.Payments.CreateAsync(new CreatePaymentArgs(amountCents, "NZD"));
        Console.WriteLine($"Saga {payment.SagaId} created.");
        Console.WriteLine($"  status:      {payment.Status}");
        Console.WriteLine($"  consentUrl:  {payment.ConsentUrl}");
        Console.WriteLine($"  qrCodeImage: {payment.QrCodeImage[..Math.Min(60, payment.QrCodeImage.Length)]}... ({payment.QrCodeImage.Length} bytes)");
        return 0;
    }

    private static string EnsurePem(string path)
    {
        if (File.Exists(path))
            return File.ReadAllText(path);
        using var rsa = RSA.Create(2048);
        var pem = rsa.ExportPkcs8PrivateKeyPem();
        File.WriteAllText(path, pem);
        File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        return pem;
    }
}
