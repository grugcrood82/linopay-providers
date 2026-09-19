using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Linopay.Sdk;

/// <summary>
/// HTTP plumbing for the SDK. One place that sets the right headers,
/// unwraps the <c>{ data: ... }</c> envelope that every LinoPay
/// endpoint uses, and converts non-2xx responses into a typed error.
///
/// <para>
/// Headers that MUST be set on every call:
///   - <c>Authorization: Bearer &lt;channel-signed JWT&gt;</c> for raw
///     channel instruction endpoints (one-off payments).
///   - <c>Authorization: Bearer &lt;exchanged scoped token&gt;</c> for
///     endpoints authed by the exchanged scoped token (invoices).
///   - <c>X-Channel-KeyId: &lt;keyId&gt;</c> so the LinoPay gateway can
///     look up the channel by its active key id, separately from the
///     JWT's claim.
/// </para>
/// </summary>
public sealed class LinopayHttp
{
    private readonly HttpClient _http;
    private readonly Config _config;

    /// <summary>JSON options: case-insensitive, snake_case tolerated.</summary>
    public static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public LinopayHttp(Config config, HttpMessageHandler? handler = null)
    {
        _config = config.Validate();
        _http = handler is null ? new HttpClient() : new HttpClient(handler);
    }

    /// <summary>
    /// Performs an HTTP call against <paramref name="path"/> (which is
    /// appended to the configured baseUrl) and returns the unwrapped
    /// payload.
    ///
    ///  - 2xx: returns the response body, unwrapping <c>{ data: ... }</c>
    ///    when present so callers get the inner value directly.
    ///  - non-2xx: throws <see cref="LinopayApiException"/> with the
    ///    upstream status and a sanitised message. The raw response
    ///    body is stored on <c>.Body</c> for inspection, but never
    ///    appears in the error message.
    ///  - network failure: the underlying exception is rethrown.
    /// </summary>
    public async Task<T> CallAsync<T>(string path, CallOptions options, CancellationToken ct = default)
    {
        var url = BuildUrl(_config.BaseUrl, path);
        var method = options.Method ?? "GET";

        using var req = new HttpRequestMessage(new HttpMethod(method), url);

        if (options.Token is not null)
        {
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", options.Token);
        }

        var effectiveKeyId = options.KeyIdOverride ?? _config.KeyId;
        if (string.IsNullOrWhiteSpace(effectiveKeyId))
        {
            throw new LinopayConfigException("Channel Key ID is required to make any API call.");
        }
        // Always send the Key ID alongside the token.
        req.Headers.Add("X-Channel-KeyId", effectiveKeyId);

        if (options.Body is not null)
        {
            req.Content = new StringContent(
                JsonSerializer.Serialize(options.Body, JsonOptions),
                Encoding.UTF8,
                "application/json");
        }

        HttpResponseMessage resp;
        try
        {
            resp = await _http.SendAsync(req, ct).ConfigureAwait(false);
        }
        catch (Exception)
        {
            // Don't wrap unknown errors — a merchant who sees "fetch failed"
            // knows what to do; a wrapped "LinopayApiException: fetch failed"
            // looks like our problem and isn't.
            throw;
        }

        var text = await resp.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        object? parsed = null;
        if (text.Length > 0)
        {
            try
            {
                parsed = JsonSerializer.Deserialize<JsonElement>(text, JsonOptions);
            }
            catch
            {
                // Non-JSON response. Leave parsed as null and let the
                // caller decide what to do.
            }
        }

        if (!resp.IsSuccessStatusCode)
        {
            string? code = null;
            string detail = $"{method} {url} failed with {(int)resp.StatusCode}";
            if (parsed is JsonElement obj)
            {
                if (obj.ValueKind == JsonValueKind.Object)
                {
                    if (obj.TryGetProperty("code", out var c) && c.ValueKind == JsonValueKind.String)
                        code = c.GetString();
                    else if (obj.TryGetProperty("type", out var t) && t.ValueKind == JsonValueKind.String)
                        code = t.GetString();

                    if (obj.TryGetProperty("detail", out var d) && d.ValueKind == JsonValueKind.String)
                        detail = d.GetString() ?? detail;
                    else if (obj.TryGetProperty("title", out var tt) && tt.ValueKind == JsonValueKind.String)
                        detail = tt.GetString() ?? detail;
                    else if (obj.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String)
                        detail = m.GetString() ?? detail;
                }
            }
            else if (!string.IsNullOrEmpty(text))
            {
                detail = text;
            }
            throw new LinopayApiException(detail!, (int)resp.StatusCode, code, parsed ?? text);
        }

        // `data: ...` envelope unwrap (matches the LinoPay convention).
        if (parsed is JsonElement root &&
            root.ValueKind == JsonValueKind.Object &&
            root.TryGetProperty("data", out var inner))
        {
            return JsonSerializer.Deserialize<T>(inner.GetRawText(), JsonOptions)
                   ?? throw new LinopayApiException(
                       "Response body deserialised to null after envelope unwrap.",
                       (int)resp.StatusCode);
        }
        if (parsed is JsonElement r2)
        {
            return JsonSerializer.Deserialize<T>(r2.GetRawText(), JsonOptions)
                   ?? throw new LinopayApiException(
                       "Response body deserialised to null.",
                       (int)resp.StatusCode);
        }
        // No body at all — caller wanted T, we give them default(T).
        return default!;
    }

    private static string BuildUrl(string baseUrl, string path)
    {
        var left = baseUrl.TrimEnd('/');
        var right = path.StartsWith('/') ? path : "/" + path;
        return left + right;
    }
}

public sealed class CallOptions
{
    public string? Method { get; set; }
    public object? Body { get; set; }
    public string? Token { get; set; }
    public string? KeyIdOverride { get; set; }
}
