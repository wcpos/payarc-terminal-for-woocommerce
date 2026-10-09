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

## Changed

- **Pro-only at 2.0.** The plugin registers everything from one `plugins_loaded` hook at priority 30
  behind `wcpos_pro_requires('2.0.0')`: without a compatible WCPOS Pro, an admin notice and nothing
  else (no gateway, no AJAX actions, no callback route, no adapter). Activation records the
  requirement for Pro's own notice.
- **Web checkout removed.** The gateway is for staff on the POS: available on POS requests and, to a
  user who may run the POS, on the order-pay page when POS → Settings → Checkout has it on; never on
  the shop's checkout. The "Enable PayArc Terminal on the online checkout" checkbox is gone and a
  saved value counts for nothing; the settings page says where the switch is. The connection checks
  on save (Connect after a mode or credential change, HTTPS callback in Live) apply when the POS
  switch is on.
