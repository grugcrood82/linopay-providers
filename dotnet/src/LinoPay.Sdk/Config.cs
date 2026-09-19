namespace Linopay.Sdk;

/// <summary>
/// SDK configuration. Construct one per-process and pass it to
/// <see cref="LinopayClient.Create"/>.
///
/// <para>
/// The merchant constructs this once and passes it to every SDK call.
/// It carries the channel's signing key (Key ID + PEM), the API base
/// URL, the default bank code, and the environment.
/// </para>
///
/// <para>
/// Sandbox vs. live must be explicit (SOW §2.2). The SDK never infers
/// it from the shape of the key or from an ambient env var the caller
/// didn't set themselves.
/// </para>
/// </summary>
public sealed record Config
{
    /// <summary>Base URL for the LinoPay merchant API. For the WireMock
    /// test sandbox this is <c>http://localhost:8080</c>; for production
    /// the published LinoPay merchant API host.</summary>
    public required string BaseUrl { get; init; }

    /// <summary>Channel Key ID. Sent as <c>X-Channel-KeyId</c> on every
    /// request and used as the <c>kid</c> header on the channel-signed
    /// instruction JWT.</summary>
    public required string KeyId { get; init; }

    /// <summary>The channel's RSA private key in PEM form. The SDK
    /// holds it in memory only — never logged, never written to disk,
    /// never returned through an error message (SOW §2.1).</summary>
    public required string PrivateKeyPem { get; init; }

    /// <summary>Default bank code (e.g. <c>ANZ</c>, <c>ASB</c>, <c>BNZ</c>,
    /// <c>KIWIBANK</c>, <c>WESTPAC</c>) for one-off payments. Optional;
    /// can be overridden per call.</summary>
    public string? BankCode { get; init; }

    /// <summary>Either <c>"sandbox"</c> or <c>"live"</c>. Required.
    /// Surfaced in error messages so a merchant who fires from the
    /// wrong environment knows immediately.</summary>
    public required string Environment { get; init; }

    /// <summary>Optional explicit logger. If omitted, the SDK writes
    /// nothing to stdout/stderr — the only way secret material could
    /// leak from logging is via a consumer-supplied logger, so the SDK
    /// never has one by default.</summary>
    public ILogger? Logger { get; init; }

    public Config Validate()
    {
        if (string.IsNullOrWhiteSpace(BaseUrl))
            throw new LinopayConfigException("BaseUrl is required.");
        if (string.IsNullOrWhiteSpace(KeyId))
            throw new LinopayConfigException("KeyId is required.");
        if (string.IsNullOrWhiteSpace(PrivateKeyPem))
            throw new LinopayConfigException("PrivateKeyPem is required.");
        if (Environment is not ("sandbox" or "live"))
            throw new LinopayConfigException(
                $"Environment must be 'sandbox' or 'live' (got '{Environment}').");
        return this;
    }
}

/// <summary>
/// Optional logger interface. Consumer chooses where output goes.
/// The default null logger does nothing — secret material never
/// leaves the SDK via logging.
/// </summary>
public interface ILogger
{
    void Info(string message, IReadOnlyDictionary<string, object?>? meta = null);
    void Warn(string message, IReadOnlyDictionary<string, object?>? meta = null);
    void Error(string message, IReadOnlyDictionary<string, object?>? meta = null);
}

/// <summary>
/// Logger that throws every log line away. Used when the caller does
/// not supply one. Not exported on the public surface.
/// </summary>
internal sealed class NullLogger : ILogger
{
    public void Info(string message, IReadOnlyDictionary<string, object?>? meta = null) { }
    public void Warn(string message, IReadOnlyDictionary<string, object?>? meta = null) { }
    public void Error(string message, IReadOnlyDictionary<string, object?>? meta = null) { }
}
