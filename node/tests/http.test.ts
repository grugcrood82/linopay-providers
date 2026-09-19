import { describe, expect, it } from 'vitest';
import { createHttpClient } from '../src/http.js';
import { LinopayApiError } from '../src/errors.js';
import { generateTestPem } from './helpers.js';

/**
 * Verifies the HTTP wrapper's contract without going anywhere near the
 * network. We hand-wire fetch by replacing it for the duration of each
 * test, then restore.
 */
describe('http client', () => {
  const cfg = {
    baseUrl: 'http://example.test',
    keyId: 'kid_http',
    privateKeyPem: '',
    environment: 'sandbox' as const,
  };

  const original = globalThis.fetch;

  function withFetch(
    handler: (url: string, init: RequestInit) => Promise<Response>,
    fn: () => Promise<void>,
  ): Promise<void> {
    (globalThis as { fetch: typeof fetch }).fetch = (url, init) =>
      handler(String(url), init!) as unknown as Promise<Response>;
    return fn().finally(() => {
      (globalThis as { fetch: typeof fetch }).fetch = original;
    });
  }

  it('sets the right Authorization and X-Channel-KeyId headers on every call', async () => {
    await withFetch(async (_url, init) => {
      const headers = (init.headers ?? {}) as Record<string, string>;
      expect(headers['Authorization']).toBe('Bearer my-test-token');
      expect(headers['X-Channel-KeyId']).toBe('kid_http');
      // Content-Type is only set when there's a body. This test sends
      // a GET with no body, so no Content-Type is expected.
      expect(headers['Content-Type']).toBeUndefined();
      return new Response(JSON.stringify({ data: { ok: true } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }, async () => {
      const http = createHttpClient(cfg);
      const result = await http.callJson<{ ok: boolean }>('/v1/test', {
        method: 'GET',
        token: 'my-test-token',
      });
      expect(result.ok).toBe(true);
    });
  });

  it('sets Content-Type=application/json when a body is sent', async () => {
    await withFetch(async (_url, init) => {
      const headers = (init.headers ?? {}) as Record<string, string>;
      expect(headers['Content-Type']).toBe('application/json');
      return new Response(JSON.stringify({ data: { ok: true } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }, async () => {
      const http = createHttpClient(cfg);
      await http.callJson('/v1/test', {
        method: 'POST',
        token: 't',
        body: { hello: 'world' },
      });
    });
  });

  it('unwraps a { data: ... } envelope', async () => {
    await withFetch(async () => {
      return new Response(JSON.stringify({ data: { x: 1, y: 2 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }, async () => {
      const http = createHttpClient(cfg);
      const result = await http.callJson<{ x: number; y: number }>('/v1/test', { token: 't' });
      expect(result.x).toBe(1);
      expect(result.y).toBe(2);
    });
  });

  it('returns non-enveloped responses as-is', async () => {
    await withFetch(async () => {
      return new Response(JSON.stringify({ transactionSagaId: 'abc', status: 'Pending' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }, async () => {
      const http = createHttpClient(cfg);
      const result = await http.callJson<{ transactionSagaId: string; status: string }>(
        '/v1/payments/qrcode',
        { token: 't' },
      );
      expect(result.transactionSagaId).toBe('abc');
      expect(result.status).toBe('Pending');
    });
  });

  it('throws LinopayApiError on a non-2xx with sanitised code/detail', async () => {
    await withFetch(async () => {
      return new Response(
        JSON.stringify({ code: 'CHANNEL_NOT_FOUND', message: 'Channel not found.' }),
        { status: 404, headers: { 'Content-Type': 'application/json' } },
      );
    }, async () => {
      const http = createHttpClient(cfg);
      let caught: unknown = null;
      try {
        await http.callJson('/v1/test', { token: 't' });
      } catch (e) {
        caught = e;
      }
      expect(caught).toBeInstanceOf(LinopayApiError);
      const e = caught as LinopayApiError;
      expect(e.status).toBe(404);
      expect(e.code).toBe('CHANNEL_NOT_FOUND');
      expect(e.message).toBe('Channel not found.');
    });
  });

  it('throws LinopayApiError on a non-2xx with RFC 7807 detail', async () => {
    await withFetch(async () => {
      return new Response(
        JSON.stringify({ type: 'about:blank', title: 'Not Found', detail: 'Wrong channel id.' }),
        { status: 404, headers: { 'Content-Type': 'application/json' } },
      );
    }, async () => {
      const http = createHttpClient(cfg);
      let caught: unknown = null;
      try {
        await http.callJson('/v1/test', { token: 't' });
      } catch (e) {
        caught = e;
      }
      const e = caught as LinopayApiError;
      expect(e.status).toBe(404);
      expect(e.code).toBe('about:blank');
      // HTTP wrapper prefers `detail` over `title` — RFC 7807's
      // `detail` is the human-readable explanation; `title` is the
      // summary type. Either is a sensible message; we surface the
      // more specific field.
      expect(e.message).toBe('Wrong channel id.');
    });
  });

  it('refuses to send without a key id', async () => {
    const http = createHttpClient({ ...cfg, keyId: '' });
    await expect(http.callJson('/v1/test', { token: 't' })).rejects.toThrow();
  });
});
