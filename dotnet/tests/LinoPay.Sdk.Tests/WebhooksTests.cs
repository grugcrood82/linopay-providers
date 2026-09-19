using System.Security.Cryptography;
using Linopay.Sdk;
using Xunit;

namespace Linopay.Sdk.Tests;

public class WebhooksTests
{
    private const string Secret = "whsec_testsecret_abcdef";
    private const string Body = "{\"event\":\"invoice.settled\",\"data\":{\"invoiceId\":\"2_9hYAOr\"}}";
    private const long Ts = 1_789_000_000;

    [Fact]
    public void Build_signing_payload_pins_timestamp_to_body()
    {
        Assert.Equal($"{Ts}.{Body}", WebhookSignature.BuildSigningPayload(Ts, Body));
    }

    [Fact]
    public void Produces_a_stable_v1_lowercase_hex_signature()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        Assert.StartsWith("v1=", sig);
        var hex = sig.Substring("v1=".Length);
        Assert.Equal(64, hex.Length); // SHA-256 -> 32 bytes
        Assert.Equal(hex.ToLowerInvariant(), hex);
        Assert.Equal(sig, WebhookSignature.Compute(Secret, Ts, Body)); // deterministic
    }

    [Fact]
    public void Signs_both_timestamp_and_body_together()
    {
        Assert.NotEqual(WebhookSignature.Compute(Secret, Ts, Body),
                        WebhookSignature.Compute(Secret, Ts + 1, Body));
        Assert.NotEqual(WebhookSignature.Compute(Secret, Ts, Body),
                        WebhookSignature.Compute(Secret, Ts, Body + " "));
    }

    [Fact]
    public void Refuses_to_sign_with_an_empty_secret()
    {
        Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Compute("", Ts, Body));
    }

    [Fact]
    public void Verifies_a_freshly_signed_payload()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        WebhookSignature.Verify(Secret, Ts, Body, sig, nowSeconds: Ts);
    }

    [Fact]
    public void Rejects_a_tampered_body()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        var ex = Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body + " ", sig, nowSeconds: Ts));
        Assert.Equal("wrong-secret", ex.Reason);
    }

    [Fact]
    public void Rejects_a_replayed_under_a_fresh_timestamp()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, sig,
                nowSeconds: Ts + (long)WebhookSignature.ReplayWindow.TotalSeconds + 60));
    }

    [Fact]
    public void Rejects_outside_the_replay_window()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        var ex = Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, sig,
                nowSeconds: Ts + (long)WebhookSignature.ReplayWindow.TotalSeconds + 1));
        Assert.Equal("replay-out-of-window", ex.Reason);
    }

    [Fact]
    public void Rejects_a_wrong_secret()
    {
        var sig = WebhookSignature.Compute("whsec_wrong", Ts, Body);
        var ex = Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, sig, nowSeconds: Ts));
        Assert.Equal("wrong-secret", ex.Reason);
    }

    [Fact]
    public void Rejects_missing_or_empty_signature()
    {
        Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, "", nowSeconds: Ts));
        Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, "  ", nowSeconds: Ts));
    }

    [Fact]
    public void Uses_constant_time_comparison_even_with_length_mismatch()
    {
        // A wrong-length candidate must still surface a wrong-secret reason,
        // proving the verifier does not crash on a length mismatch and does
        // not leak the "wrong-length" fact to the error reason.
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        var truncated = sig[..32];
        var ex = Assert.Throws<LinopayWebhookVerificationException>(() =>
            WebhookSignature.Verify(Secret, Ts, Body, truncated, nowSeconds: Ts));
        Assert.Equal("wrong-secret", ex.Reason);
    }

    [Fact]
    public void Verifier_overload_works_via_args_record()
    {
        var sig = WebhookSignature.Compute(Secret, Ts, Body);
        var args = new WebhookVerifyArgs(Secret, Ts, Body, sig, Ts);
        WebhookSignature.Verify(args);  // does not throw
    }
}
