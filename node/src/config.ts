/**
 * SDK configuration.
 *
 * The merchant constructs a {@link LinopayConfig} once and passes it to
 * every SDK call. The config carries the channel's signing key (Key ID +
 * PEM), the API base URL, the default bank code, and the environment.
 *
 * Sandbox vs. live must be explicit. The SDK never infers it from the
 * shape of the key or from an ambient env var the caller didn't set
 * themselves (SOW §2.2).
 */
export interface LinopayConfig {
  /**
   * Base URL for the LinoPay merchant API. For the wiremock-backed test
   * sandbox this is `http://localhost:8080`. For production this is the
   * published LinoPay merchant API host.
   */
  readonly baseUrl: string;

  /**
   * Channel Key ID. Sent as `X-Channel-KeyId` on every request and used
   * as the `kid` header on the channel-signed instruction JWT.
   */
  readonly keyId: string;

  /**
   * The channel's RSA private key in PEM form. The SDK holds it in
   * memory only — never logged, never written to disk, never returned
   * through an error message (SOW §2.1).
   */
  readonly privateKeyPem: string;

  /**
   * Default bank code (e.g. `ANZ`, `ASB`, `BNZ`, `KIWIBANK`, `WESTPAC`)
   * for one-off payments. Optional on the config; can be overridden per
   * call.
   */
  readonly bankCode?: string;

  /**
   * Either `"sandbox"` or `"live"`. Required. Surfaced in error
   * messages so a merchant who fires from the wrong environment knows
   * immediately.
   */
  readonly environment: LinopayEnvironment;

  /**
   * Optional explicit logger. If omitted, the SDK writes nothing to
   * stdout/stderr — the only way secret material could leak from
   * logging is via a consumer-supplied logger, so the SDK never has one
   * by default.
   */
  readonly logger?: Logger;
}

export type LinopayEnvironment = 'sandbox' | 'live';

export interface Logger {
  info(msg: string, meta?: Record<string, unknown>): void;
  warn(msg: string, meta?: Record<string, unknown>): void;
  error(msg: string, meta?: Record<string, unknown>): void;
}

/**
 * Loads an SDK config from environment variables. Never reads files or
 * reads from a global secret store. The PEM is read from a filesystem
 * path supplied in `LINOPAY_PRIVATE_KEY_PEM_PATH` — the PEM itself is
 * never put in an env var (env vars get logged; files at named paths
 * don't, by default).
 *
 * Required env vars: LINOPAY_BASE_URL, LINOPAY_KEY_ID,
 * LINOPAY_PRIVATE_KEY_PEM_PATH, LINOPAY_ENVIRONMENT.
 */
export async function loadConfigFromEnv(
  fs?: { readFile(path: string): Promise<string> },
): Promise<LinopayConfig> {
  const baseUrl = requireEnv('LINOPAY_BASE_URL');
  const keyId = requireEnv('LINOPAY_KEY_ID');
  const pemPath = requireEnv('LINOPAY_PRIVATE_KEY_PEM_PATH');
  const environment = requireEnv('LINOPAY_ENVIRONMENT') as LinopayEnvironment;
  if (environment !== 'sandbox' && environment !== 'live') {
    throw new Error(
      `LINOPAY_ENVIRONMENT must be 'sandbox' or 'live' (got ${JSON.stringify(environment)}).`,
    );
  }
  const bankCode = process.env.LINOPAY_BANK_CODE;

  const reader = fs ?? (await import('node:fs/promises'));
  const privateKeyPem = (await reader.readFile(pemPath)).toString();

  const cfg: LinopayConfig = {
    baseUrl: baseUrl.replace(/\/+$/, ''),
    keyId,
    privateKeyPem,
    environment,
    ...(bankCode ? { bankCode } : {}),
  };
  return cfg;
}

function requireEnv(name: string): string {
  const v = process.env[name];
  if (!v || v.trim() === '') {
    throw new Error(`Missing required env var ${name}.`);
  }
  return v.trim();
}
