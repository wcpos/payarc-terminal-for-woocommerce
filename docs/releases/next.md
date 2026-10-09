# Unreleased (the `next` line, WCPOS 2.0)

## Added

- **A server adapter for the WCPOS Pro 2.0 payments base.** With Pro 2.0 active, the plugin
  registers `PayArc_Server_Provider`, so the POS app drives a PayArc terminal through Pro's
  ledger: a sale per ledger row (the row id is the `X-Idempotency-Key`), polling by `traceId`,
  cancellation, authenticated callbacks on Pro's route, and refunds as terminal commands through
  `POST /v3/transactions/refund` (new on the client). Pro's provider conformance suite runs against
  the real adapter over a scripted PayArc in CI, with the transcripts committed. Without a
  compatible Pro the plugin behaves as before.
- **Refund requests PayArc does not answer are asked about again** under the same idempotency key,
  saved on the refund record before the request, until PayArc answers with the refund's `traceId`
  or staff are told to check the PayArc dashboard. A refund's terminal outcome, delivered on the
  callback, is noted on the order.
