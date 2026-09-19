#!/usr/bin/env node
/**
 * `linopay` CLI entrypoint.
 *
 * Subcommands:
 *   init    — write a `.env` template; the path to the PEM is
 *             recorded, never the PEM itself.
 *   verify  — fire one LinoPay API call against the configured
 *             sandbox and report pass/fail in plain language.
 *
 * No flags requiring a third-party parser. We keep argv parsing
 * deliberately minimal so the dependency surface stays at
 * `@linotech/sdk + jose + node built-ins`.
 */

import { initCommand } from './commands/init.js';
import { verifyCommand } from './commands/verify.js';

interface CliArgs {
  command: string | null;
  showHelp: boolean;
  raw: readonly string[];
}

function parseArgs(argv: readonly string[]): CliArgs {
  // Skip argv[0] (node) and argv[1] (script).
  const args = argv.slice(2);
  if (args.length === 0) {
    return { command: null, showHelp: true, raw: args };
  }
  if (args[0] === '--help' || args[0] === '-h') {
    return { command: null, showHelp: true, raw: args };
  }
  return { command: args[0] ?? null, showHelp: false, raw: args };
}

const HELP = `
linopay — official LinoPay CLI

Usage:
  linopay init                  Write a .env template (no secrets written to it).
  linopay verify                Run one authenticating call against the configured sandbox.
  linopay --help                Show this help.

The PEM private key is NEVER written into the .env template —
only its filesystem path. Re-run \`linopay init\` to regenerate.
`;

async function main(): Promise<void> {
  const { command, showHelp } = parseArgs(process.argv);
  if (showHelp || command === null) {
    process.stdout.write(HELP);
    return;
  }

  switch (command) {
    case 'init': {
      const nonInteractive = process.argv.includes('--non-interactive');
      const result = await initCommand({
        output: flagValue('--output'),
        defaults: nonInteractive
          ? {
              keyId: 'kid_demo',
              environment: 'sandbox',
              baseUrl: 'http://localhost:18080',
              bankCode: 'ANZ',
            }
          : {},
      });
      process.stdout.write(`Wrote ${result.envFilePath}.\n`);
      if (result.warning) {
        process.stderr.write(`Warning: ${result.warning}\n`);
      }
      process.stdout.write('Run `linopay verify` to test it.\n');
      return;
    }
    case 'verify': {
      const result = await verifyCommand();
      process.stdout.write(`${result.message}\n`);
      if (!result.ok) {
        process.exitCode = 1;
      }
      return;
    }
    default:
      process.stderr.write(`Unknown command: ${command}. Try \`linopay --help\`.\n`);
      process.exitCode = 2;
      return;
  }
}

function flagValue(name: string): string | undefined {
  const i = process.argv.indexOf(name);
  if (i < 0) return undefined;
  const v = process.argv[i + 1];
  if (!v || v.startsWith('--')) return undefined;
  return v;
}

main().catch((err: unknown) => {
  process.stderr.write(`linopay: ${(err as Error).message ?? String(err)}\n`);
  process.exitCode = 1;
});
