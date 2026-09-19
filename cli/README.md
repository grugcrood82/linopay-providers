# @linotech/cli

Official LinoPay CLI. The 5-minute terminal integration.

```
$ linopay init   # prompts for Key ID + PEM path, writes .env
$ linopay verify # fires one SDK call against your configured sandbox
```

Wraps `@linotech/sdk` with no extra moving parts — same wire shapes,
same signing, same `{ data: ... }` envelope rules.

## Install

### macOS / Linux

```bash
curl -fsSL https://raw.githubusercontent.com/grugcrood82/linopay-providers/main/cli/install/install.sh | sh
```

### Windows (PowerShell)

```powershell
iwr -useb https://raw.githubusercontent.com/grugcrood82/linopay-providers/main/cli/install/install.ps1 | iex
```

Both installers detect missing-or-too-old Node and bail with a
specific, actionable message instead of a stack trace.

## Commands

### `linopay init`

Writes a `.env` template containing the configuration the SDK
reads. Deliberately **never** writes the private key itself — only
the filesystem path to it.

```
$ linopay init
Channel Key ID (kid) [kid_demo]: kid_abc123
Environment (sandbox or live) [sandbox]: sandbox
LinoPay API base URL [http://localhost:18080]: https://sandbox.api.linopay.io
Default bank code (e.g. ANZ, ASB, BNZ, KIWIBANK, WESTPAC) []: ANZ
Path to your channel private key (PEM) [/Users/me/.linopay/key.pem]:
Wrote /Users/me/projects/myapp/.env.
Run `linopay verify` to test it.
```

Add `--non-interactive` for CI:
```bash
linopay init --non-interactive --output .env
```

If the current directory is a git repo and `.env` is not in
`.gitignore`, init prints a warning to stderr. The PEM file is
created if absent (with a placeholder comment — no key bytes).

### `linopay verify`

Sources the same env vars from the shell (or from your `.env`) and
makes one authenticating call to LinoPay, then prints
`OK. ...` or a specific failure message.

```
$ linopay verify
OK. Verified against https://sandbox.api.linopay.io as "kid_abc123". Active key kid_xyz... (Active, expires in 354 days).
```

Exits non-zero on failure — suitable for a `post-install` smoke in
deploy scripts.

## Verification (CI / pre-merge)

```bash
docker compose -f cli/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from cli-tests
docker compose -f cli/docker-compose.test.yml down -v
```

This stands up WireMock (the LinoPay-API mocks) and runs the full
CLI test suite against it, including a `init` → `verify`
round-trip. Everything is teardown-clean afterwards.

## License

MIT — see [`../LICENSE`](../LICENSE).
