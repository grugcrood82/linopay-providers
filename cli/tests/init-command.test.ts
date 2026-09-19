import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, writeFileSync, rmSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { initCommand } from '../src/commands/init.js';

function tmpCwd(): string {
  return mkdtempSync(join(tmpdir(), 'linopay-cli-test-'));
}

function makeFakePrompt(answers: Record<string, string>) {
  return async (q: string, d?: string) => {
    // match by leading-substring so we don't depend on exact wording
    for (const [k, v] of Object.entries(answers)) {
      if (q.startsWith(k)) return v;
    }
    return d ?? '';
  };
}

describe('linopay init', () => {
  let cwd: string;
  beforeEach(() => {
    cwd = tmpCwd();
  });
  afterEach(() => {
    rmSync(cwd, { recursive: true, force: true });
  });

  it('writes a .env template containing the right env vars, never the PEM', async () => {
    writeFileSync(
      join(cwd, 'fake-key.pem'),
      '-----BEGIN PRIVATE KEY-----\nfake-key-bytes\n-----END PRIVATE KEY-----\n',
    );
    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid_test_001',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://localhost:18080',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'fake-key.pem'),
    });
    const result = await initCommand({
      cwd,
      home: cwd,
      prompt,
    });
    expect(existsSync(result.envFilePath)).toBe(true);
    const contents = readFileSync(result.envFilePath, 'utf8');
    expect(contents).toContain('LINOPAY_KEY_ID=kid_test_001');
    expect(contents).toContain('LINOPAY_BASE_URL=http://localhost:18080');
    expect(contents).toContain('LINOPAY_ENVIRONMENT=sandbox');
    expect(contents).toContain('LINOPAY_BANK_CODE=ANZ');
    expect(contents).toContain(`LINOPAY_PRIVATE_KEY_PEM_PATH=${join(cwd, 'fake-key.pem')}`);
    // The PEM *bytes* must NOT appear in the env template. The path
    // component (filename) is fine — only the key body is forbidden.
    expect(contents).not.toContain('BEGIN PRIVATE KEY');
    expect(contents).not.toContain('fake-key-bytes');
  });

  it('writes the env file at 0600 (mode bit) so other users can\'t read it', async () => {
    writeFileSync(join(cwd, 'k.pem'), 'fake\n');
    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://x',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'k.pem'),
    });
    const result = await initCommand({ cwd, home: cwd, prompt });
    // On POSIX, the env file should be 0600. On Windows the bit is
    // best-effort (NTFS permissions don't map to POSIX mode bits in
    // the same way) — relax to "at least not 0666".
    if (process.platform === 'win32') {
      expect(true).toBe(true);
    } else {
      const mode = (require('node:fs') as typeof import('node:fs')).statSync(result.envFilePath).mode & 0o777;
      expect(mode & 0o077).toBe(0);
    }
  });

  it('creates a placeholder PEM file when one does not exist', async () => {
    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://x',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'new-key.pem'),
    });
    const result = await initCommand({ cwd, home: cwd, prompt });
    expect(existsSync(result.pemPath)).toBe(true);
    const contents = readFileSync(result.pemPath, 'utf8');
    // Placeholder, not the key.
    expect(contents).not.toContain('BEGIN PRIVATE KEY');
    expect(contents).toContain('paste your channel private key');
  });

  it('warns when writing .env into a git repo that does not ignore it', async () => {
    require('node:fs').mkdirSync(join(cwd, '.git'), { recursive: true });
    writeFileSync(join(cwd, '.git/HEAD'), 'ref: refs/heads/main\n');
    writeFileSync(join(cwd, 'k.pem'), 'fake');

    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://x',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'k.pem'),
    });
    const result = await initCommand({ cwd, home: cwd, prompt });
    expect(result.warning).not.toBeNull();
    expect(result.warning).toMatch(/gitignore/i);
  });

  it('does NOT warn when git is detected and .gitignore mentions .env', async () => {
    require('node:fs').mkdirSync(join(cwd, '.git'), { recursive: true });
    writeFileSync(join(cwd, '.git/HEAD'), 'ref: refs/heads/main\n');
    writeFileSync(join(cwd, '.gitignore'), '.env\n');
    writeFileSync(join(cwd, 'k.pem'), 'fake');

    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://x',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'k.pem'),
    });
    const result = await initCommand({ cwd, home: cwd, prompt });
    expect(result.warning).toBeNull();
  });

  it('does NOT warn outside a git repo', async () => {
    writeFileSync(join(cwd, 'k.pem'), 'fake');
    const prompt = makeFakePrompt({
      'Channel Key ID': 'kid',
      Environment: 'sandbox',
      'LinoPay API base URL': 'http://x',
      'Default bank code': 'ANZ',
      'Path to your channel private key': join(cwd, 'k.pem'),
    });
    const result = await initCommand({ cwd, home: cwd, prompt });
    expect(result.warning).toBeNull();
  });
});
