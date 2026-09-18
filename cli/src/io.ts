import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Minimal I/O helpers for the CLI — no third-party deps.
 *
 * `prompt` reads a single line from the configured input stream and
 * returns it (trimmed). Tests inject a stub. The CLI runs with
 * `node:readline`-style behavior on POSIX TTYs; on a redirected pipe
 * we read raw chunks because TTY is unlikely when this CLI is run
 * non-interactively (e.g., from a setup script).
 */

export type PromptFn = (question: string, defaultValue?: string) => Promise<string>;

export const PROMPT_NEVER = Symbol('PROMPT_NEVER');
export type PromptNever = typeof PROMPT_NEVER;

/**
 * Detects whether `.env` is about to be committed to a git repo at
 * `cwd`. Returns the file path to the existing `.gitignore` if one
 * is found and ignores `.env`, or an explanation if not.
 *
 * The SOW §3 deliverable is explicit: `linopay init` must "warn
 * loudly if it detects a `.env` file about to be added to a git repo
 * without a `.gitignore` entry for it." This function is what
 * carries that warning.
 */
export interface GitignoreStatus {
  /** Whether `cwd` is inside a git repo at all. */
  isGitRepo: boolean;
  /** Whether the existing `.gitignore` (if any) ignores `.env`. */
  gitignoreMentionsEnv: boolean | null;
  /** Absolute path to the existing `.gitignore`, if any. */
  gitignorePath: string | null;
  /** When non-null, a string the caller should print to stderr. */
  warning: string | null;
}

export function inspectGitignoreForEnv(cwd: string): GitignoreStatus {
  let isGitRepo = false;
  try {
    // Cheap probe: if .git/ exists in cwd, this is a git repo.
    // Deeper `git rev-parse --show-toplevel` is nicer but spawns a
    // process; keeping this pure-JS so the CLI runs in any environment
    // (including a stripped-down alpine container) without a git
    // binary on PATH.
    readFileSync(resolve(cwd, '.git/HEAD'));
    isGitRepo = true;
  } catch {
    isGitRepo = false;
  }

  if (!isGitRepo) {
    return {
      isGitRepo: false,
      gitignoreMentionsEnv: null,
      gitignorePath: null,
      warning: null,
    };
  }

  const gitignorePath = resolve(cwd, '.gitignore');
  let contents = '';
  try {
    contents = readFileSync(gitignorePath, 'utf8');
  } catch {
    return {
      isGitRepo: true,
      gitignoreMentionsEnv: false,
      gitignorePath: null,
      warning:
        "This directory is a git repository but has no .gitignore. " +
        "Add one that ignores .env before you commit anything `linopay init` writes here.",
    };
  }

  // Pattern matches a line that mentions `.env` — covers `.env`,
  // `.env.*`, `*.env`. The point is to know whether `.env` (the file
  // we want to write) is going to leak into a commit.
  const lines = contents.split(/\r?\n/).map((l) => l.trim());
  const gitignoreMentionsEnv = lines.some(
    (l) => l === '.env' || l === '.env*' || l.startsWith('.env*') || l.includes('.env'),
  );

  return {
    isGitRepo: true,
    gitignoreMentionsEnv,
    gitignorePath,
    warning: gitignoreMentionsEnv
      ? null
      : "`.env` is not in your `.gitignore`. `linopay init` writes no secrets, but you should still add `.env` to it before committing.",
  };
}
