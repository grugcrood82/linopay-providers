import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { generateKeyPair, exportPKCS8 } from 'jose';
import { initCommand } from '../src/commands/init.js';
import { verifyCommand } from '../src/commands/verify.js';

/**
 * End-to-end CLI test (the SOW Phase 1 DoD: "`linopay init` followed by
 * `linopay verify`, run inside a throwaway container against the
 * WireMock sandbox, succeeds end to end as part of the same
 * `docker compose` stack").
 *
 * Skipped unless `LINOPAY_E2E_BASE_URL` is set (the docker-compose
 * self-check sets it for `cli-tests`).
 */
const BASE_URL = process.env['LINOPAY_E2E_BASE_URL'];
const SHOULD_RUN_E2E = !!BASE_URL;
const describeE2E = SHOULD_RUN_E2E ? describe : describe.skip;

describeE2E('CLI e2e against WireMock', () => {
  let cwd: string;
  let pemPath: string;
  let envFileContents: string;

  beforeAll(async () => {
    cwd = mkdtempSync(join(tmpdir(), 'linopay-cli-e2e-'));
    pemPath = join(cwd, 'test-key.pem');
    const { privateKey } = await generateKeyPair('RS256', { extractable: true });
    const pem = await exportPKCS8(privateKey);
    writeFileSync(pemPath, pem, { mode: 0o600 });
  });

  afterAll(async () => {
    // best-effort cleanup; tmpdir gets swept on reboot anyway
  });

  it('init writes the .env template without the PEM bytes', async () => {
    const initResult = await initCommand({
      cwd,
      home: cwd,
      pemPath,
      // Stub one prompt per field, with the right answer for each.
      prompt: (async (question: string, defaultValue?: string) => {
        if (question.startsWith('Channel Key ID')) return 'kid_e2e';
        if (question.startsWith('Environment')) return defaultValue ?? 'sandbox';
        if (question.startsWith('LinoPay API base URL')) return defaultValue ?? 'http://wiremock:8080';
        if (question.startsWith('Default bank code')) return 'ANZ';
        if (question.startsWith('Path to your channel private key')) return pemPath;
        return defaultValue ?? '';
      }) as never,
    });
    expect(initResult.envFilePath.endsWith('.env')).toBe(true);
    envFileContents = readFileSync(initResult.envFilePath, 'utf8');
    expect(envFileContents).toContain('LINOPAY_KEY_ID=kid_e2e');
    expect(envFileContents).toContain('LINOPAY_ENVIRONMENT=sandbox');
    expect(envFileContents).not.toContain('BEGIN PRIVATE KEY');
  });

  it('verify against the WireMock sandbox succeeds end to end', async () => {
    // Pull what init wrote so the env we pass to verifyCommand
    // matches the on-disk template.
    const lines = envFileContents.split('\n');
    const env: NodeJS.ProcessEnv = {};
    for (const l of lines) {
      const m = /^([A-Z_]+)=(.+)$/.exec(l.trim());
      if (m) env[m[1]!] = m[2];
    }
    env['LINOPAY_BASE_URL'] = BASE_URL!;

    const result = await verifyCommand({ env, cwd });
    if (!result.ok) {
      throw new Error(`verify failed: ${result.message}`);
    }
    expect(result.ok).toBe(true);
    expect(result.message).toMatch(/OK/);
    expect(result.keyId).toBe('kid_e2e');
    expect(typeof result.daysRemaining).toBe('number');
  });
});
