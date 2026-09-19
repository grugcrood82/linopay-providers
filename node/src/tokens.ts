import type { LinopayConfig } from './config.js';
import type { HttpClient } from './http.js';
import { signChannelJwt } from './signing.js';

/**
 * Scope-limited access token cache.
 *
 * Endpoints that act on already-issued invoices (Flexi-Payment
 * create/get/cancel) take a different token from the one that's used
 * to *issue* payments (the channel-signed JWT). The exchanged token is
 * HS256-signed by LinoPay itself, has its own audience, and is short-
 * lived (5 minutes). It carries NO business payload — it only asserts
 * "this bearer may call these scopes for this one channel, for the next
 * 5 minutes."
 *
 * The exchange is `POST /v1/auth/channel-token`. The body of the
 * response is `{ accessToken, expiresIn, merchantId, channelId, scope }`
 * — the merchantId and channelId are how the SDK discovers what to
 * construct URLs against (e.g. `/api/merchants/{m}/channels/{c}/...`),
 * since the caller doesn't supply them.
 *
 * The cache here is deliberate: signing the assertion JWT is cheap-ish
 * but not free, and a sequence of invoice operations only needs to do
 * it once every 5 minutes. We re-exchange 30s before the real expiry
 * so a call never starts with a token that dies mid-flight.
 */
export interface ChannelAccessToken {
  accessToken: string;
  expiresAtMs: number;
  merchantId: string;
  channelId: string;
  scope: readonly string[];
}

export interface ExchangedToken extends ChannelAccessToken {
  fromCache: boolean;
}

const GUARD_MS = 30_000;

export class ChannelTokenCache {
  private cached: ChannelAccessToken | null = null;

  constructor(
    private readonly cfg: LinopayConfig,
    private readonly http: HttpClient,
  ) {}

  /**
   * Returns a still-valid scoped token, exchanging a fresh one if
   * needed. The returned object has a `fromCache` boolean so callers
   * (and tests) can see whether a network round-trip happened.
   */
  async exchange(scopes: readonly string[]): Promise<ExchangedToken> {
    const wanted = [...scopes].sort().join(' ');

    if (
      this.cached !== null &&
      this.cached.expiresAtMs - GUARD_MS > Date.now() &&
      [...this.cached.scope].sort().join(' ') === wanted
    ) {
      return { ...this.cached, fromCache: true };
    }

    const wantedInvoiceScope = scopes.includes('invoices:write') || scopes.includes('invoices:read');
    if (!wantedInvoiceScope) {
      // The exchange endpoint only honours these scopes today (see
      // `ChannelAccessToken.AllowedScopes` in the upstream code). A
      // future SDK release can widen this list when the upstream does.
      throw new Error(
        `Channel-token exchange: requested scope ${JSON.stringify(wanted)} is not supported by LinoPay.`,
      );
    }

    const assertion = await signChannelJwt(this.cfg.privateKeyPem, this.cfg.keyId, {
      channelKeyId: this.cfg.keyId,
    });

    const raw = await this.http.callJson<{
      accessToken: string;
      expiresIn: number;
      merchantId: string;
      channelId: string;
      scope: string[];
    }>('/v1/auth/channel-token', {
      method: 'POST',
      token: assertion,
      body: { scope: [...scopes] },
    });

    const token: ChannelAccessToken = {
      accessToken: raw.accessToken,
      expiresAtMs: Date.now() + raw.expiresIn * 1000,
      merchantId: raw.merchantId,
      channelId: raw.channelId,
      scope: raw.scope as readonly string[],
    };
    this.cached = token;
    return { ...token, fromCache: false };
  }
}
