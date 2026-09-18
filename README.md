# linopay-providers

Official LinoPay client SDKs, CLI, and platform plugins.

This is the public, open-source home for everything a merchant needs to
integrate with [LinoPay](https://github.com/grugcrood82/lime-payments).
Each language / platform lives in its own top-level folder with its own
package, version, tests, and release pipeline; they share nothing but
a header layout and a contract against the same upstream APIs.

## What's in here

| Folder | Audience | Status |
|--------|----------|--------|
| [`node/`](./node)         | TypeScript / Node.js merchants | Phase 1 |
| [`cli/`](./cli)           | Anyone who wants a 5-minute terminal integration | Phase 1 |
| [`php/`](./php)           | PHP merchants | Phase 2 |
| [`woocommerce-plugin/`](./woocommerce-plugin) | WooCommerce store owners (no code) | Phase 2 |
| [`python/`](./python)     | Python merchants | Phase 3 |
| [`dotnet/`](./dotnet)     | .NET merchants | Phase 4 |
| [`test-infra/`](./test-infra) | Shared WireMock sandbox for unattended CI | All phases |

Each folder has its own `README.md` with the language-specific install /
quickstart, and its own `docker-compose.test.yml` so the verification
loop is `docker compose up --build --abort-on-container-exit` —
nothing manual, no real credentials required.

## Relationship to `lime-payments`

The upstream application (`grugcrood82/lime-payments`, private) is the
implementation LinoPay itself runs. This repository is the merchant-
facing surface area — every method here maps to a real wire-format
endpoint in `lime-payments`. We do not invent request or response
shapes; the SDKs mirror the contracts in
`lime-payments/shared/LinoPay.Shared.Contracts/` byte-for-byte in
meaning (idiomatic casing per language only).

## License

MIT — see [`LICENSE`](./LICENSE).

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). TL;DR: every PR must run
its provider's containerized test command and paste the real output —
unverified work doesn't land here.
