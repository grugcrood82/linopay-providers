namespace Linopay.Sdk;

/// <summary>
/// Error types the SDK can surface. Deliberately carry no secret
/// material — a PEM, a webhook signing secret, or a kid never appears
/// in any of these messages. The no-secret-leak integration test
/// pins that property.
/// </summary>
public class LinopayApiException : Exception
{
    public int Status { get; }
    public string? Code { get; }
    public object? Body { get; }

    public LinopayApiException(string message, int status, string? code = null, object? body = null)
        : base(message)
    {
        Status = status;
        Code = code;
        Body = body;
    }
}

public class LinopayConfigException : Exception
{
    public LinopayConfigException(string message) : base(message) { }
}

public class LinopayWebhookVerificationException : Exception
{
    /// <summary>Stable identifier of what went wrong. One of the
    /// documented values: <c>no-signature</c>, <c>malformed-signature</c>,
    /// <c>wrong-secret</c>, <c>replay-out-of-window</c>, <c>body-tampered</c>.</summary>
    public string Reason { get; }

    public LinopayWebhookVerificationException(string reason, string message)
        : base(message)
    {
        Reason = reason;
    }
}
