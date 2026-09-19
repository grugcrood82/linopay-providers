import { randomUUID } from 'node:crypto';
import { callJsonWithChannelJwt, type HttpClient } from './http.js';
import type { LinopayConfig } from './config.js';
import { LinopayConfigError } from './errors.js';

/**
 * One-off payment (Phase A in the demo).
 *
 * `createPayment` is the only SDK entry point that uses the raw
 * channel-signed instruction JWT — the one carrying `amount`,
 * `currency`, and `txnRef` as signed claims. That signature is what
 * tells the LinoPay gateway "this channel authorises this specific
 * money movement", so it isn't interchangeable with the exchanged
 * scoped token the invoices endpoints use.
 *
 * `getPaymentStatus` polls the channel-authenticated status endpoint
 * that the demo at `lime-payments/demos/server/lino.mjs` calls
 * `channel-status`. The poll uses the channel-signed token too, so a
 * merchant can call it without first exchanging a token (good for
 * "did the customer ever pay?" lookups where there's no invoice to
 * read).
 */
export interface CreatePaymentInput {
  amountCents: number;
  currency?: string;
  reference?: string;
  targetBank?: string;
  imageDimensions?: { width: number; height: number };
}

export interface CreatePaymentResult {
  sagaId: string;
  qrCodeImage: string;
  consentUrl: string;
  status: string;
}

export interface PaymentStatus {
  transactionSagaId: string;
  status: string;
  amount: number;
  currency: string;
}

export interface PaymentsClient {
  create(input: CreatePaymentInput): Promise<CreatePaymentResult>;
  get(sagaId: string): Promise<PaymentStatus>;
}

export function createPaymentsClient(cfg: LinopayConfig, http: HttpClient): PaymentsClient {
  return {
    async create(input: CreatePaymentInput): Promise<CreatePaymentResult> {
      const amountCents = input.amountCents;
      if (!Number.isInteger(amountCents) || amountCents <= 0) {
        throw new LinopayConfigError(
          `amountCents must be a positive integer (got ${JSON.stringify(amountCents)}).`,
        );
      }
      const reference = input.reference ?? randomUUID();
      const currency = input.currency ?? 'NZD';
      const targetBank = input.targetBank ?? cfg.bankCode;
      if (!targetBank) {
        throw new LinopayConfigError(
          'No bank code configured. Set LINOPAY_BANK_CODE or pass targetBank explicitly.',
        );
      }

      type QrResponse = {
        transactionSagaId: string;
        qrCodeImage: string;
        consentUrl: string;
        status: string;
      };

      const data = await callJsonWithChannelJwt<QrResponse>(
        cfg,
        http,
        '/v1/payments/qrcode',
        {
          riskNote: null,
          targetBank,
          imageDimensions: input.imageDimensions ?? { width: 320, height: 320 },
        },
        {
          txnRef: reference,
          amount: (amountCents / 100).toFixed(2),
          currency,
        },
      );
      return {
        sagaId: data.transactionSagaId,
        qrCodeImage: data.qrCodeImage,
        consentUrl: data.consentUrl,
        status: data.status,
      };
    },

    async get(sagaId: string): Promise<PaymentStatus> {
      if (!sagaId) {
        throw new LinopayConfigError('sagaId is required to look up a payment.');
      }
      type StatusResponse = {
        transactionSagaId: string;
        status: string;
        amount: number;
        currency: string;
      };
      const data = await callJsonWithChannelJwt<StatusResponse>(
        cfg,
        http,
        `/v1/payments/transactions/${encodeURIComponent(sagaId)}/channel-status`,
        // The status endpoint has no body — but our callJsonWithChannelJwt
        // helper requires one to populate the JWT. Use an empty object.
        {},
        { txnRef: sagaId },
      );
      // `http.callJson` has already pulled out the `{ data: ... }`
      // envelope, so `data` is the inner row directly.
      return data;
    },
  };
}
