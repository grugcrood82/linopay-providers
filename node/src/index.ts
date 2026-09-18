import type { LinopayConfig } from './config.js';
import { createHttpClient } from './http.js';
import { ChannelTokenCache } from './tokens.js';
import { createPaymentsClient, type PaymentsClient } from './payments.js';
import { createInvoicesClient, type InvoicesClient } from './invoices.js';
import { createChannelsClient, type ChannelsClient } from './channels.js';
import {
  verifyWebhook as _verifyWebhook,
  computeSignature as _computeSignature,
  type VerifyInput,
  SIGNATURE_HEADER,
  TIMESTAMP_HEADER,
  REPLAY_WINDOW_SECONDS,
} from './webhooks.js';

/**
 * Top-level SDK entry point.
 *
 *   const linopay = createLinopay(config);
 *   const saga = await linopay.payments.create({ amountCents: 199, currency: 'NZD' });
 *   const invoice = await linopay.invoices.create({ amountCents: 1000 });
 *   const key = await linopay.channels.keyStatus();
 *   verifyWebhook({ body, timestampHeader, signatureHeader, secret });
 *
 * No method touches the network without going through the shared
 * {@link HttpClient}, so the `Authorization` header shape, the
 * `X-Channel-KeyId` header shape, the `{ data: ... }` envelope unwrap,
 * and the error-translation rules are all in exactly one place.
 */
export interface Linopay {
  readonly payments: PaymentsClient;
  readonly invoices: InvoicesClient;
  readonly channels: ChannelsClient;
  /** Re-exported so consumers don't need a second import. */
  readonly webhooks: WebhooksSurface;
}

export interface WebhooksSurface {
  verify(input: VerifyInput): void;
  readonly SIGNATURE_HEADER: string;
  readonly TIMESTAMP_HEADER: string;
  readonly REPLAY_WINDOW_SECONDS: number;
  /**
   * Computes a `v1=<hex>` signature for testing. Production code
   * receiving webhooks should only call `verify`; `computeSignature`
   * is here so a merchant's own test suite (or a server-side
   * dispatcher) can build signatures that the verifier checks.
   */
  computeSignature(secret: string, unixSeconds: number, body: string): string;
}

export function createLinopay(cfg: LinopayConfig): Linopay {
  const http = createHttpClient(cfg);
  const tokens = new ChannelTokenCache(cfg, http);
  const payments = createPaymentsClient(cfg, http);
  const invoices = createInvoicesClient(cfg, http, tokens);
  const channels = createChannelsClient(cfg, http, tokens);
  const webhooks: WebhooksSurface = {
    verify: _verifyWebhook,
    computeSignature: _computeSignature,
    SIGNATURE_HEADER,
    TIMESTAMP_HEADER,
    REPLAY_WINDOW_SECONDS,
  };
  return { payments, invoices, channels, webhooks };
}

// Re-exports for callers who'd rather import the surface types from
// the package root than from each module.
export type {
  LinopayConfig,
  LinopayEnvironment,
  Logger,
} from './config.js';
export { loadConfigFromEnv } from './config.js';
export { LinopayApiError, LinopayConfigError, LinopayWebhookVerificationError } from './errors.js';
export type {
  CreatePaymentInput,
  CreatePaymentResult,
  PaymentStatus,
  PaymentsClient,
} from './payments.js';
export type {
  CreateInvoiceInput,
  Invoice,
  InvoicesClient,
} from './invoices.js';
export type {
  ChannelKeyStatus,
  ChannelKeyVersion,
  ChannelsClient,
} from './channels.js';
export type { VerifyInput } from './webhooks.js';
