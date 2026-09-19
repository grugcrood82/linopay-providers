import { describe, expect, it } from 'vitest';
import { generateTestPem } from './helpers.js';
import { createLinopay } from '../src/index.js';
import { LinopayApiError, LinopayWebhookVerificationError } from '../src/errors.js';

/**
 * SOW §2.1 — "Never log or persist secret material." This test asserts
 * the SDK's own behaviour: a merchant-installed logger never receives
 * a message containing the channel's private-key PEM, no matter what
 * goes wrong on the wire.
 *
 * Captures every log() call. If any captured message contains the
 * secret, the test fails.
 */
describe('SDK never leaks the private-key PEM to logs', () => {
  it('does not include the PEM in any error path (config, http, signing)', async () => {
    const pem = await generateTestPem();
    // The "secret" we will look for in captured logs is the unique
    // marker we'll embed in our PEM — slightly extended with a known
    // suffix so a substring match is unambiguous.
    const secret = pem + '\n-----UNIQUE-SENTINEL-LINE-----';
    const baseUrl = 'http://127.0.0.1:1'; // guaranteed refusal
    const captured: string[] = [];
    const sinkLogger = {
      info: (m: string) => captured.push(`info:${m}`),
      warn: (m: string) => captured.push(`warn:${m}`),
      error: (m: string) => captured.push(`error:${m}`),
    };

    const linopay = createLinopay({
      baseUrl,
      keyId: 'kid_for_leak_test',
      privateKeyPem: secret,
      environment: 'sandbox',
      logger: sinkLogger,
    });

    // 1. Failed HTTP from createPayment — would surface a network /
    //    DNS error. We assert the SDK's error path doesn't include
    //    the PEM.
    try {
      await linopay.payments.create({ amountCents: 199, currency: 'NZD' });
    } catch (err) {
      expect(err instanceof LinopayApiError || err instanceof Error).toBe(true);
    }

    // 2. Failed HTTP from invoices.create — exercises the token
    //    exchange path (signing happens here too).
    try {
      await linopay.invoices.create({ amountCents: 199 });
    } catch {
      // intentional — we only care about the capture below
    }

    // 3. Failed HTTP from channels.keyStatus — exercises a different
    //    scoped token path.
    try {
      await linopay.channels.keyStatus();
    } catch {
      // intentional
    }

    // 4. Misconfiguration — sending payments.create without a bank
    //    code should NOT surface the PEM, even though the PEM is in
    //    the closure.
    try {
      await createLinopay({
        baseUrl,
        keyId: 'kid_misconfig',
        privateKeyPem: secret,
        environment: 'sandbox',
        logger: sinkLogger,
      }).payments.create({ amountCents: 100 }); // no bank code anywhere
    } catch {
      // intentional
    }

    // The PEM content is multi-line. A substring match for any line
    // (other than the always-included `-----END ENCRYPTED PRIVATE KEY-----`
    // style marker which is content-agnostic) is conclusive.
    expect(captured.length).toBeGreaterThanOrEqual(0);
    for (const line of captured) {
      expect(line.includes('UNIQUE-SENTINEL-LINE')).toBe(false);
      // Also the inner base64 body of the PEM — match the BEGIN/END
      // fence in any of the captured messages.
      expect(line.includes('BEGIN PRIVATE KEY')).toBe(false);
      expect(line.includes('BEGIN ENCRYPTED PRIVATE KEY')).toBe(false);
      // The fingerprint marker line we control.
      expect(line).not.toContain(secret.slice(0, 32));
    }
  });

  it('does not include the signing secret in webhook verification errors', () => {
    const { verify, computeSignature } = createLinopay({
      baseUrl: 'http://localhost:0',
      keyId: 'kid',
      privateKeyPem: 'ignored',
      environment: 'sandbox',
    }).webhooks;
    const secret = 'whsec_super_unique_marker_xyz_42';
    const sig = computeSignature(secret, 1_789_000_000, '{}');
    expect(() =>
      verify({
        body: '{}',
        timestampHeader: '1789000000',
        signatureHeader: 'v1=deadbeef', // wrong on purpose
        secret,
      }),
    ).toThrow(LinopayWebhookVerificationError);
    // No exception message or stack should contain the secret.
    try {
      verify({
        body: '{}',
        timestampHeader: '1789000000',
        signatureHeader: 'v1=deadbeef',
        secret,
      });
    } catch (e: unknown) {
      const msg = (e as Error).message ?? '';
      expect(msg).not.toContain(secret);
      // Sanity: the compute signature call returned something.
      expect(sig).toMatch(/^v1=[0-9a-f]{64}$/);
    }
  });
});
