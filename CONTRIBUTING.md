# Contributing to linopay-providers

Thanks for contributing. This repository exists to make LinoPay
integrations boring — predictable, testable, well-typed. Every change
should leave the codebase at least that boring.

## Ground rules

1. **Every provider folder is independently versioned.** A change to
   `node/` does not force a release of `cli/`; they ship separately,
   they release separately, they pin dependencies separately.
2. **No real credentials, ever, anywhere.** Public fixtures only.
   The WireMock sandbox in `test-infra/` is what you test against.
3. **Never log, persist, or surface secret material.** Private keys,
   webhook signing secrets, bearer tokens — none of these may appear
   in a log line, an exception message, or a stack trace, in any SDK
   or CLI. There is a unit test asserting this for every provider
   that touches a secret; add yours when you add a new code path.
4. **Sandbox vs. live is explicit, never inferred.** A client is
   constructed with an explicit environment. No sniffing the key's
   shape, no ambient env vars the caller didn't set themselves.

## Pull request checklist

Before opening a PR, every box below must be true:

- [ ] `docker compose -f <provider>/docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from <provider>-tests` exits 0.
- [ ] `docker compose -f <provider>/docker-compose.test.yml down -v` tears everything down cleanly.
- [ ] The real command output is pasted into the PR description — not paraphrased.
- [ ] No new dependency was added without a justification line in the PR body explaining what it buys and whether it's already a transitive dep.
- [ ] No new public method was added without a matching wire-format endpoint in `lime-payments/shared/LinoPay.Shared.Contracts/`. If you think you need one and it doesn't exist, that's a separate conversation with the upstream maintainers.

## Local development

Each provider folder is its own package; consult its own README. There
is no top-level `npm install` or `pip install` that gets you anything.

## Code of conduct

See [`CODE_OF_CONDUCT.md`](./CODE_OF_CONDUCT.md). The short version:
be the colleague you'd want to debug with at 2am.
