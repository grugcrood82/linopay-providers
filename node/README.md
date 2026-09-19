# @linotech/sdk

Official LinoPay Node.js / TypeScript SDK. One-call access to the
merchant-facing surface — payments, Flexi-Payment invoices
(pay-now scope), channel key status, and outbound webhook signature
verification.

The SDK is the standalone, versioned replacement for what used to live
inline in `lime-payments/demos/server/lino.mjs`. The same wire shapes,
the same signing rules, the same `{ data: ... }` envelope conventions;
just packaged so a merchant can `npm install @linotech/sdk` and stop
re-implementing RS256 + token exchange + envelope unwrapping themselves.

## What you get

- `linopay.payments.create(input)` — start a one-off PIS checkout
- `linopay.payments.get(sagaId)` — poll a previously-created payment
- `linopay.invoices.create(input)` — issue a Flexi-Payment (dormant invoice QR)
- `linopay.invoices.get(invoiceId)` — re-read a Flexi-Payment
- `linopay.invoices.cancel(invoiceId)` — cancel / recall a Flexi-Payment
- `linopay.channels.keyStatus()` — read the channel's active signing-key state
- `linopay.webhooks.verify(input)` — verify an `X-LinoPay-Signature` delivery

## Install

```bash
npm install @linotech/sdk
```

Requires Node ≥ 20. The only runtime dependency is `jose` (RS256
signing). No native bindings, no `node-gyp`.

## Quickstart

```ts
import { createLinopay } from '@linotech/sdk';
import { readFileSync } from 'node:fs';

const linopay = createLinopay({
  baseUrl: 'https://api.lino.dev',
  keyId: process.env.LINOPAY_KEY_ID!,
  privateKeyPem: readFileSync(process.env.LINOPAY_PEM_PATH!, 'utf8'),
  bankCode: 'ANZ',
  environment: 'sandbox',  // ← always explicit, never inferred
});

const payment = await linopay.payments.create({ amountCents: 199, currency: 'NZD' });
console.log(payment.sagaId);    // server-side saga id
console.log(payment.consentUrl); // deep-link / QR redirect
```

## Verification (CI / pre-merge)

```bash
docker compose -f node/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from node-tests
docker compose -f node/docker-compose.test.yml down -v
```

That stands up WireMock with the LinoPay-API mappings and runs the
entire vitest suite against it, then tears down. Nothing manual.

## Where things come from

- Wire shapes: `lime-payments/demos/server/lino.mjs` for the { data: ... }
  envelope, and `lime-payments/shared/LinoPay.Shared.Contracts/` for
  the typed records. We mirror contracts byte-for-byte in meaning
  (only casing changes per language).
- Webhook signature: `lime-payments/shared/LinoPay.Application/Abstractions/WebhookSignature.cs`
- Token exchange: `lime-payments/shared/LinoPay.Application/ChannelAuth/ChannelAccessToken.cs`

## License

MIT — see [`../LICENSE`](../LICENSE).
