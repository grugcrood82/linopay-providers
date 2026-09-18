import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { spawn, ChildProcess } from 'node:child_process';
import { generateKeyPair, exportPKCS8 } from 'jose';
import { writeFileSync } from 'node:fs';
import { createLinopay } from '../src/index.js';

/**
 * Integration test: hits a real local WireMock that the docker-compose
 * test stack stood up. Skipped automatically if `LINOPAY_E2E_BASE_URL`
 * is unset so a pure unit-test run on a developer machine still works.
 *
 * In CI (and locally when invoked via the self-check script) the env
 * var is set to `http://wiremock:8080` and the assertions below run.
 */
const BASE_URL = process.env['LINOPAY_E2E_BASE_URL'];
const SHOULD_RUN_E2E = !!BASE_URL;
const describeE2E = SHOULD_RUN_E2E ? describe : describe.skip;

describeE2E('SDK against a live WireMock', () => {
  let tmpPemPath: string;

  beforeAll(async () => {
    if (!SHOULD_RUN_E2E) return;
    const { privateKey } = await generateKeyPair('RS256', { extractable: true });
    const pem = await exportPKCS8(privateKey);
    const tmpPath = process.env['LINOPAY_E2E_PEM_PATH'] ?? './.test-key.pem';
    writeFileSync(tmpPath, pem, { mode: 0o600 });
    tmpPemPath = tmpPath;
  });

  afterAll(() => {
    // Don't leave the key on disk; every test invocation regenerates
    // it, so no caching here.
    if (tmpPemPath) {
      try {
        require('node:fs').unlinkSync(tmpPemPath);
      } catch {
        // best-effort cleanup
      }
    }
  });

  it('creates a one-off payment against WireMock', async () => {
    if (!SHOULD_RUN_E2E) return;
    const { readFileSync } = await import('node:fs');
    const pem = readFileSync(tmpPemPath, 'utf8');
    const linopay = createLinopay({
      baseUrl: BASE_URL!,
      keyId: 'kid_e2e',
      privateKeyPem: pem,
      bankCode: 'ANZ',
      environment: 'sandbox',
    });

    const payment = await linopay.payments.create({ amountCents: 199, currency: 'NZD' });
    expect(payment.sagaId).toMatch(/^wire_saga_/);
    expect(payment.qrCodeImage).toMatch(/^data:image\/png/);
    expect(payment.consentUrl).toMatch(/oauth\/v2\.0\/authorize/);
    expect(payment.status).toBe('Pending');
  });

  it('exchanges a token, then creates an invoice', async () => {
    if (!SHOULD_RUN_E2E) return;
    const { readFileSync } = await import('node:fs');
    const pem = readFileSync(tmpPemPath, 'utf8');
    const linopay = createLinopay({
      baseUrl: BASE_URL!,
      keyId: 'kid_e2e_invoice',
      privateKeyPem: pem,
      bankCode: 'ASB',
      environment: 'sandbox',
    });

    const invoice = await linopay.invoices.create({
      amountCents: 1000,
      reference: 'wire-test-ref',
      paymentWindowDays: 14,
    });
    expect(invoice.invoiceId).toMatch(/^wire_inv_/);
    expect(invoice.status).toBe('Outstanding');
  });

  it('reads the active channel key status', async () => {
    if (!SHOULD_RUN_E2E) return;
    const { readFileSync } = await import('node:fs');
    const pem = readFileSync(tmpPemPath, 'utf8');
    const linopay = createLinopay({
      baseUrl: BASE_URL!,
      keyId: 'kid_e2e_keystatus',
      privateKeyPem: pem,
      bankCode: 'BNZ',
      environment: 'sandbox',
    });

    const status = await linopay.channels.keyStatus();
    expect(status.activeKeyId).toMatch(/^wire_kid_/);
    expect(status.effectiveStatus).toBe('Active');
    expect(typeof status.daysRemaining).toBe('number');
  });
});

// Self-check: smoke-test that the runnable example file is reachable
// from a docker-shell context. We do this by reading the file rather
// than exec'ing it — full execution is the job of the containerised
// self-check script (`docker compose ... up node-tests`).
describe('runnable example', () => {
  it('exists at examples/create-payment.ts', async () => {
    const { existsSync } = await import('node:fs');
    const { resolve } = await import('node:path');
    const p = resolve(__dirname, '..', 'examples', 'create-payment.ts');
    expect(existsSync(p)).toBe(true);
  });
});
