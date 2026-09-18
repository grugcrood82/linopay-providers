import {
  createHmac,
  timingSafeEqual,
} from 'node:crypto';
import {
  LinopayWebhookVerificationError,
} from './errors.js';

/**
 * Webhook signature scheme — HMAC-SHA256, version-prefixed.
 *
 * This is the mirror implementation of LinoPay's outbound-webhook
 * signer (C#: `shared/LinoPay.Application/Abstractions/WebhookSignature.cs`).
 * A merchant who receives a webhook verifies it by:
 *
 *   1. Reading the `X-LinoPay-Timestamp` and `X-LinoPay-Signature`
 *      headers.
 *   2. Constructing the signed payload `${timestamp}.${rawBody}`
 *      (timestamp is signed AS WELL AS sent — changing either
 *      invalidates the signature, which kills replay attacks).
 *   3. Recomputing HMAC-SHA256(secret, payload) and comparing the
 *      hex digest to the `v1=...` value in `X-LinoPay-Signature` in
 *      CONSTANT TIME (a byte-by-byte early return leaks the position
 *      of the first mismatch — enough to recover a signature).
 *
 * The constant-time compare is on purpose: Node's `crypto.timingSafeEqual`
 * runs in fixed time regardless of input length, which is what we want.
 *
 * Replay window: 5 minutes. A delivery older than that should be
 * rejected even if the signature itself checks out (mirrors
 * `WebhookSignature.ReplayWindow` upstream).
 */
export const SIGNATURE_HEADER = 'X-LinoPay-Signature';
export const TIMESTAMP_HEADER = 'X-LinoPay-Timestamp';
export const SCHEME_VERSION = 'v1';
export const REPLAY_WINDOW_SECONDS = 300;

export interface VerifyInput {
  /**
   * The raw HTTP request body as a string (NOT a re-serialised JSON
   * object — re-serialising changes whitespace and key ordering, both
   * of which break the signature).
   */
  body: string;
  /**
   * The `X-LinoPay-Timestamp` header value, as a Unix-seconds string.
   */
  timestampHeader: string;
  /**
   * The `X-LinoPay-Signature` header value (`v1=<hex>`).
   */
  signatureHeader: string;
  /**
   * The shared signing secret (`whsec_...`).
   */
  secret: string;
  /**
   * Override the default 5-minute replay window. Tests use this; so
   * does anything pulling a "now" from a controlled clock.
   */
  nowSeconds?: number;
}

/**
 * Verifies a webhook delivery. Throws
 * {@link LinopayWebhookVerificationError} on any mismatch; returns
 * `undefined` on success (a `boolean` would be more conventional but
 * the throw carries the *reason*, which is the data a merchant's own
 * handler logs — and we don't want them constructing their own message).
 */
export function verifyWebhook(input: VerifyInput): void {
  if (!input.signatureHeader || input.signatureHeader.trim() === '') {
    throw new LinopayWebhookVerificationError(
      'no-signature',
      'No signature header was supplied.',
    );
  }
  const ts = Number.parseInt(input.timestampHeader, 10);
  if (!Number.isFinite(ts)) {
    throw new LinopayWebhookVerificationError(
      'no-signature',
      'Timestamp header is missing or not a Unix-seconds integer.',
    );
  }
  if (input.secret === '') {
    throw new LinopayWebhookVerificationError(
      'wrong-secret',
      'A non-empty signing secret is required to verify a webhook.',
    );
  }

  // Signature check FIRST, then replay-window check. A merchant who
  // forges a signature deserves a `wrong-secret` reason regardless of
  // the timestamp freshness; the replay check is a defence against an
  // attacker replaying a *valid* signature, not against forging one.
  const expected = computeSignature(input.secret, ts, input.body);

  // Constant-time compare. Node's timingSafeEqual requires equal-length
  // buffers — pad the shorter side so a wrong-length candidate can't
  // crash the comparison and isn't short-circuited either.
  const a = Buffer.from(expected, 'utf8');
  let b = Buffer.from(input.signatureHeader, 'utf8');
  if (b.length !== a.length) {
    // Construct a same-length junk buffer for the safe-equal. We
    // already know this is a mismatch, but we need to spend the
    // constant time so we don't leak length info via timing.
    b = Buffer.alloc(a.length);
  }
  if (!timingSafeEqual(a, b)) {
    throw new LinopayWebhookVerificationError(
      'wrong-secret',
      'Webhook signature did not match.',
    );
  }

  const now = input.nowSeconds ?? Math.floor(Date.now() / 1000);
  if (Math.abs(now - ts) > REPLAY_WINDOW_SECONDS) {
    throw new LinopayWebhookVerificationError(
      'replay-out-of-window',
      `Timestamp ${ts} is outside the ${REPLAY_WINDOW_SECONDS}-second replay window (now=${now}).`,
    );
  }
}

/**
 * Computes the signing payload `${timestamp}.${body}` and returns
 * `v1=<lowercase hex HMAC-SHA256>`. Exported so a merchant's own test
 * suite can verify against the same byte-exact construction the SDK
 * uses for verification — recreating the spec from the verifier is a
 * classic source of integration bugs.
 */
export function computeSignature(secret: string, unixSeconds: number, body: string): string {
  if (secret === '') {
    throw new Error('computeSignature requires a non-empty secret.');
  }
  const payload = `${unixSeconds.toString(10)}.${body}`;
  const mac = createHmac('sha256', secret).update(payload, 'utf8').digest('hex');
  return `${SCHEME_VERSION}=${mac}`;
}
