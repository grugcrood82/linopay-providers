using System.Security.Cryptography;

namespace Linopay.Sdk.Tests.Helpers;

/// <summary>
/// Generates a fresh RSA PKCS#8 PEM for a test. The matching public
/// half is implicit (the SDK only signs; verifying happens at
/// integration boundaries, not in unit tests).
/// </summary>
public static class TestPemGenerator
{
    public static string GenerateRsaPem(int bits = 2048)
    {
        using var rsa = RSA.Create(bits);
        return rsa.ExportPkcs8PrivateKeyPem();
    }
}
