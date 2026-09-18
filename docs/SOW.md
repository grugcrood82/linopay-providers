# Statement of Work: Merchant SDKs, CLI, and WooCommerce Plugin

**Audience:** an implementing engineer (or AI coding agent) working across
two repositories: this one (`lime-payments`, read-only reference for
contracts and the existing demo) and a new one you will create,
`linopay-providers` (where all deliverables in this SOW actually live).
Every instruction below assumes you can read the actual source files named
in `lime-payments` ΓÇö do that before writing any code. Do not invent request
or response shapes, endpoint paths, or field names. If something this
document asks for does not actually exist in `lime-payments` yet, **stop and
flag it** in your report rather than fabricating a stand-in.

**Non-negotiable working rule:** you have a documented track record of
missing things and leaving work half-wired (see the CHNUI-021 facelift ΓÇö
inconsistent labels, a checkbox that saved nothing, incomplete tab wiring).
This SOW exists specifically to close that gap. Every phase below has a
**Definition of Done** and a **Self-check script**. A phase is not complete
until you have actually run the self-check, pasted its real output into your
report, and every item passes. "I wrote the code" is not "I verified the
code." If a check fails, fix it before moving to the next phase ΓÇö do not
report a failing check and move on regardless.

**No manual verification, anywhere.** The person who owns this project will
not be running any of this on their own machine. Every check in every
phase's Definition of Done must be something that runs unattended inside a
Docker container (or a `docker compose` stack), produces a clear pass/fail
result, and tears itself down afterwards. If a Definition of Done item as
originally drafted would require a human to click through a UI, that item
has been rewritten below to instead run headlessly inside a container ΓÇö
follow the rewritten version, and if you find another spot in this document
that still implies manual testing, treat that as a documentation bug and
containerize it yourself rather than doing it by hand.

---

## 0. Context (read once, do not skip)

LinoPay merchants integrate today by hand-constructing signed requests
against their channel's own signing key, understanding PNZ Open Banking
concepts, and manually verifying webhook signatures. This SOW builds the
tooling that removes that friction: per-language SDKs, a CLI for the
"5-minute integration" experience, and a WooCommerce plugin ΓÇö the highest-
leverage single artifact here, because most WooCommerce store owners are not
developers and will never touch a raw SDK.

**Reference implementation ΓÇö read this first, in full, before writing any
SDK code:** `lime-payments/demos/` (Node.js). Its server signs real
requests with a real channel's private key and calls LinoPay's real dev
APIs ΓÇö read `demos/README.md` and the server source for exactly how
credential setup (`/setup`, Key ID + PEM upload, no host path/volume mount
needed) and request signing work today. Treat this as the source of truth
for Phase 1's design, not something to redesign from a blank page. **Do
not move or modify anything in `lime-payments/demos/`** ΓÇö you are reading
it for reference only; the code you write lives entirely in the new
`linopay-providers` repository (┬º0.5).

**This is going to be open source.** Everything built under this SOW is
public from the day its repository is created ΓÇö write code, comments, and
commit messages accordingly (no internal jargon, no references to internal
ticket numbers or people, no secrets or sandbox credentials committed
anywhere, ever, including in test fixtures ΓÇö see ┬º1's WireMock note for how
to test without needing a single real credential).

## 0.5. Repository setup (do this before Phase 1)

Create a **new, separate, public** GitHub repository named
`linopay-providers` ΓÇö a sibling of `lime-payments`, not a folder inside it
and not a fork of it.

```bash
mkdir linopay-providers && cd linopay-providers
git init
gh repo create linopay-providers --public \
  --description "Official LinoPay client SDKs, CLI, and platform plugins" \
  --source . --remote origin
```

Structure it by folder, one top-level folder per provider, flat (no deep
nesting):

```
linopay-providers/
Γö£ΓöÇΓöÇ LICENSE                  # MIT ΓÇö see note below
Γö£ΓöÇΓöÇ README.md                # what this repo is, links to each folder's own README
Γö£ΓöÇΓöÇ CONTRIBUTING.md
Γö£ΓöÇΓöÇ CODE_OF_CONDUCT.md
Γö£ΓöÇΓöÇ .github/workflows/       # one CI workflow per provider folder, see ┬º2.6
Γö£ΓöÇΓöÇ test-infra/              # shared Docker test infrastructure, see ┬º1
Γö£ΓöÇΓöÇ node/                    # Phase 1
Γö£ΓöÇΓöÇ cli/                     # Phase 1 (own folder ΓÇö it depends on node/ but ships separately)
Γö£ΓöÇΓöÇ php/                     # Phase 2
Γö£ΓöÇΓöÇ woocommerce-plugin/      # Phase 2
Γö£ΓöÇΓöÇ python/                  # Phase 3
ΓööΓöÇΓöÇ dotnet/                  # Phase 4
```

**License:** MIT, unless the repo owner tells you otherwise before you
commit the LICENSE file ΓÇö it's the standard, business-friendly choice for
SDKs (Stripe, Twilio, and most comparable providers ship MIT), but flag it
in your Phase-0 report as a decision you made rather than silently
assuming it's uncontroversial.

**Two different meanings of "publish" in this SOW ΓÇö do both, don't conflate
them:**
1. **Repository publication** (this section): push `linopay-providers` to
   GitHub as a public repo. Do this once, immediately, even before Phase 1
   has any code in it ΓÇö an empty public repo with a README describing
   what's coming is fine and normal for an open-source project.
2. **Package publication** (per phase, in each phase's Deliverables):
   actually publishing the built artifact to that language's real registry
   ΓÇö npm, Packagist, PyPI, NuGet. This requires the org to own the relevant
   namespace/scope on each registry (e.g., an `@linopay` npm org) ΓÇö if that
   doesn't exist yet, flag it in your report rather than publishing under a
   personal or placeholder account.

### Definition of Done (Phase 0)
- [ ] `linopay-providers` exists on GitHub, public, with the folder skeleton
      above (empty provider folders are fine at this point ΓÇö just the
      layout, README, LICENSE, CONTRIBUTING, and `.github/workflows/`
      committed).
- [ ] `git clone` of the public URL, from a clean directory, succeeds and
      shows the expected structure ΓÇö paste that clone command's output.

## 1. Ground truth: verify these before designing any SDK contract

Do not guess at any of the following ΓÇö open the actual files in
`lime-payments` and confirm current behaviour before writing a client
method against it. If a capability described below has changed since this
document was written, trust the code over this document and flag the
discrepancy in your report.

- **Channel authentication model:** a channel has a signing key (Key ID +
  PEM, downloaded once at rotation time), with an overlap window during
  rotation. Read `shared/LinoPay.Domain/Channels/ChannelKeyVersion.cs` and
  the channel key endpoints in `portal-api/LinoPay.Api.Functions/Channels/`.
- **One-off checkout (PIS payment):** `shared/LinoPay.Application/Transactions/CreatePisPaymentHandler.cs`
  and `shared/LinoPay.Application/Abstractions/IBankPaymentApi.cs`. This is
  the anonymous, deep-link/QR flow ΓÇö no debtor account is collected upfront.
- **Flexi-Payments (invoicing), as it exists TODAY:** `public-edge/LinoPay.Api.Functions.PublicEdge/InvoicePaymentFunctions.cs`
  (`GetInvoiceForPayment`, `PayInvoice`) and channel invoicing rules (grace
  period, fee absorption, late fee) via the `SetChannelInvoicingRules`
  endpoint in `portal-api/LinoPay.Api.Functions/Channels/ChannelsFunctions.cs`.
  **Important ΓÇö read this bullet carefully:** the "customer commits to a
  future date and it becomes an executable bank authorisation" capability
  described in company messaging is **not yet shipped on `main`** as of this
  SOW. A prior design/implementation pass exists in an unmerged branch, not
  in the mainline codebase. **Do not build an SDK method that pretends this
  exists.** Scope Phase 2's Flexi-Payment SDK surface to what
  `InvoicePaymentFunctions.cs` actually exposes today (create/fetch/cancel a
  pay-now invoice, read its QR/status). If you are asked to add a "schedule
  payment" SDK method, confirm with the product owner first whether the
  backend work has landed; do not stub it client-side.
- **Subscriptions (enrollment):** `shared/LinoPay.Application/Subscriptions/EnrollmentConsentHandlers.cs`
  ΓÇö the CIBA-push-then-redirect-fallback pattern. This is the correct
  pattern to mirror for any SDK/CLI flow that needs to wait on a bank
  authorisation asynchronously (poll a status endpoint; do not invent a
  webhook-only design if a polling endpoint already exists).
- **Webhooks:** `shared/LinoPay.Application/Invoicing/InvoiceWebhookNotifier.cs`
  shows the signing-secret model on the LinoPay side. Every SDK **must**
  ship a first-class `verifyWebhookSignature(payload, signature, secret)`
  helper ΓÇö this is one of the highest support-burden mistakes merchants make
  industry-wide, and getting it right in the SDK removes an entire category
  of integration bugs.
- **Mock bank sandbox for testing ΓÇö use this, do not build your own:**
  `lime-payments/infra/wiremock/pnz/` and `infra/wiremock/dia/` are real,
  working WireMock stub definitions for the PNZ/DIA bank sandbox, already
  used by `lime-payments`' own local stack (`infra/docker-compose.yml`,
  services `wiremock-pnz` / `wiremock-dia`, image `wiremock/wiremock:3.9.1`).
  Copy the relevant mapping JSON files (not the whole `lime-payments`
  checkout) into `linopay-providers/test-infra/wiremock/` and stand the same
  image up via Docker Compose in every provider's test stack. This is how
  every phase below tests real request/response shapes and real signature
  verification **without any real bank sandbox credentials, and without any
  network access to a real bank**, which is exactly what makes this safe to
  run unattended in CI and safe to hand to a person who won't be testing on
  their own machine.

## 2. Cross-cutting requirements ΓÇö apply to every phase, every language

1. **Never log or persist secret material.** Private keys, PEM contents, and
   webhook signing secrets must never appear in a log line, an exception
   message, or a stack trace, in any SDK or the CLI. Write a test that
   asserts this where practical (e.g., mock a logger, trigger an error path
   that touches the secret, assert the logger was never called with a string
   containing it).
2. **Sandbox vs. live must be explicit, never inferred.** Every SDK client
   is constructed with an explicit environment (`sandbox` / `live`), never a
   guess based on the key's shape or an ambient env var the caller didn't
   set themselves. "Sandbox" for these tests means the WireMock stack from
   ┬º1, not LinoPay's real hosted dev environment.
3. **Key rotation must not silently break an integration.** Each SDK must
   expose a way to check the current key's remaining validity (a
   `keyExpiresInDays()`-shaped call, backed by the real channel-key
   endpoint) so a merchant's own monitoring can alert before a key dies, not
   after.
4. **No SDK invents its own request/response shape.** Every method's
   request and response types must match the real contracts in
   `lime-payments/shared/LinoPay.Shared.Contracts/` byte-for-byte in meaning
   (field names may be idiomatically cased per language ΓÇö `camelCase` in JS,
   `snake_case` in Python ΓÇö but no field is added, dropped, or renamed in
   meaning without flagging it).
5. **Every SDK ships with a working example** that a developer can run
   against the containerized WireMock sandbox with zero setup beyond
   `docker compose up` ΓÇö not pseudocode in a README, an actual runnable file
   a machine can execute headlessly.
6. **Every provider's tests run via one containerized command that stands
   up, tests, and tears down.** Each provider folder gets its own
   `docker-compose.test.yml` (or reuses a shared one in `test-infra/` ΓÇö your
   call, document which) that at minimum brings up the WireMock sandbox
   dependency, runs that provider's real test suite against it, exits with
   the test runner's real exit code, and brings everything back down. The
   canonical invocation for every provider, referenced in every phase below,
   is:
   ```bash
   docker compose -f <provider>/docker-compose.test.yml up \
     --build --abort-on-container-exit --exit-code-from <provider>-tests
   docker compose -f <provider>/docker-compose.test.yml down -v
   ```
   Paste the real output of both commands in your report for every phase.

## 3. Phase 1 ΓÇö Node.js / TypeScript SDK + CLI

### Objectives
Formalise `demos/`'s signing and calling logic into a standalone, versioned,
independently publishable package inside `linopay-providers`. This is the
foundation the CLI and the company's own future language SDKs will be
judged against for shape and quality.

### Deliverables
- `linopay-providers/node/` ΓÇö a standalone npm package (own `package.json`,
  own versioning). Port the demo's signing and token-exchange logic here;
  `lime-payments/demos/` is left as reference only (see ┬º0, do not modify
  it as part of this SOW).
- Method surface (minimum): `linopay.payments.create(...)` /
  `linopay.payments.get(id)` (one-off PIS checkout), `linopay.invoices.create(...)`
  / `.get(id)` / `.cancel(id)` (Flexi-Payments, pay-now scope only ΓÇö see ┬º1),
  `linopay.channels.keyStatus()`, `linopay.webhooks.verify(payload, signature)`.
- `linopay-providers/cli/` ΓÇö a CLI distributed via
  `npm install -g @linopay/cli` (or `npx @linopay/cli <command>`), depending
  on `node/` as a library, with at minimum: `linopay init` (prompts
  for/reads Key ID + PEM path, writes an env-var template, does **not**
  write the key into any file that looks committable ΓÇö warn loudly if it
  detects a `.env` file about to be added to a git repo without a
  `.gitignore` entry for it), and `linopay verify` (fires one call against
  the configured sandbox and reports pass/fail in plain language).
- Bash installer script (`curl -fsSL .../install.sh | sh`) for Linux/macOS
  that installs the npm package if Node is present, and gives a clear,
  specific error (not a stack trace) if it is not. A separate PowerShell
  one-liner for Windows. Do not attempt to make one script serve all three
  OSes ΓÇö see the reasoning already agreed: shell interpretation avoids
  macOS Gatekeeper's notarization requirement entirely, which only applies
  to compiled/launched executables, not scripts handed to an interpreter.

### Definition of Done
- [ ] `npm run build` and `npm run lint` (or equivalent ΓÇö match whatever this
      package's own `package.json` scripts define) both exit 0.
- [ ] Unit tests exist for: request signing (given a known key + payload,
      assert the exact signed output ΓÇö not just "it didn't throw"), the
      webhook-verify helper (both a valid and a tampered signature case),
      and the never-logs-the-secret assertion from ┬º2.1.
- [ ] The containerized test command from ┬º2.6 passes for `node/`, 0
      failures, including the runnable example completing one real call
      against the **WireMock** sandbox as part of the same containerized
      run (not a separate manual step).
- [ ] `linopay init` followed by `linopay verify`, run inside a throwaway
      container against the WireMock sandbox, succeeds end to end as part
      of the same `docker compose` stack.

### Self-check script (run this, paste the output)
```bash
docker compose -f node/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from node-tests
docker compose -f node/docker-compose.test.yml down -v
```

## 4. Phase 2 ΓÇö PHP SDK + WooCommerce Plugin

### Objectives
The WooCommerce plugin is the priority deliverable of this phase, not an
add-on to the SDK. WooCommerce/WordPress is the dominant way small NZ
businesses stand up an online shop, and the plugin is a zero-code product
for a merchant who will never touch the SDK directly. Build the SDK far
enough to support the plugin fully; do not over-build the standalone SDK
surface ahead of what the plugin actually needs in this phase.

### Deliverables
- `linopay-providers/php/` ΓÇö Composer package, mirroring Phase 1's method
  surface (`payments`, `invoices`, `channels`, `webhooks->verify()`).
- `linopay-providers/woocommerce-plugin/` ΓÇö a WordPress plugin implementing
  `WC_Payment_Gateway`: registers LinoPay as a checkout payment method,
  `process_payment()` redirects to (or renders) the QR/authorisation step,
  a webhook receiver marks the WooCommerce order paid via
  `$order->payment_complete()` and redirects to the order-received page on
  confirmed payment. Settings screen: sandbox/live toggle, Key ID + PEM
  upload (store the PEM using WordPress's own encrypted-option mechanism,
  never in a plain option row or a file under the web root).
- Distribution: a downloadable zip built as a release artifact of this same
  repo (a GitHub Release / CI artifact, not hosted elsewhere) for this
  phase. Do **not** submit to the official WordPress.org plugin directory
  yet ΓÇö that has its own review process and an ongoing support-quality bar;
  that's a separate, later decision.

### Definition of Done
- [ ] The containerized test command from ┬º2.6 passes for `php/` (PHPUnit
      inside the container), 0 failures, covering the same signing/webhook-
      verify/no-secret-logging cases as Phase 1.
- [ ] **The WooCommerce checkout flow is verified by an automated,
      containerized, headless test ΓÇö not a manual click-through.** Build
      `woocommerce-plugin/docker-compose.test.yml` to stand up: WordPress +
      WooCommerce (official `wordpress` image, WooCommerce installed via
      WP-CLI in an init step), the plugin (mounted/installed and activated
      via WP-CLI), the WireMock PNZ/DIA sandbox from ┬º1, and a headless
      browser test runner (Playwright, containerized) that: creates a
      product via the WooCommerce REST API, drives a real browser through
      checkout choosing LinoPay, completes the sandbox bank authorisation
      against WireMock's stubbed responses, then polls the WooCommerce REST
      API for the order to reach `processing`/`completed` with the correct
      total. The whole stack must come up, run this test, report a real
      pass/fail, and tear down via one `docker compose ... up
      --abort-on-container-exit` / `down -v` pair ΓÇö see ┬º2.6's canonical
      invocation.
- [ ] No PHP warnings/notices appear in the WordPress debug log
      (`WP_DEBUG=true`, `WP_DEBUG_LOG=true` set in the compose stack) across
      that same automated run ΓÇö assert this in the test itself (fail the
      test if the log file is non-empty of warnings/notices), don't just
      eyeball it.
- [ ] The PEM is confirmed never to be stored in plaintext ΓÇö assert this
      programmatically in the test (query the WordPress database container
      directly for the relevant option row and assert it does not contain
      the raw PEM string), not by a human looking at it.

### Self-check script
```bash
docker compose -f php/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from php-tests
docker compose -f php/docker-compose.test.yml down -v

docker compose -f woocommerce-plugin/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from woocommerce-e2e
docker compose -f woocommerce-plugin/docker-compose.test.yml down -v
```

## 5. Phase 3 ΓÇö Python SDK

`linopay-providers/python/`. Same method surface and cross-cutting
requirements as Phase 1, packaged for PyPI (`pip install linopay`).
Definition of Done mirrors Phase 1's exactly (containerized build/lint/test
all green against the WireMock sandbox, signing + webhook-verify + no-
secret-logging unit tests, one real sandbox call in a runnable example,
output pasted in the report). Do not start this phase until Phase 1's Node
SDK is complete and its Definition of Done has been signed off ΓÇö Phase 1 is
where design mistakes should get caught, not repeated three more times.

## 6. Phase 4 ΓÇö .NET SDK

`linopay-providers/dotnet/`. Same again, packaged as a NuGet package.
Because `lime-payments` is this codebase's own native stack, you have
direct access to the real contract types in
`lime-payments/shared/LinoPay.Shared.Contracts/` ΓÇö reference them for shape
where sensible rather than redefining equivalent DTOs from scratch, but keep
the SDK itself as an independent package with no dependency on
`lime-payments`'s internal packages ΓÇö a public artifact never references
internal-only NuGet feeds or projects.

## 7. Explicitly out of scope for this SOW

Do not build these without a separate, explicit go-ahead: a Java SDK, a
Ruby SDK, a standalone compiled (Go/Rust) CLI binary, code-signing/
notarization for macOS, or submission of the WooCommerce plugin to the
official WordPress.org directory. These are real future work, not omissions
ΓÇö they are deliberately sequenced after real usage data justifies them.

## 8. Reporting format required at the end of every phase

For each phase, your report must include, in this order:
1. **File list** of everything added or changed, and which repo it's in
   (it should almost always be `linopay-providers`, never `lime-payments`).
2. **Definition of Done**, copied from the relevant section above, with
   each box checked only if you personally ran the corresponding containerized
   check and it passed ΓÇö include the actual command output, not a
   paraphrase, and confirm the teardown (`down -v`) command also ran
   cleanly.
3. **Deviations** from this SOW, and why, if any ΓÇö including anywhere you
   found this document to be wrong about the current state of the
   `lime-payments` codebase (see ┬º1's instruction to trust the code over
   this document).
4. **What you could not verify**, and why. Given everything in this SOW is
   designed to run unattended in Docker with no real credentials needed,
   "I couldn't test it" should be rare ΓÇö if it happens, say exactly what
   container or dependency was missing, not just that a step was skipped.
   Do not claim something works if you did not personally see the
   containerized run pass.
