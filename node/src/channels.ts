import type { HttpClient } from './http.js';
import type { ChannelTokenCache } from './tokens.js';
import type { LinopayConfig } from './config.js';

/**
 * Channel-key lifecycle helper.
 *
 * The merchant channel has a rotating RSA key. The active key has a
 * fixed expiry date (computed at rotation time: now + 12 months by
 * default — see `ChannelKeyRotationPolicy`). After the expiry, the
 * LinoPay gateway flips the row's `EffectiveStatus` to `Revoked` even
 * if the database row still says ACTIVE — so a merchant who doesn't
 * track expiry proactively gets cryptographically rejected on the
 * next signed request, *not* gradually-degraded.
 *
 * `keyStatus()` reads the channel's keys list and surfaces the
 * nearest-to-expiry active key as a clean shape:
 *   { activeKeyId, expiresAt, daysRemaining, effectiveStatus, fingerprint }
 *
 * The "days remaining" math is on purpose done in the SDK — the server
 * returns the raw `expiresAt`. We want the SDK to be the place a
 * merchant's own monitoring reads from, so the integration test can
 * assert the conversion.
 */
export interface ChannelKeyStatus {
  activeKeyId: string;
  expiresAt: string | null;
  daysRemaining: number | null;
  effectiveStatus: string;
  fingerprint: string | null;
  allVersions: ReadonlyArray<ChannelKeyVersion>;
}

export interface ChannelKeyVersion {
  id: number;
  keyId: string;
  status: string;
  activatedAt: string;
  expiresAt: string | null;
  isPassphraseProtected: boolean;
  fingerprint: string | null;
  effectiveStatus: string;
}

export interface ChannelsClient {
  keyStatus(): Promise<ChannelKeyStatus>;
}

export function createChannelsClient(
  _cfg: LinopayConfig,
  http: HttpClient,
  tokens: ChannelTokenCache,
): ChannelsClient {
  void _cfg; // reserved for per-channel explicit-merchant-id overrides
  return {
    async keyStatus(): Promise<ChannelKeyStatus> {
      // The keys list endpoint requires a real merchant identity check
      // upstream — OrgOwnership.RequireOrgOwnership reads the caller's
      // identity from the request. We use the exchanged token (which
      // carries merchantId) for that.
      const token = await tokens.exchange(['invoices:read']);

      type Response = ChannelKeyVersion[];
      const allVersions = await http.callJson<Response>(
        `/api/merchants/${encodeURIComponent(token.merchantId)}` +
          `/channels/${encodeURIComponent(token.channelId)}/keys`,
        { method: 'GET', token: token.accessToken },
      );

      // Pick the currently-active version (EffectiveStatus=Active is
      // the field to trust — see ChannelKeyVersionResponse. The plain
      // Status is left in the wire format for back-compat but the FE
      // is told to render against EffectiveStatus).
      const active = allVersions.find((v) => v.effectiveStatus === 'Active')
        ?? allVersions.find((v) => v.status === 'ACTIVE' && v.effectiveStatus === 'Upcoming')
        ?? null;

      if (active === null) {
        return {
          activeKeyId: '',
          expiresAt: null,
          daysRemaining: null,
          effectiveStatus: 'None',
          fingerprint: null,
          allVersions,
        };
      }

      const daysRemaining = active.expiresAt
        ? daysBetween(new Date(active.expiresAt), new Date())
        : null;

      return {
        activeKeyId: active.keyId,
        expiresAt: active.expiresAt,
        daysRemaining,
        effectiveStatus: active.effectiveStatus,
        fingerprint: active.fingerprint,
        allVersions,
      };
    },
  };
}

function daysBetween(target: Date, now: Date): number {
  const ms = target.getTime() - now.getTime();
  return Math.ceil(ms / (24 * 60 * 60 * 1000));
}
