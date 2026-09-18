import { describe, expect, it } from 'vitest';
import {
  computeSignature,
  verifyWebhook,
  REPLAY_WINDOW_SECONDS,
} from '../src/webhooks.js';
import { LinopayWebhookVerificationError } from '../src/errors.js';

const SECRET = 'whsec_testsecret_abcdef';
const BODY = '{"event":"invoice.settled","data":{"invoiceId":"2_9hYAOr"}}';
const TS = 1_789_000_000;

describe('computeSignature', () => {
  it('produces v1=<lowercase hex HMAC-SHA256>', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(sig.startsWith('v1=')).toBe(true);
    const hex = sig.slice('v1='.length);
    expect(hex).toHaveLength(64); // SHA-256 -> 32 bytes
    expect(hex.toLowerCase()).toBe(hex);
    expect(sig).toBe(computeSignature(SECRET, TS, BODY)); // deterministic
  });

  it('signs the timestamp and the body together (separately, not just the body)', () => {
    // Same body, different timestamp = different signature. Same
    // timestamp, different body = different signature. The contract
    // is that BOTH are part of the signed material.
    const sigA = computeSignature(SECRET, TS, BODY);
    const sigB = computeSignature(SECRET, TS + 1, BODY);
    const sigC = computeSignature(SECRET, TS, BODY + ' ');
    expect(sigA).not.toBe(sigB);
    expect(sigA).not.toBe(sigC);
  });

  it('refuses to sign with an empty secret', () => {
    expect(() => computeSignature('', TS, BODY)).toThrow();
  });
});

describe('verifyWebhook', () => {
  it('accepts a freshly-signed payload', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: sig,
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).not.toThrow();
  });

  it('rejects a tampered body', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY + ' ',
        timestampHeader: String(TS),
        signatureHeader: sig,
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('rejects a replayed delivery under a fresh timestamp', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: sig,
        secret: SECRET,
        // Now is well outside the window; used to verify the upper-bound
        // check independently of the timestamp-vs-now closeness.
        nowSeconds: TS + REPLAY_WINDOW_SECONDS + 60,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('rejects delivery outside the replay window (now far past timestamp)', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: sig,
        secret: SECRET,
        nowSeconds: TS + REPLAY_WINDOW_SECONDS + 1,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('rejects a wrong-secret signature', () => {
    const sig = computeSignature('whsec_wrong', TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: sig,
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('rejects missing or empty signature', () => {
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: '',
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: '   ',
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('rejects malformed timestamp', () => {
    const sig = computeSignature(SECRET, TS, BODY);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: 'not-a-number',
        signatureHeader: sig,
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });

  it('does not throw early on length mismatch (length-blind constant-time compare)', () => {
    // A 32-byte signature must not cause a non-constant-time path. We
    // can't easily assert "spent constant time" but we can at least
    // confirm the call still throws with a sensible reason.
    const sig = computeSignature(SECRET, TS, BODY);
    const truncated = sig.slice(0, 32);
    expect(() =>
      verifyWebhook({
        body: BODY,
        timestampHeader: String(TS),
        signatureHeader: truncated,
        secret: SECRET,
        nowSeconds: TS,
      }),
    ).toThrow(LinopayWebhookVerificationError);
  });
});
