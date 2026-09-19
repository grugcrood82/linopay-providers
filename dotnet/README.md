# `LinoPay.Sdk` — the official LinoPay .NET SDK

Phase 4 deliverable. One `dotnet add package LinoPay.Sdk` away from the
same merchant surface the TypeScript SDK provides — every method
mirrors `@linotech/sdk` field-for-field, only the wire shapes are
idiomatic C#.

## Method surface

| Method | Endpoint | Auth |
|---|---|---|
| `client.Payments.CreateAsync(args)` | `POST /v1/payments/qrcode` | Channel-signed JWT (RS256, kid, claims: amount / currency / txnRef) |
| `client.Payments.GetAsync(sagaId)` | `GET /v1/payments/transactions/{id}/channel-status` | Channel-signed JWT |
| `client.Invoices.CreateAsync(args)` | `POST /api/merchants/{m}/channels/{c}/invoices` | Exchanged scoped token (`invoices:write`) |
| `client.Invoices.GetAsync(id)` | `GET /api/merchants/{m}/invoices/{id}` | Exchanged scoped token (`invoices:read`) |
| `client.Invoices.CancelAsync(id)` | `POST /api/merchants/{m}/invoices/{id}/cancel` | Exchanged scoped token (`invoices:write`) |
| `client.Channels.KeyStatusAsync()` | `GET /api/merchants/{m}/channels/{c}/keys` | Exchanged scoped token; surfaces active-key `daysRemaining` |
| `WebhookSignature.Verify(args)` | (your endpoint) | HMAC-SHA256 `v1=<hex>` over `<unix-seconds>.<body>`, constant-time compare |

Wire shapes come from real `lime-payments` contracts (the
`LinoPay.Shared.Contracts` records + the body envelope convention used
in `demos/server/lino.mjs`). DTOs in this package are
**independent** — they match the wire shapes but do not import the
real types. Per the SOW §4, no internal-only NuGet feed is
referenced.

The package is **private until `@linotech` is set up** on the NuGet
registry; until then the consuming project (e.g. a merchant's `Api/`
project) uses a `ProjectReference` to the SDK as a local source dep.

## Quickstart

```csharp
using Linopay.Sdk;

var linopay = LinopayClient.Create(new Config
{
    BaseUrl = "https://api.lino.dev",
    KeyId = Environment.GetEnvironmentVariable("LINOPAY_KEY_ID")!,
    PrivateKeyPem = File.ReadAllText("channel.pem"),
    BankCode = "ANZ",
    Environment = "sandbox",    // ← always explicit, never inferred
});

var payment = await linopay.Payments.CreateAsync(new CreatePaymentArgs(
    AmountCents: 199,
    Currency: "NZD"));
Console.WriteLine(payment.SagaId);
Console.WriteLine(payment.ConsentUrl);
```

## Verification (CI / pre-merge)

```bash
docker compose -f dotnet/docker-compose.test.yml up \
  --build --abort-on-container-exit --exit-code-from dotnet-tests
docker compose -f dotnet/docker-compose.test.yml down -v
```

That stands up WireMock (with the LinoPay-API mappings), builds the
SDK + the runnable example, runs the full xUnit suite against it,
tears down. No real bank or LinoPay credentials required.

`Passed!  - Failed: 0, Passed: 24, Skipped: 3, Total: 27` — the 3
e2e cases (`EndToEndTests.cs` `[SkippableFact]`) skip when
`LINOPAY_E2E_BASE_URL` is unset (developer-machine dev mode) and
become real assertions when it's set (CI / docker-compose).

## Why these design choices mirror `node/`

- **Method surface** is byte-for-byte the same: a merchant who learns
  one language's SDK recognises the other by name, not by reading
  docs. See `cross_repo_map.md` in the docs hub for the per-endpoint
  wire-shape sources.
- **`{ data: ... }` envelope unwrap** happens once, in `LinopayHttp`.
  Callers get the inner value directly.
- **Token cache** (`ChannelTokenCache`) re-uses the exchanged scoped
  token across invoice calls. Re-exchange 30 s before the real
  expiry so a call never starts with a token that dies mid-flight.
- **Webhook verify** first matches the signature in constant time,
  then checks the replay window — a forgery must surface as
  `wrong-secret`, not the misleading `replay-out-of-window`.
- **No new Newtonsoft.Json dependency.** `System.Text.Json` with
  web defaults handles camelCase ↔ PascalCase automatically.
- **No copying of `LinoPay.Shared.Contracts/` into this repo.** The
  DTOs that ship here are independent and copy exactly what's on
  the wire; when the upstream contracts change, the SDK's DTOs
  change with them in the same PR.

## License

MIT — see [`../LICENSE`](../LICENSE).
