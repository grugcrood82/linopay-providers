import { describe, expect, it } from 'vitest';
import { verifyCommand } from '../src/commands/verify.js';

describe('linopay verify', () => {
  it('fails fast when LINOPAY_BASE_URL is missing', async () => {
    const result = await verifyCommand({
      env: { LINOPAY_KEY_ID: 'kid', LINOPAY_PRIVATE_KEY_PEM_PATH: '/x', LINOPAY_BASE_URL: '' },
    });
    expect(result.ok).toBe(false);
    expect(result.message).toMatch(/LINOPAY_BASE_URL/);
  });

  it('fails fast when LINOPAY_KEY_ID is missing', async () => {
    const result = await verifyCommand({
      env: {
        LINOPAY_BASE_URL: 'http://x',
        LINOPAY_KEY_ID: '',
        LINOPAY_PRIVATE_KEY_PEM_PATH: '/x',
      },
    });
    expect(result.ok).toBe(false);
    expect(result.message).toMatch(/LINOPAY_KEY_ID/);
  });

  it('reports a clear error when the PEM file is unreadable', async () => {
    const result = await verifyCommand({
      env: {
        LINOPAY_BASE_URL: 'http://127.0.0.1:1', // refused
        LINOPAY_KEY_ID: 'kid',
        LINOPAY_PRIVATE_KEY_PEM_PATH: '/nonexistent/does-not-exist.pem',
      },
    });
    expect(result.ok).toBe(false);
    expect(result.message).toMatch(/Could not read the private key/);
  });

  it('refuses to run with an unknown environment value', async () => {
    const result = await verifyCommand({
      env: {
        LINOPAY_BASE_URL: 'http://x',
        LINOPAY_KEY_ID: 'kid',
        LINOPAY_PRIVATE_KEY_PEM_PATH: '/x',
        LINOPAY_ENVIRONMENT: 'banana',
      },
      fs: { readFile: () => 'fake-pem' },
    });
    expect(result.ok).toBe(false);
    expect(result.message).toMatch(/LINOPAY_ENVIRONMENT/);
  });
});
