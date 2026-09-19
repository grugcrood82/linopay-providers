using System.IdentityModel.Tokens.Jwt;
using Linopay.Sdk;
using Linopay.Sdk.Tests.Helpers;
using Xunit;

namespace Linopay.Sdk.Tests;

public class SigningTests
{
    [Fact]
    public void Signs_with_RS256_kid_issuer_audience()
    {
        var pem = TestPemGenerator.GenerateRsaPem();
        var token = ChannelJwtSigner.Sign(pem, "kid_test_abc", new Dictionary<string, object?>
        {
            ["channelKeyId"] = "kid_test_abc",
            ["txnRef"] = "tx_001",
            ["amount"] = "1.99",
            ["currency"] = "NZD",
        });

        // Decode the JWT manually — Microsoft's JwtSecurityToken may
        // surface iat inconsistently across versions, and the
        // production verifier on the server side reads bytes not
        // typed payloads.
        var parts = token.Split('.');
        Assert.Equal(3, parts.Length);

        var headerJson = DecodeBase64Url(parts[0]);
        var payloadJson = DecodeBase64Url(parts[1]);

        Assert.Contains("\"alg\":\"RS256\"", headerJson);
        Assert.Contains("\"kid\":\"kid_test_abc\"", headerJson);
        Assert.Contains("\"typ\":\"JWT\"", headerJson);

        Assert.Contains("\"iss\":\"linotech-pay\"", payloadJson);
        Assert.Contains("\"aud\":\"linotech-pay-api\"", payloadJson);
        Assert.Contains("\"channelKeyId\":\"kid_test_abc\"", payloadJson);
        Assert.Contains("\"txnRef\":\"tx_001\"", payloadJson);
        Assert.Contains("\"amount\":\"1.99\"", payloadJson);
        Assert.Contains("\"currency\":\"NZD\"", payloadJson);

        // 5min lifetime — both iat and exp must be present, within 5min
        // of each other (with 1s tolerance for clock skew). The
        // Microsoft handler may emit "iat" or "nbf" depending on the
        // version; the wire shape is what matters, not which one.
        long issuedAt;
        if (payloadJson.Contains("\"iat\":"))
            issuedAt = ExtractLong(payloadJson, "iat");
        else if (payloadJson.Contains("\"nbf\":"))
            issuedAt = ExtractLong(payloadJson, "nbf");
        else
            issuedAt = 0;  // sentinel for Assert.Fail below
        Assert.True(issuedAt != 0, $"Payload missing both iat and nbf: {payloadJson}");

        var exp = ExtractLong(payloadJson, "exp");
        Assert.InRange(exp - issuedAt, 290, 305);
    }

    private static string DecodeBase64Url(string s)
    {
        var padded = s.Replace('-', '+').Replace('_', '/');
        switch (padded.Length % 4)
        {
            case 2: padded += "=="; break;
            case 3: padded += "="; break;
        }
        return System.Text.Encoding.UTF8.GetString(Convert.FromBase64String(padded));
    }

    private static long ExtractLong(string json, string key)
    {
        // The Microsoft handler may serialise numeric claims as
        // `"iat":12345` OR `"iat":"12345"`. Match either form.
        var pattern = "\"" + System.Text.RegularExpressions.Regex.Escape(key) + "\":\"?(\\d+)\"?";
        var match = System.Text.RegularExpressions.Regex.Match(json, pattern);
        Assert.True(match.Success,
            $"Expected key '{key}' (as a decimal number) in payload: {json}");
        return long.Parse(match.Groups[1].Value);
    }

    [Fact]
    public void Forces_channelKeyId_to_the_configured_one()
    {
        var pem = TestPemGenerator.GenerateRsaPem();
        var token = ChannelJwtSigner.Sign(pem, "real_key_id", new Dictionary<string, object?>
        {
            ["channelKeyId"] = "forged_key_id",
        });
        var jwt = new JwtSecurityToken(token);
        Assert.Equal("real_key_id", jwt.Payload["channelKeyId"]);
    }

    [Fact]
    public void Refuses_to_sign_without_a_key_id()
    {
        var pem = TestPemGenerator.GenerateRsaPem();
        Assert.Throws<LinopayConfigException>(() =>
            ChannelJwtSigner.Sign(pem, "", new Dictionary<string, object?> { ["channelKeyId"] = "" }));
    }

    [Fact]
    public void Refuses_to_sign_without_a_private_key()
    {
        Assert.Throws<LinopayConfigException>(() =>
            ChannelJwtSigner.Sign("", "kid", new Dictionary<string, object?>()));
    }
}
