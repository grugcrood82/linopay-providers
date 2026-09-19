# test-infra — Shared WireMock sandbox

WireMock stubs the SDK and CLI exercise in containerized tests. There is no
real bank involved, and no real LinoPay API — every request from the SDK
under test is answered by one of the mappings below.

Two layers of mocks:

| Path                                              | What it stands in for                                                                                  |
|---------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| `wiremock/pnz/mappings/*.json`                    | PNZ Open Banking sandbox (`accounts`, `party`, `domestic-payments`, OAuth) — copied from `lime-payments/infra/wiremock/pnz/`. The bank's CIBA + consent dance is mocked here, not in the SDK code. |
| `wiremock/dia/mappings/*.json`                    | DIA — government registry `nzbn` lookups.                                                             |
| `wiremock/meta/mappings/*.json`                   | Meta WhatsApp Cloud API (for P2P — not exercised by Phase 1's SDK surface, but kept for completeness). |
| `wiremock/linopay/mappings/linopay.json`          | The LinoPay-API surface Phase 1 needs (`POST /v1/auth/channel-token`, payments.qrcode, invoices, channel keys). These mirror the real envelopes from `lime-payments/demos/server/lino.mjs` so the SDK exercises actual response shapes. |

The lime-payments repository owns the canonical mappings (these were
copied from there at Phase 1 commit time). If `lime-payments` updates
its WireMock fixtures, this directory should be re-synced in the same
PR — drift between the two means the SDK test sandboxes no longer match
the production contract.

## Validating the mappings

The `shared-infra-ci` workflow (`/.github/workflows/shared-infra-ci.yml`)
parses every JSON file in this directory with `jq` on every PR. Bad
JSON is caught there, before any containerized test even starts.
