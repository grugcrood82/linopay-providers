import { SignJWT, importPKCS8 } from 'jose';
import { LinopayConfigError } from './errors.js';

/**
 * Channel-side request signing.
 *
 * The merchant channel has its own RS256 key pair. To call certain
 * LinoPay endpoints (the ones that authorise money movement, like
 * `POST /v1/payments/qrcode`), the merchant sends a JWT signed with the
 * channel's private key. The JWT carries the request claims (amount,
 * currency, txnRef, …) and is verified server-side against the
 * channel's registered public key.
 *
 * The token shape mirrors what `lime-payments/demos/server/lino.mjs`
 * produces against the real dev API — same issuer, same audience,
 * same algorithm, same `kid` header. Keeping the bytes identical
 * matters: signed requests that don't round-trip through the same
 * verifier don't get approved.
 *
 * (Issuer and audience are import-time constants on purpose. They are
 * NOT in any cfg object — moving them onto cfg would let a caller
 * silently send tokens the server rejects.)
 */
const JWT_ISSUER = 'linotech-pay';
const JWT_AUDIENCE = 'linotech-pay-api';
const JWT_EXPIRY = '5m';

export interface ChannelJwtClaims {
  /** Always present — and matches `cfg.keyId`. The `kid` header alone
   *  is not load-bearing; the body claim is what the server audits. */
  channelKeyId: string;
  /** Idempotency / correlation token. Required for one-off payments;
   *  optional elsewhere. */
  txnRef?: string;
  /** Whole-NZD amount as a string with two decimal places, e.g.
   *  `"1.99"`. Required for one-off payments. */
  amount?: string;
  /** ISO-4217 currency code. Required for one-off payments. */
  currency?: string;
  /** Free-form merchant-supplied claims. Only used to forward to
   *  the server — nothing in the SDK inspects them. The surface stays
   *  narrow on purpose. */
  [k: string]: unknown;
}

/**
 * Signs a channel JWT with the channel's private key. Returns the
 * compact-serialised JWT. The token is short-lived (5 minutes) so a
 * leaked token stops working almost immediately.
 */
export async function signChannelJwt(
  privateKeyPem: string,
  keyId: string,
  claims: ChannelJwtClaims,
): Promise<string> {
  if (!keyId || keyId.trim() === '') {
    throw new LinopayConfigError('Channel Key ID is required to sign a request.');
  }
  if (!privateKeyPem || privateKeyPem.trim() === '') {
    throw new LinopayConfigError('Channel private key is required to sign a request.');
  }
  const key = await importPKCS8(privateKeyPem, 'RS256');

  const payload: Record<string, unknown> = { ...claims };
  // Always pin channelKeyId to the configured one — never trust the
  // caller to set it correctly. Matches `cfg.keyId` is enforced at call
  // site; this is the no-clobber default.
  payload['channelKeyId'] = keyId;

  return await new SignJWT(payload)
    .setProtectedHeader({ alg: 'RS256', kid: keyId, typ: 'JWT' })
    .setIssuer(JWT_ISSUER)
    .setAudience(JWT_AUDIENCE)
    .setIssuedAt()
    .setExpirationTime(JWT_EXPIRY)
    .sign(key);
}
