namespace Linopay.Sdk;

using System.Text.Json.Serialization;

/// <summary>
/// Scope-limited access token cache.
///
/// <para>
/// Endpoints that act on already-issued invoices (Flexi-Payment
/// create/get/cancel) take a different token from the one that's used
/// to issue payments (the channel-signed JWT). The exchanged token is
/// HS256-signed by LinoPay itself, has its own audience, and is short-
/// lived (5 minutes). It carries NO business payload — it only asserts
/// "this bearer may call these scopes for this one channel, for the
/// next 5 minutes."
/// </para>
///
/// <para>
/// The exchange is <c>POST /v1/auth/channel-token</c>. The body of the
/// response is <c>{ accessToken, expiresIn, merchantId, channelId, scope }</c>
/// — the merchantId and channelId are how the SDK discovers what to
/// construct URLs against (e.g.
/// <c>/api/merchants/{m}/channels/{c}/...</c>), since the caller
/// doesn't supply them.
/// </para>
///
/// <para>
/// The cache here is deliberate: signing the assertion JWT is cheap-ish
/// but not free, and a sequence of invoice operations only needs to do
/// it once every 5 minutes. We re-exchange 30 s before the real expiry
/// so a call never starts with a token that dies mid-flight.
/// </para>
/// </summary>
public sealed class ChannelTokenCache
{
    private readonly Config _config;
    private readonly LinopayHttp _http;
    private readonly object _lock = new();
    private ChannelAccessToken? _cached;

    public ChannelTokenCache(Config config, LinopayHttp http)
    {
        _config = config.Validate();
        _http = http;
    }

    /// <summary>
    /// Returns a still-valid scoped token, exchanging a fresh one if
    /// needed. The returned object's <see cref="ChannelAccessToken.FromCache"/>
    /// boolean is true when a cached token was reused (no network call),
    /// false when a fresh exchange happened.
    /// </summary>
    public async Task<ChannelAccessToken> ExchangeAsync(IReadOnlyList<string> scopes, CancellationToken ct = default)
    {
        var wanted = string.Join(' ', scopes.OrderBy(s => s));

        lock (_lock)
        {
            if (_cached is not null
                && new DateTimeOffset(_cached.ExpiresAtTicks, TimeSpan.Zero).Subtract(DateTimeOffset.UtcNow).TotalMilliseconds > 30_000
                && string.Join(' ', _cached.Scope.OrderBy(s => s)) == wanted)
            {
                return new ChannelAccessToken(
                    _cached.AccessToken, _cached.MerchantId, _cached.ChannelId,
                    _cached.Scope, _cached.ExpiresAtTicks, FromCache: true);
            }
        }

        var wantedHasInvoice = scopes.Any(s => s is "invoices:write" or "invoices:read");
        if (!wantedHasInvoice)
        {
            throw new LinopayApiException(
                $"Channel-token exchange: requested scope '{wanted}' is not supported by LinoPay.",
                400, "UNSUPPORTED_SCOPE");
        }

        var assertion = ChannelJwtSigner.Sign(
            _config.PrivateKeyPem, _config.KeyId,
            new Dictionary<string, object?> { ["channelKeyId"] = _config.KeyId });

        var resp = await _http.CallAsync<ChannelTokenResponse>(
            "/v1/auth/channel-token",
            new CallOptions { Method = "POST", Token = assertion, Body = new { scope = scopes.ToArray() } },
            ct);

        var nowMs = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
        var expiresAtTicks = nowMs + resp.ExpiresIn * 1000L;
        var token = new ChannelAccessToken(
            resp.AccessToken, resp.MerchantId, resp.ChannelId,
            resp.Scope, expiresAtTicks, FromCache: false);

        lock (_lock)
        {
            _cached = token;
        }
        return token;
    }

    // Plain DTOs that match the upstream's response shape exactly.
    private sealed class ChannelTokenResponse
    {
        [JsonPropertyName("accessToken")]
        public string AccessToken { get; set; } = "";

        [JsonPropertyName("expiresIn")]
        public int ExpiresIn { get; set; }

        [JsonPropertyName("merchantId")]
        public string MerchantId { get; set; } = "";

        [JsonPropertyName("channelId")]
        public string ChannelId { get; set; } = "";

        [JsonPropertyName("scope")]
        public string[] Scope { get; set; } = Array.Empty<string>();
    }
}

public sealed record ChannelAccessToken(
    string AccessToken,
    string MerchantId,
    string ChannelId,
    IReadOnlyList<string> Scope,
    long ExpiresAtTicks,
    bool FromCache);
