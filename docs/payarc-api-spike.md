# PayArc Sandbox API Spike

Date: 2026-06-29
Status: MOCK_CONTRACT_CREATED

No live PayArc sandbox evidence has been collected yet. The required real PayArc sandbox values were not present in the environment, so no PayArc API calls were made and no fake credentials, terminal IDs, callback payloads, or sandbox results were fabricated as live results.

A documentation-derived mock contract now exists to unblock TDD and unit development without a PayArc account:

- Contract notes: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-mock-contract.md`
- JSON fixtures: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/fixtures/payarc-connect-v3.json`

Implementation may proceed only against these mocks while live PayArc integration remains gated. Do not claim real PayArc API, sandbox, callback, terminal, cancel, or hardware behavior until Task 0 is rerun with real PayArc context and this spike records sanitized live evidence.

## MOCK_CONTRACT_CREATED

The mock contract is derived from official PayArc Connect V3 documentation:

- [Sale](https://docs.payarc.net/reference/createsaletransaction)
- [Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3)
- [Get Transaction](https://docs.payarc.net/reference/get_v3-transactions-traceid)
- [Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel)

The contract is not live sandbox evidence. It exists only to support mock-only Task 1 development, unit tests, and contract-shaped adapters until real credentials and terminal access are available.

## Live Task 0 remains required

Provide these real sandbox values to the operator/agent through a secure channel before rerunning Task 0:

- `PAYARC_CONNECT_TOKEN`: PayArc Connect bearer token.
- `PAYARC_TENANT_ID`: PayArc tenant ID, expected to be 12 digits.
- `PAYARC_TERMINAL_ID`: PAX terminal ID / serial, expected to be 10 digits.
- `PAYARC_CALLBACK_URL`: Public HTTPS callback URL able to receive PayArc callbacks.

Previously observed environment-variable availability, without printing values:

- `PAYARC_CONNECT_TOKEN`: missing.
- `PAYARC_TENANT_ID`: missing.
- `PAYARC_TERMINAL_ID`: missing.
- `PAYARC_CALLBACK_URL`: missing.
- Other `PAYARC_*` variable names present: none.

## Authentication

No authentication behavior was observed because the PayArc Connect token was not present. No request was sent to PayArc.

Mock-only development assumption:

- Direct bearer authentication is represented as a docs-derived assumption only.

Required live evidence before production or hardware validation:

- Whether `/v3/transactions/sale` accepts the configured bearer token directly.
- HTTP status and sanitized response shape for the sale request.
- If authentication fails, whether an access-token exchange is required.

## Sale Response

No sale response was observed because required sandbox context was missing. No `/v3/transactions/sale` request was sent.

Mock-only development assumption:

- The PayArc docs define a successful initiation response containing `traceId`; the fixture models that as a synchronous trace ID returned before final terminal processing.

Required live evidence before production or hardware validation:

- Actual HTTP status.
- Sanitized response shape.
- Whether a synchronous `traceId` is returned.
- Whether the response echoes or otherwise identifies the client-generated `transactionId`.

## Amount Units

No amount unit behavior was observed because no sale/get/callback responses were available.

Mock-only development assumption:

- The fixture uses documented minor units/cents, including `109` for `$1.09`.

Required live evidence before production or hardware validation:

- Whether request and response `amount.total` / `amount.approved` values are minor units, e.g. `109` for `$1.09`, or decimal units.
- Whether the authoritative get-transaction payload contains the expected `amount` object.

## Callback

No callback behavior was observed because no public callback URL was present and no sale request was sent.

Mock-only development assumption:

- Callback fixtures model success, decline, and timeout shapes from the docs. The callbacks are triggers only and success must be verified by `GET /v3/transactions/{traceId}` before fulfillment.

Required live evidence before production or hardware validation:

- Whether the callback includes `traceId`.
- Whether the callback echoes `transactionId`.
- Exact observed status values for approval, decline, timeout, and cancel when practical.
- Whether callback `amount.total` / `amount.approved` use minor units.
- Whether callback includes original `metadata.order_id`.

## Cancel

No cancel behavior was observed because no synchronous `traceId` was available and no sale request was sent.

Mock-only development assumption:

- Cancel request/response fixtures follow the docs: cancel accepts `tenantId` and `terminalId` for a pending transaction trace, returns `SUCCESS` when cancelled, and returns a failure error when the transaction can no longer be cancelled.

Required live evidence before production or hardware validation:

- Whether PayArc accepts cancel before card presentation.
- Actual HTTP status and sanitized response shape for cancel.
- Exact failure shape if the terminal has already started processing or cancel is not supported for the observed state.

## Plan Impact

Decision: Task 1 can proceed in mock-only mode using the docs-derived mock contract. Live PayArc integration remains gated on Task 0.

Current implementation decision: MOCK_CONTRACT_CREATED. Build adapters and unit tests against the fixture contract only; do not enable production or hardware validation until the live spike supplies sanitized evidence.
