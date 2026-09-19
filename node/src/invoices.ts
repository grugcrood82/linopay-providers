import { LinopayConfigError } from './errors.js';
import type { HttpClient } from './http.js';
import type { ChannelTokenCache } from './tokens.js';
import type { LinopayConfig } from './config.js';

/**
 * Flexi-Payments (invoicing), pay-now scope only.
 *
 * The SOW (§1, Flexi-Payment bullet) is explicit: do NOT build an SDK
 * method for the "customer commits to a future date" capability the
 * company messaging describes — that backend work isn't on `main` yet.
 * Scope is exactly:
 *   - create a pay-now invoice (dormant QR)
 *   - get one (re-read, so the merchant screen can settle)
 *   - cancel / recall
 *
 * Auth: every call in this group uses the exchanged scoped token
 * (NOT the raw channel-signed JWT). The exchanged token carries
 * `invoices:write` for create/cancel, `invoices:read` for get — but
 * `invoices:write` implies read access too (see ChannelAccessToken.
 * HasScope), so a single cached token suffices for all three.
 */
export interface CreateInvoiceInput {
  amountCents: number;
  currency?: string;
  reference?: string;
  paymentWindowDays?: number;
}

export interface Invoice {
  invoiceId: string;
  invoiceCode: string;
  amount: number;
  currency: string;
  reference: string | null;
  status: string;
  dueAt: string;
  expiresAt: string;
  paymentWindowDays: number;
  qrCodeUrl: string;
  qrCodeImage: string;
}

export interface InvoicesClient {
  create(input: CreateInvoiceInput): Promise<Invoice>;
  get(invoiceId: string): Promise<Invoice>;
  cancel(invoiceId: string): Promise<Invoice & { cancelledAt?: string }>;
}

export function createInvoicesClient(
  _cfg: LinopayConfig,
  http: HttpClient,
  tokens: ChannelTokenCache,
): InvoicesClient {
  void _cfg; // reserved for per-call explicit key-id / merchant-id overrides
  return {
    async create(input: CreateInvoiceInput): Promise<Invoice> {
      const amountCents = input.amountCents;
      if (!Number.isInteger(amountCents) || amountCents <= 0) {
        throw new LinopayConfigError(
          `amountCents must be a positive integer (got ${JSON.stringify(amountCents)}).`,
        );
      }
      const token = await tokens.exchange(['invoices:write']);
      const body = {
        amount: Number((amountCents / 100).toFixed(2)),
        currency: input.currency ?? 'NZD',
        reference: input.reference ?? null,
        paymentWindowDays: input.paymentWindowDays ?? 14,
      };
      type Response = Invoice;
      const resp = await http.callJson<Response>(
        `/api/merchants/${encodeURIComponent(token.merchantId)}` +
          `/channels/${encodeURIComponent(token.channelId)}/invoices`,
        {
          method: 'POST',
          token: token.accessToken,
          body,
        },
      );
      return resp;
    },

    async get(invoiceId: string): Promise<Invoice> {
      if (!invoiceId) {
        throw new LinopayConfigError('invoiceId is required to look up an invoice.');
      }
      const token = await tokens.exchange(['invoices:read']);
      type Response = Invoice;
      const resp = await http.callJson<Response>(
        `/api/merchants/${encodeURIComponent(token.merchantId)}/invoices/${encodeURIComponent(invoiceId)}`,
        { method: 'GET', token: token.accessToken },
      );
      return resp;
    },

    async cancel(invoiceId: string): Promise<Invoice & { cancelledAt?: string }> {
      if (!invoiceId) {
        throw new LinopayConfigError('invoiceId is required to cancel an invoice.');
      }
      const token = await tokens.exchange(['invoices:write']);
      type Response = Invoice & { cancelledAt?: string };
      const resp = await http.callJson<Response>(
        `/api/merchants/${encodeURIComponent(token.merchantId)}/invoices/${encodeURIComponent(invoiceId)}/cancel`,
        { method: 'POST', token: token.accessToken, body: {} },
      );
      return resp;
    },
  };
}
