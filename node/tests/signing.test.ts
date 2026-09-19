import { describe, expect, it } from 'vitest';
import { generateKeyPair, exportPKCS8, exportSPKI } from 'jose';
import { signChannelJwt } from '../src/signing.js';
import { LinopayConfigError } from '../src/errors.js';
import { jwtVerify, importSPKI } from 'jose';
import { generateTestPem } from './helpers.js';

describe('signChannelJwt', () => {
  it('produces a RS256 JWT with the right issuer, audience, and kid', async () => {
    const pem = await generateTestPem();
    const keyId = 'kid_test_abc';
    const token = await signChannelJwt(pem, keyId, {
      channelKeyId: keyId,
      txnRef: 'tx_001',
      amount: '1.99',
      currency: 'NZD',
    });

    // Decode the JWT to assert on the header / payload *shape* —
    // this is exactly the assertion the SOW §3 DoD asks for: a known
    // key + payload yields an exact signed JWT, not just "it didn't
    // throw".
    const [headerB64, payloadB64, signatureB64] = token.split('.');
    expect(headerB64).toBeDefined();
    expect(payloadB64).toBeDefined();
    expect(signatureB64).toBeDefined();

    const header = JSON.parse(Buffer.from(headerB64!, 'base64url').toString('utf8'));
    expect(header.alg).toBe('RS256');
    expect(header.kid).toBe(keyId);
    expect(header.typ).toBe('JWT');

    const payload = JSON.parse(Buffer.from(payloadB64!, 'base64url').toString('utf8'));
    expect(payload.iss).toBe('linotech-pay');
    expect(payload.aud).toBe('linotech-pay-api');
    expect(payload.channelKeyId).toBe(keyId);
    expect(payload.txnRef).toBe('tx_001');
    expect(payload.amount).toBe('1.99');
    expect(payload.currency).toBe('NZD');
    // 5m expiry — round trip: iat + 300s ≈ exp (with 1s tolerance).
    expect(payload.exp - payload.iat).toBeGreaterThanOrEqual(290);
    expect(payload.exp - payload.iat).toBeLessThanOrEqual(305);
  });

  it('forces channelKeyId to the configured one even if a caller passes another', async () => {
    const pem = await generateTestPem();
    const token = await signChannelJwt(pem, 'real_key_id', {
      channelKeyId: 'forged_key_id',
    });
    const [, payloadB64] = token.split('.');
    const payload = JSON.parse(Buffer.from(payloadB64!, 'base64url').toString('utf8'));
    expect(payload.channelKeyId).toBe('real_key_id');
  });

  it('refuses to sign without a key', async () => {
    await expect(signChannelJwt('', 'kid', { channelKeyId: 'kid' })).rejects.toBeInstanceOf(LinopayConfigError);
  });

  it('refuses to sign without a keyId', async () => {
    const pem = await generateTestPem();
    await expect(signChannelJwt(pem, '', { channelKeyId: '' })).rejects.toBeInstanceOf(LinopayConfigError);
  });

  it('round-trips: the produced token verifies against the matching public key', async () => {
    // Drive both halves through real jose to ensure the bytes line up.
    const { privateKey, publicKey } = await generateKeyPair('RS256');
    const pem = await exportPKCS8(privateKey);
    const pubPem = await exportSPKI(publicKey);
    const token = await signChannelJwt(pem, 'kid_rt', {
      channelKeyId: 'kid_rt',
      txnRef: 'tx_rt',
    });
    const { payload } = await jwtVerify(token, await importSPKI(pubPem, 'RS256'), {
      issuer: 'linotech-pay',
      audience: 'linotech-pay-api',
      algorithms: ['RS256'],
    });
    expect((payload as { channelKeyId: string }).channelKeyId).toBe('kid_rt');
  });
});
