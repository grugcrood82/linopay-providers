import { describe, expect, it } from 'vitest';
import { ChannelTokenCache } from '../src/tokens.js';
import type { HttpClient } from '../src/http.js';
import { generateTestPem } from './helpers.js';

function mockHttp(responses: Array<{ accessToken: string; expiresIn: number; merchantId: string; channelId: string; scope: string[] }>): HttpClient {
  let idx = 0;
  return {
    callJson: async () => {
      // The mock here stands in for the real HttpClient.callJson,
      // which has already unwrapped any `{ data: ... }` envelope.
      // So we return the inner row directly.
      const r = responses[idx++] ?? responses[0];
      if (!r) throw new Error('Mock exhausted.');
      return r;
    },
  } as HttpClient;
}

describe('ChannelTokenCache', () => {
  it('exchanges an unsigned assertion for a scoped token on first call', async () => {
    const pem = await generateTestPem();
    const http = mockHttp([
      {
        accessToken: 'tok_1',
        expiresIn: 300,
        merchantId: 'merchant_test',
        channelId: 'channel_test',
        scope: ['invoices:write'],
      },
    ]);
    const cache = new ChannelTokenCache(
      {
        baseUrl: 'http://x',
        keyId: 'kid',
        privateKeyPem: pem,
        environment: 'sandbox',
      },
      http,
    );
    const t = await cache.exchange(['invoices:write']);
    expect(t.fromCache).toBe(false);
    expect(t.accessToken).toBe('tok_1');
  });

  it('reuses a cached token within the expiry guard window', async () => {
    const pem = await generateTestPem();
    const http = mockHttp([
      {
        accessToken: 'tok_1',
        expiresIn: 300,
        merchantId: 'm',
        channelId: 'c',
        scope: ['invoices:write'],
      },
    ]);
    const cache = new ChannelTokenCache(
      { baseUrl: 'http://x', keyId: 'k', privateKeyPem: pem, environment: 'sandbox' },
      http,
    );
    const a = await cache.exchange(['invoices:write']);
    const b = await cache.exchange(['invoices:write']);
    expect(a.fromCache).toBe(false);
    expect(b.fromCache).toBe(true);
    expect(b.accessToken).toBe(a.accessToken);
  });

  it('rejects unsupported scopes', async () => {
    const pem = await generateTestPem();
    const http = mockHttp([]);
    const cache = new ChannelTokenCache(
      { baseUrl: 'http://x', keyId: 'k', privateKeyPem: pem, environment: 'sandbox' },
      http,
    );
    await expect(cache.exchange(['payments:write'])).rejects.toThrow();
  });
});
