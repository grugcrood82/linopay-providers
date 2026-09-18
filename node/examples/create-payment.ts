/**
 * Runnable example: create a one-off payment against a LinoPay-compatible
 * API. This file IS the "every SDK ships with a working example" from
 * SOW §2.5 — a developer can run it against the containerized WireMock
 * sandbox with zero host setup beyond `docker compose up`.
 *
 * Usage (against the WireMock sandbox):
 *   LINOPAY_BASE_URL=http://localhost:18080 \
 *   LINOPAY_KEY_ID=kid_demo \
 *   LINOPAY_PRIVATE_KEY_PEM_PATH=./.demo-key.pem \
 *   LINOPAY_ENVIRONMENT=sandbox \
 *   LINOPAY_BANK_CODE=ANZ \
 *   npx tsx examples/create-payment.ts
 *
 * The script writes a friendly summary rather than dumping the whole
 * response object — that's the audience this is for.
 */
import { generateKeyPair, exportPKCS8 } from 'jose';
import { writeFileSync } from 'node:fs';
import { createLinopay } from '../src/index.js';

async function main(): Promise<void> {
  // The sandbox doesn't care which key pair we present — the token
  // exchange endpoint in WireMock returns a stub token regardless.
  // For a real LinoPay exchange, the public half must be on the
  // channel's record; this generates a throwaway one for the demo.
  const pemPath = process.env['LINOPAY_PRIVATE_KEY_PEM_PATH'] ?? './.demo-key.pem';
  const { privateKey } = await generateKeyPair('RS256', { extractable: true });
  const pem = await exportPKCS8(privateKey);
  writeFileSync(pemPath, pem, { mode: 0o600 });

  // For a local WireMock run the demo also lets you override
  // LINOPAY_BASE_URL=http://localhost:18080 (the published host port
  // from docker-compose.test.yml).
  const linopay = createLinopay({
    baseUrl: process.env['LINOPAY_BASE_URL'] ?? 'http://localhost:18080',
    keyId: process.env['LINOPAY_KEY_ID'] ?? 'kid_demo',
    privateKeyPem: pem,
    bankCode: process.env['LINOPAY_BANK_CODE'] ?? 'ANZ',
    environment: 'sandbox',
  });

  const amountCents = Number.parseInt(process.env['LINOPAY_AMOUNT_CENTS'] ?? '199', 10);
  if (!Number.isFinite(amountCents) || amountCents <= 0) {
    throw new Error('LINOPAY_AMOUNT_CENTS must be a positive integer (in cents).');
  }

  // eslint-disable-next-line no-console
  console.warn(`Creating a one-off payment of ${(amountCents / 100).toFixed(2)} NZD ...`);
  const payment = await linopay.payments.create({ amountCents, currency: 'NZD' });
  // eslint-disable-next-line no-console
  console.warn(`Saga ${payment.sagaId} created.`);
  // eslint-disable-next-line no-console
  console.warn(`  status:      ${payment.status}`);
  // eslint-disable-next-line no-console
  console.warn(`  consentUrl:  ${payment.consentUrl}`);
  // eslint-disable-next-line no-console
  console.warn(`  qrCodeImage: ${payment.qrCodeImage.slice(0, 60)}... (${payment.qrCodeImage.length} bytes)`);
}

main().catch((err: unknown) => {
  // eslint-disable-next-line no-console
  console.error('create-payment example failed:', err);
  process.exitCode = 1;
});
