<?php

declare(strict_types=1);

namespace Linotech\Sdk;

/**
 * Surfaces errors that originate inside the SDK without ever leaking
 * secret material — a PEM, a webhook signing secret, or a Key ID never
 * appears in any of these messages. The `no-secret-leak` integration
 * test pins that property.
 */
class LinopayApiException extends \RuntimeException
{
    public readonly string $errorCode;     // renamed from $code to avoid shadowing Exception::$code
    public readonly mixed $body;

    public function __construct(
        string $message,
        public readonly int $status,
        ?string $errorCode = null,
        mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode ?? '';
        $this->body = $body;
    }

    /** Convenience: returns the upstream error code, e.g. "CHANNEL_NOT_FOUND", or null if none was returned. */
    public function upstreamCode(): ?string
    {
        return $this->errorCode !== '' ? $this->errorCode : null;
    }
}

class LinopayConfigException extends \InvalidArgumentException
{
}

class LinopayWebhookVerificationException extends \RuntimeException
{
    public const REASON_NO_SIGNATURE = 'no-signature';
    public const REASON_MALFORMED_SIGNATURE = 'malformed-signature';
    public const REASON_WRONG_SECRET = 'wrong-secret';
    public const REASON_REPLAY_OUT_OF_WINDOW = 'replay-out-of-window';
    public const REASON_BODY_TAMPERED = 'body-tampered';

    public readonly string $reason;

    public function __construct(
        string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }
}
