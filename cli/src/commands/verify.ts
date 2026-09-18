import { readFileSync } from 'node:fs';
import { resolve as resolvePath } from 'node:path';
import { createLinopay, LinopayApiError, LinopayConfigError } from '@linotech/sdk';

/**
 * `linopay verify` — fire one LinoPay API call against the configured
 * sandbox and report pass/fail in plain language.
 *
 * The call chosen is `channels.keyStatus()`. It's the cheapest
 * authenticating call we have: it forces both the JWT signing path
 * AND the token-exchange path (since keyStatus uses the exchanged
 * scoped token), without mutating anything. If it succeeds, every
 * other SDK method in this surface area will too.
 */
export interface VerifyOptions {
  cwd?: string;
  env?: NodeJS.ProcessEnv;
  fs?: { readFile(path: string): string };
}

export interface VerifyResult {
  ok: boolean;
  /** Plain-language summary; do not present as JSON. */
  message: string;
  /**
   * The Key ID that was used, when the call succeeded. Useful for
   * `linopay verify` from a Docker container where the merchant
   * pasted one-off credentials.
   */
  keyId?: string;
  /** Active key days-remaining, when the call succeeded. */
  daysRemaining?: number | null;
}

export async function verifyCommand(opts: VerifyOptions = {}): Promise<VerifyResult> {
  const env = opts.env ?? process.env;
  const baseUrlRaw = env['LINOPAY_BASE_URL']?.trim() ?? '';
  const keyIdRaw = env['LINOPAY_KEY_ID']?.trim() ?? '';
  const pemPathRaw = env['LINOPAY_PRIVATE_KEY_PEM_PATH']?.trim() ?? '';
  if (!baseUrlRaw) {
    return {
      ok: false,
      message: 'Missing required env var LINOPAY_BASE_URL. Run `linopay init` first.',
    };
  }
  if (!keyIdRaw) {
    return {
      ok: false,
      message: 'Missing required env var LINOPAY_KEY_ID. Run `linopay init` first.',
    };
  }
  if (!pemPathRaw) {
    return {
      ok: false,
      message: 'Missing required env var LINOPAY_PRIVATE_KEY_PEM_PATH. Run `linopay init` first.',
    };
  }
  const baseUrl = baseUrlRaw;
  const keyId = keyIdRaw;
  const environment = (env['LINOPAY_ENVIRONMENT'] ?? 'sandbox').trim();
  if (environment !== 'sandbox' && environment !== 'live') {
    return {
      ok: false,
      message:
        `LINOPAY_ENVIRONMENT must be "sandbox" or "live" (got "${environment}"). ` +
        `Re-run \`linopay init\` to fix.`,
    };
  }

  const cwd = opts.cwd ?? process.cwd();
  const pemPath = pemPathRaw.startsWith('/') || /^[a-zA-Z]:[\\/]/.test(pemPathRaw)
    ? pemPathRaw
    : resolvePath(cwd, pemPathRaw);

  let pem: string;
  try {
    const reader = opts.fs?.readFile ?? ((p: string) => readFileSync(p, 'utf8'));
    pem = reader(pemPath);
  } catch (err: unknown) {
    return {
      ok: false,
      message:
        `Could not read the private key at ${pemPath}: ` +
        `${(err as Error).message ?? String(err)}. ` +
        `Re-run \`linopay init\` to check the path, or fix LINOPAY_PRIVATE_KEY_PEM_PATH.`,
    };
  }

  const cfg: Parameters<typeof createLinopay>[0] = {
    baseUrl,
    keyId,
    privateKeyPem: pem,
    environment: environment as 'sandbox' | 'live',
    ...(env['LINOPAY_BANK_CODE'] ? { bankCode: env['LINOPAY_BANK_CODE'] } : {}),
  };
  const linopay = createLinopay(cfg);

  try {
    const status = await linopay.channels.keyStatus();
    return {
      ok: true,
      keyId,
      daysRemaining: status.daysRemaining,
      message:
        `OK. Verified against ${baseUrl} as "${keyId}". ` +
        `Active key ${status.activeKeyId || '(none)'} ` +
        `(${status.effectiveStatus}` +
        `${status.daysRemaining !== null ? `, expires in ${status.daysRemaining} day(s)` : ''}` +
        `).`,
    };
  } catch (err: unknown) {
    if (err instanceof LinopayConfigError) {
      return { ok: false, message: `Configuration problem: ${err.message}` };
    }
    if (err instanceof LinopayApiError) {
      return {
        ok: false,
        message: `LinoPay rejected the request: ${err.message} (HTTP ${err.status}). ` +
          `Double-check the Key ID, PEM, and base URL.`,
      };
    }
    return {
      ok: false,
      message:
        `Could not reach ${baseUrl}: ${(err as Error).message ?? String(err)}. ` +
        `Is the base URL reachable? If it's the local sandbox, is WireMock up?`,
    };
  }
}

// Note: a previous revision of this file had a `requireEnv` helper
// that *threw* on missing env vars. That made the CLI emit an
// unfriendly traceback for "you haven't run init yet" — instead, the
// CLI now returns `{ ok: false, message }` so the user sees one
// plain-language line. Removed the helper outright.
