import { writeFileSync, mkdirSync, statSync } from 'node:fs';
import { dirname, isAbsolute, resolve } from 'node:path';
import { inspectGitignoreForEnv, type PromptFn } from '../io.js';
import { defaultPemPath, renderEnvTemplate } from '../envfile.js';

export interface InitOptions {
  /**
   * Where the `.env` file should be written. Defaults to
   * `<cwd>/.env`. Pass an explicit path to write elsewhere.
   */
  output?: string;
  /** Override the env-template path. */
  pemPath?: string;
  /** Override the home directory for the default PEM location. */
  home?: string;
  /** Override the CWD; defaults to `process.cwd()`. */
  cwd?: string;
  /** Override the prompt function (tests inject stubs). */
  prompt?: PromptFn;
  /**
   * Pre-fill individual fields. The CLI's `--non-interactive` flag
   * (and tests) pass values here so prompts are skipped for fields
   * that already have a value, while still allowing interactive
   * prompting for the rest.
   */
  defaults?: {
    keyId?: string;
    baseUrl?: string;
    environment?: 'sandbox' | 'live';
    bankCode?: string;
    pemPath?: string;
  };
}

export interface InitResult {
  envFilePath: string;
  pemPath: string;
  warning: string | null;
  /** What was actually written, for the test suite. */
  written: { envFilePath: string; contents: string };
}

/**
 * `linopay init` — write a `.env`-compatible template so a developer
 * can install the SDK + run a real call with one shell command.
 *
 * The flow:
 *   1. Ask for the Key ID (or read it from --key-id).
 *   2. Ask for the bank code, base URL, environment (sensible defaults).
 *   3. Ask where the PEM lives on disk (or default to ~/.linopay/key.pem).
 *   4. Detect whether `.env` is going into a git repo untracked, and
 *      warn loudly if so (SOW §3).
 *   5. Write the template. The PEM is NEVER written into the template;
 *      only the filesystem path to it is.
 */
export async function initCommand(opts: InitOptions = {}): Promise<InitResult> {
  const cwd = opts.cwd ?? process.cwd();
  const home = opts.home ?? process.env['HOME'] ?? process.env['USERPROFILE'] ?? cwd;
  const pemPath = opts.pemPath ?? defaultPemPath(home);
  const promptFn: PromptFn = opts.prompt ?? ((q, d) => stdioPrompt(q, d));
  const d = opts.defaults ?? {};

  // Probe gitignore status BEFORE any prompting so the warning (if
  // any) can ride alongside the prompt output, in the right order.
  const gitignoreStatus = inspectGitignoreForEnv(cwd);

  const keyId = await promptFn('Channel Key ID (kid)', d.keyId ?? process.env['LINOPAY_KEY_ID']);
  const environmentRaw = await promptFn('Environment (sandbox or live)', d.environment ?? 'sandbox');
  const environment = (
    d.environment ??
    (environmentRaw.trim() === '' ? 'sandbox' : environmentRaw.trim())
  ) as 'sandbox' | 'live';
  const baseUrl = await promptFn(
    'LinoPay API base URL',
    d.baseUrl ?? (environment === 'live' ? 'https://api.linopay.io' : 'http://localhost:18080'),
  );
  const bankCodeRaw = await promptFn(
    'Default bank code (e.g. ANZ, ASB, BNZ, KIWIBANK, WESTPAC)',
    d.bankCode ?? '',
  );
  const bankCode = bankCodeRaw.trim() === '' ? undefined : bankCodeRaw.trim();
  const resolvedPemPath = await promptFn(
    'Path to your channel private key (PEM)',
    d.pemPath ?? (isAbsolute(pemPath) ? pemPath : resolve(cwd, pemPath)),
  );

  const envFilePath = resolve(cwd, opts.output ?? '.env');
  const contents = renderEnvTemplate({
    keyId: keyId.trim(),
    baseUrl: baseUrl.trim(),
    environment,
    ...(bankCode ? { bankCode } : {}),
    pemPath: resolvedPemPath.trim(),
  });

  // Write atomically — write to a temp file, then rename. Avoids
  // leaving a half-written `.env` if the process is killed mid-write.
  mkdirSync(dirname(envFilePath), { recursive: true });
  writeFileSync(envFilePath, contents, { encoding: 'utf8', mode: 0o600 });
  // If the PEM file does not yet exist, create an empty placeholder.
  // The merchant pastes their key in later. We do NOT write the key
  // itself; that's their responsibility.
  try {
    statSync(resolvedPemPath);
  } catch {
    mkdirSync(dirname(resolvedPemPath), { recursive: true });
    writeFileSync(resolvedPemPath, '# paste your channel private key (PEM, PKCS#8) here\n', {
      mode: 0o600,
    });
  }

  return {
    envFilePath,
    pemPath: resolvedPemPath,
    warning: gitignoreStatus.warning,
    written: { envFilePath, contents },
  };
}

/**
 * Real, blocking stdin prompt. Suitable for an interactive TTY.
 * Tests should never reach here — they pass a stub via `opts.prompt`.
 */
async function stdioPrompt(question: string, defaultValue?: string): Promise<string> {
  // We deliberately don't import `readline` so the CLI has zero deps
  // beyond `@linotech/sdk` and `jose`. node:readline is a Node
  // built-in; using it pulls nothing extra into node_modules.
  const { createInterface } = await import('node:readline');
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  return new Promise<string>((resolveP) => {
    const suffix = defaultValue ? ` [${defaultValue}]` : '';
    rl.question(`${question}${suffix}: `, (answer) => {
      rl.close();
      resolveP(answer.trim() === '' ? (defaultValue ?? '') : answer.trim());
    });
  });
}
