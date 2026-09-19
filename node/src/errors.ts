/**
 * Surfaces errors that originate inside the SDK without ever leaking
 * secret material. Specifically:
 *
 *   - `LinopayApiError` carries the upstream `status`, `code`, and a
 *     sanitised message — but never the raw request body or any header
 *     that could carry the PEM, the channel token, or the Key ID.
 *
 *   - `LinopayConfigError` represents a misconfiguration (missing key,
 *     bad PEM, wrong env). The PEM is never included in the message.
 *
 *   - `LinopayWebhookVerificationError` carries the underlying reason
 *     ("missing signature", "timestamp out of window", etc.) but never
 *     the candidate signature bytes themselves.
 *
 * The never-logs-secret integration test (`tests/no-secret-leak.test.ts`)
 * asserts that this property holds across the SDK's error paths.
 */

export class LinopayApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly body: unknown;

  constructor(
    message: string,
    options: { status: number; code?: string; body?: unknown; cause?: unknown } = { status: 0 },
  ) {
    super(message);
    this.name = 'LinopayApiError';
    this.status = options.status;
    this.code = options.code;
    this.body = options.body;
    if (options.cause !== undefined) {
      (this as unknown as { cause: unknown }).cause = options.cause;
    }
  }
}

export class LinopayConfigError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'LinopayConfigError';
  }
}

export class LinopayWebhookVerificationError extends Error {
  readonly reason:
    | 'no-signature'
    | 'malformed-signature'
    | 'wrong-secret'
    | 'replay-out-of-window'
    | 'body-tampered';

  constructor(reason: LinopayWebhookVerificationError['reason'], message: string) {
    super(message);
    this.name = 'LinopayWebhookVerificationError';
    this.reason = reason;
  }
}
