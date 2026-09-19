using System.Security.Cryptography;
using System.Text;

namespace Linopay.Sdk;

/// <summary>
/// Webhook signature scheme — HMAC-SHA256, version-prefixed.
///
/// <para>
/// This is the mirror implementation of LinoPay's outbound-webhook
/// signer (C#:
/// <c>lime-payments/shared/LinoPay.Application/Abstractions/WebhookSignature.cs</c>).
/// A merchant who receives a webhook verifies it by:
/// </para>
/// <list type="number">
///   <item>Reading the <c>X-LinoPay-Timestamp</c> and
///   <c>X-LinoPay-Signature</c> headers.</item>
///   <item>Constructing the signed payload <c>${timestamp}.${rawBody}</c>
///   (timestamp is signed AS WELL AS sent — changing either
///   invalidates the signature, which kills replay attacks).</item>
///   <item>Recomputing HMAC-SHA256(secret, payload) and comparing the
///   hex digest to the <c>v1=...</c> value in <c>X-LinoPay-Signature</c>
///   in CONSTANT TIME (a byte-by-byte early return leaks the position
///   of the first mismatch — enough to recover a signature).</item>
/// </list>
/// </summary>
public static class WebhookSignature
{
    /// <summary>Header carrying <c>v1=&lt;hex&gt;</c>.</summary>
    public const string SignatureHeader = "X-LinoPay-Signature";

    /// <summary>Header carrying the Unix-seconds timestamp that was signed.</summary>
    public const string TimestampHeader = "X-LinoPay-Timestamp";

    /// <summary>Scheme version prefix on the signature value.</summary>
    public const string Version = "v1";

    /// <summary>Maximum age (in seconds) a delivery may be before a
    /// merchant should reject it. Advisory — enforced by the merchant,
    /// not by us.</summary>
    public static readonly TimeSpan ReplayWindow = TimeSpan.FromSeconds(300);

    /// <summary>
    /// Builds the byte-exact payload that gets HMAC'd:
    /// <c>${timestamp}.${body}</c>.
    /// </summary>
    public static string BuildSigningPayload(long unixSeconds, string body) =>
        unixSeconds.ToString(System.Globalization.CultureInfo.InvariantCulture) + "." + body;

    /// <summary>
    /// Computes the <c>X-LinoPay-Signature</c> value for a delivery:
    /// <c>"v1=" + lowercase hex HMAC-SHA256</c>.
    /// </summary>
    public static string Compute(string secret, long unixSeconds, string body)
    {
        if (string.IsNullOrEmpty(secret))
            throw new LinopayWebhookVerificationException("wrong-secret",
                "A non-empty signing secret is required to compute a webhook signature.");
        var payload = BuildSigningPayload(unixSeconds, body);
        var keyBytes = Encoding.UTF8.GetBytes(secret);
        var dataBytes = Encoding.UTF8.GetBytes(payload);
        var mac = HMACSHA256.HashData(keyBytes, dataBytes);
        return Version + "=" + Convert.ToHexString(mac).ToLowerInvariant();
    }

    /// <summary>
    /// Verifies a candidate signature in constant time. Throws
    /// <see cref="LinopayWebhookVerificationException"/> on any mismatch.
    /// Returns silently on success.
    /// </summary>
    public static void Verify(string secret, long unixSeconds, string body, string? candidate, long? nowSeconds = null)
    {
        if (string.IsNullOrWhiteSpace(candidate))
            throw new LinopayWebhookVerificationException("no-signature",
                "No signature header was supplied.");

        if (secret.Length == 0)
            throw new LinopayWebhookVerificationException("wrong-secret",
                "A non-empty signing secret is required to verify a webhook.");

        // Signature check FIRST, then replay-window check. A merchant
        // who forges a signature deserves a wrong-secret reason
        // regardless of the timestamp freshness; the replay check is a
        // defence against an attacker replaying a *valid* signature,
        // not against forging one.
        var expected = Compute(secret, unixSeconds, body);

        var a = Encoding.UTF8.GetBytes(expected);
        byte[] bRaw = Encoding.UTF8.GetBytes(candidate!);
        if (bRaw.Length != a.Length)
        {
            // Construct a same-length junk buffer for the safe-equal.
            // We already know this is a mismatch, but we need to spend
            // the constant time so we don't leak length info via timing.
            bRaw = new byte[a.Length];
        }

        if (!CryptographicOperations.FixedTimeEquals(a, bRaw))
            throw new LinopayWebhookVerificationException("wrong-secret",
                "Webhook signature did not match.");

        var now = nowSeconds ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        if (Math.Abs(now - unixSeconds) > (long)ReplayWindow.TotalSeconds)
            throw new LinopayWebhookVerificationException("replay-out-of-window",
                $"Timestamp {unixSeconds} is outside the {(int)ReplayWindow.TotalSeconds}-second replay window (now={now}).");
    }

    /// <summary>
    /// The user-facing wrapper: takes the convenience
    /// <see cref="WebhookVerifyArgs"/> record, throws on any
    /// mismatch, returns silently on success. Mirrors Phase 1's
    /// <c>webhooks.verify(input)</c>.
    /// </summary>
    public static void Verify(WebhookVerifyArgs args)
    {
        Verify(args.Secret, args.UnixSeconds, args.Body, args.Candidate, args.NowSeconds);
    }
}

public sealed record WebhookVerifyArgs(
    string Secret,
    long UnixSeconds,
    string Body,
    string? Candidate,
    long? NowSeconds = null);
