using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.IdentityModel.Tokens;
using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;

namespace Linopay.Sdk;

/// <summary>
/// Channel-side request signing.
///
/// <para>
/// The merchant channel has its own RS256 key pair. To call certain
/// LinoPay endpoints (the ones that authorise money movement, like
/// <c>POST /v1/payments/qrcode</c>), the merchant sends a JWT signed
/// with the channel's private key. The JWT carries the request claims
/// (amount, currency, txnRef, ...) and is verified server-side against
/// the channel's registered public key.
/// </para>
///
/// <para>
/// Issuer and audience are constants on purpose: they're not in the
/// Config object. Moving them onto Config would let a caller silently
/// send tokens the server rejects.
/// </para>
/// </summary>
public static class ChannelJwtSigner
{
    /// <summary>Issuer claim on every channel-signed JWT.</summary>
    public const string Issuer = "linotech-pay";

    /// <summary>Audience claim on every channel-signed JWT.</summary>
    public const string Audience = "linotech-pay-api";

    /// <summary>Lifetime — 5 minutes, matches lime-payments' server.</summary>
    public static readonly TimeSpan Lifetime = TimeSpan.FromMinutes(5);

    /// <summary>Signs a payload with the channel's private key and
    /// returns the compact-serialised JWT. Throws
    /// <see cref="LinopayConfigException"/> if either the key id or
    /// the PEM is empty.</summary>
    public static string Sign(string privateKeyPem, string keyId, IReadOnlyDictionary<string, object?> claims)
    {
        if (string.IsNullOrWhiteSpace(keyId))
            throw new LinopayConfigException("Channel Key ID is required to sign a request.");
        if (string.IsNullOrWhiteSpace(privateKeyPem))
            throw new LinopayConfigException("Channel private key is required to sign a request.");

        var key = LoadRsaSecurityKey(privateKeyPem);
        var creds = new SigningCredentials(key, SecurityAlgorithms.RsaSha256);

        var iat = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        var payloadClaims = new Dictionary<string, object?>(claims)
        {
            // Always pin channelKeyId to the configured one — never trust the
            // caller to set it correctly. Matches `cfg.keyId` is enforced at
            // call site; this is the no-clobber default.
            ["channelKeyId"] = keyId,
            // Issued-at + lifetime. Adding `iat` explicitly because the
            // Microsoft handler doesn't always emit it; the upstream
            // verifier on the LinoPay side expects it.
            ["iat"] = iat,
            ["nbf"] = iat,
            ["exp"] = iat + (long)Lifetime.TotalSeconds,
        };

        var handler = new JwtSecurityTokenHandler();
        var token = new JwtSecurityToken(
            issuer: Issuer,
            audience: Audience,
            claims: ToIdentityClaims(payloadClaims),
            notBefore: DateTimeOffset.UtcNow.UtcDateTime,
            expires: DateTimeOffset.UtcNow.UtcDateTime.Add(Lifetime),
            signingCredentials: creds);

        token.Header["kid"] = keyId;
        token.Header["typ"] = "JWT";

        return handler.WriteToken(token);
    }

    private static SecurityKey LoadRsaSecurityKey(string pem)
    {
        // Microsoft's SecurityKey support for PEM takes a slight dance.
        // The cleanest path: parse the PEM with .NET 9's
        // RSA.ImportFromPem() and synthesise a RsaSecurityKey from the
        // private key's parameters.
        using var rsa = RSA.Create();
        rsa.ImportFromPem(pem.AsSpan());
        return new RsaSecurityKey(rsa.ExportParameters(includePrivateParameters: true));
    }

    private static IEnumerable<Claim> ToIdentityClaims(IReadOnlyDictionary<string, object?> payload)
    {
        foreach (var (k, v) in payload)
        {
            if (v is null) continue;
            // JwtSecurityToken requires string claims or special JSON
            // serialised ones. Convert everything else via JSON so the
            // claims round-trip the same way as the wire format.
            yield return new Claim(k, v is string s ? s : JsonSerializer.Serialize(v));
        }
    }
}
