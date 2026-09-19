import { signChannelJwt } from './signing.js';
import { LinopayApiError, LinopayConfigError } from './errors.js';
import type { LinopayConfig } from './config.js';

/**
 * HTTP plumbing for the SDK. One place that sets the right headers,
 * unwraps the `{ data: ... }` envelope that every LinoPay endpoint
 * uses, and converts non-2xx responses into a typed error.
 *
 * Headers that MUST be set on every call to a channel-authenticated
 * endpoint:
 *   - `Authorization: Bearer <channel-signed JWT>` for raw channel
 *     instruction endpoints (one-off payments)
 *   - `X-Channel-KeyId: <keyId>` so the LinoPay gateway can look up the
 *     channel by its active key id, separately from the JWT's claim
 *   - `Authorization: Bearer <exchanged scoped token>` for endpoints
 *     authed by the exchanged scoped token (invoices)
 *
 * The two `Authorization` shapes come from two different token types —
 * they are not interchangeable. See {@link tokens} for the exchange.
 */
export interface CallJsonOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Bearer token to send as `Authorization`. Either a channel-signed
   *  JWT, or an exchanged scoped access token. */
  token?: string;
  /** Override the configured Key ID for this one call. Rarely used. */
  keyIdOverride?: string;
}

export interface HttpClient {
  /**
   * Performs an HTTP call against `path` (which is appended to the
   * configured `baseUrl`) and returns the unwrapped payload.
   *
   * - 2xx: returns the response body, unwrapping `{ data: ... }` if
   *   present so callers get the inner value directly.
   * - non-2xx: throws {@link LinopayApiError} with the upstream status
   *   and a sanitised message. The raw response body is stored on
   *   `.body` for inspection, but never appears in the error message.
   * - network failure: throws the underlying `TypeError` (the Node
   *   fetch reject). Callers see real connection errors rather than
   *   masked ones.
   */
  callJson<T = unknown>(path: string, options?: CallJsonOptions): Promise<T>;
}

export function createHttpClient(cfg: LinopayConfig): HttpClient {
  return {
    async callJson<T>(path: string, options: CallJsonOptions = {}): Promise<T> {
      const url = buildUrl(cfg.baseUrl, path);
      const method = options.method ?? 'GET';
      const headers: Record<string, string> = {};
      // Default Accept for JSON. Content-Type only when there's a body.
      headers['Accept'] = 'application/json';

      const effectiveKeyId = options.keyIdOverride ?? cfg.keyId;
      if (!effectiveKeyId) {
        throw new LinopayConfigError('Channel Key ID is required to make any API call.');
      }
      if (options.token) {
        headers['Authorization'] = `Bearer ${options.token}`;
      }
      // Always send the Key ID alongside the token — the LinoPay
      // gateway reads it independently of the JWT's `kid` claim so
      // that a key rotation's overlap window (old token + new key id)
      // resolves cleanly.
      headers['X-Channel-KeyId'] = effectiveKeyId;

      const init: RequestInit = { method, headers };
      if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
      }

      let resp: Response;
      try {
        resp = await fetch(url, init);
      } catch (err) {
        // Do not wrap unknown errors — a merchant who sees "fetch failed"
        // knows what to do; a wrapped "LinopayApiError: fetch failed"
        // looks like our problem and isn't.
        throw err;
      }

      const text = await resp.text();
      let parsed: unknown = null;
      if (text.length > 0) {
        try {
          parsed = JSON.parse(text);
        } catch {
          // Non-JSON response. Leave parsed as null and let the caller
          // decide what to do.
        }
      }

      if (!resp.ok) {
        // The LinoPay error envelope:
        //   { code: string, message: string }   (SDK-style)
        //   { type: string, title: string, detail: string } (RFC 7807-ish)
        const obj = (parsed ?? {}) as Record<string, unknown>;
        const code =
          typeof obj['code'] === 'string'
            ? (obj['code'] as string)
            : typeof obj['type'] === 'string'
              ? (obj['type'] as string)
              : undefined;
        const detail =
          typeof obj['detail'] === 'string'
            ? (obj['detail'] as string)
            : typeof obj['title'] === 'string'
              ? (obj['title'] as string)
              : typeof obj['message'] === 'string'
                ? (obj['message'] as string)
                : `${method} ${url} failed with ${resp.status}`;
        throw new LinopayApiError(detail, {
          status: resp.status,
          ...(code !== undefined ? { code } : {}),
          ...(parsed !== null ? { body: parsed } : { body: text }),
        });
      }

      // `data: ...` envelope unwrap: matches the LinoPay convention
      // (see `lime-payments/demos/server/lino.mjs`: `const body =
      // data.data ?? data`). If the response isn't wrapped, return it
      // as-is.
      if (parsed && typeof parsed === 'object' && 'data' in (parsed as Record<string, unknown>)) {
        return (parsed as { data: T }).data;
      }
      return parsed as T;
    },
  };
}

function buildUrl(baseUrl: string, path: string): string {
  const left = baseUrl.replace(/\/+$/, '');
  const right = path.startsWith('/') ? path : `/${path}`;
  return `${left}${right}`;
}

/**
 * Convenience helper: signs a raw channel instruction JWT for one call.
 * The fetch wrapper itself does no signing; this composes signing + the
 * HTTP call.
 */
export async function callJsonWithChannelJwt<T>(
  cfg: LinopayConfig,
  http: HttpClient,
  path: string,
  body: unknown,
  extraClaims: Record<string, unknown> = {},
): Promise<T> {
  const token = await signChannelJwt(cfg.privateKeyPem, cfg.keyId, {
    ...extraClaims,
    channelKeyId: cfg.keyId,
  });
  return http.callJson<T>(path, {
    method: 'POST',
    token,
    body,
  });
}
