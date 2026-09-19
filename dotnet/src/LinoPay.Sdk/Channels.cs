using System.Text.Json.Serialization;

namespace Linopay.Sdk;

/// <summary>
/// Channel-key lifecycle helper.
///
/// <para>
/// The merchant channel has a rotating RSA key. The active key has
/// a fixed expiry date (computed at rotation time: now + 12 months by
/// default). After the expiry, the LinoPay gateway flips the row's
/// <c>EffectiveStatus</c> to <c>Revoked</c> even if the database row
/// still says ACTIVE — so a merchant who doesn't track expiry
/// proactively gets cryptographically rejected on the next signed
/// request, *not* gradually-degraded.
/// </para>
///
/// <para>
/// <see cref="KeyStatusAsync"/> reads the channel's keys list and
/// surfaces the nearest-to-expiry active key as a clean shape:
/// <c>{ ActiveKeyId, ExpiresAt, DaysRemaining, EffectiveStatus, Fingerprint }</c>.
/// </para>
///
/// <para>
/// The "days remaining" math is on purpose done in the SDK — the
/// server returns the raw <c>expiresAt</c>. We want the SDK to be the
/// place a merchant's own monitoring reads from, so the integration
/// test can assert the conversion.
/// </para>
/// </summary>
public sealed class ChannelsClient
{
    private readonly Config _config;
    private readonly LinopayHttp _http;
    private readonly ChannelTokenCache _tokens;

    public ChannelsClient(Config config, LinopayHttp http, ChannelTokenCache tokens)
    {
        _config = config.Validate();
        _http = http;
        _tokens = tokens;
    }

    public async Task<ChannelKeyStatus> KeyStatusAsync(CancellationToken ct = default)
    {
        var token = await _tokens.ExchangeAsync(new[] { "invoices:read" }, ct);

        var versions = await _http.CallAsync<List<ChannelKeyVersion>>(
            $"/api/merchants/{Uri.EscapeDataString(token.MerchantId)}" +
            $"/channels/{Uri.EscapeDataString(token.ChannelId)}/keys",
            new CallOptions { Method = "GET", Token = token.AccessToken },
            ct);

        // Pick the currently-active version (EffectiveStatus=Active is
        // the field to trust — see ChannelKeyVersionResponse. The plain
        // Status is left in the wire format for back-compat but the FE
        // is told to render against EffectiveStatus).
        var active = versions.FirstOrDefault(v => v.EffectiveStatus == "Active")
                  ?? versions.FirstOrDefault(v => v.Status == "ACTIVE" && v.EffectiveStatus == "Upcoming")
                  ?? null;

        if (active is null)
        {
            return new ChannelKeyStatus(
                ActiveKeyId: "",
                ExpiresAt: null,
                DaysRemaining: null,
                EffectiveStatus: "None",
                Fingerprint: null,
                AllVersions: versions);
        }

        int? daysRemaining = null;
        if (!string.IsNullOrWhiteSpace(active.ExpiresAt) &&
            DateTimeOffset.TryParse(active.ExpiresAt, out var expires))
        {
            var now = DateTimeOffset.UtcNow;
            daysRemaining = (int)Math.Ceiling((expires - now).TotalDays);
        }

        return new ChannelKeyStatus(
            ActiveKeyId: active.KeyId,
            ExpiresAt: active.ExpiresAt,
            DaysRemaining: daysRemaining,
            EffectiveStatus: active.EffectiveStatus,
            Fingerprint: active.Fingerprint,
            AllVersions: versions);
    }
}

public sealed record ChannelKeyStatus(
    string ActiveKeyId,
    string? ExpiresAt,
    int? DaysRemaining,
    string EffectiveStatus,
    string? Fingerprint,
    IReadOnlyList<ChannelKeyVersion> AllVersions);

public sealed record ChannelKeyVersion
{
    [JsonPropertyName("id")]                      public long Id { get; set; }
    [JsonPropertyName("keyId")]                   public string KeyId { get; set; } = "";
    [JsonPropertyName("status")]                  public string Status { get; set; } = "";
    [JsonPropertyName("activatedAt")]             public string ActivatedAt { get; set; } = "";
    [JsonPropertyName("expiresAt")]               public string? ExpiresAt { get; set; }
    [JsonPropertyName("isPassphraseProtected")]   public bool IsPassphraseProtected { get; set; }
    [JsonPropertyName("fingerprint")]             public string? Fingerprint { get; set; }
    [JsonPropertyName("effectiveStatus")]         public string EffectiveStatus { get; set; } = "";
}
