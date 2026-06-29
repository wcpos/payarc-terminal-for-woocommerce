# PayArc Connect V3 Mock Contract

Date: 2026-06-29
Status: MOCK_CONTRACT_CREATED

This is not live sandbox evidence. No PayArc account, terminal, sandbox token, callback delivery, or hardware behavior was verified while creating this contract.

This mock contract is derived from the official PayArc Connect V3 documentation so TDD and unit work can proceed without a PayArc account. It is intentionally limited to documentation-shaped fixtures and must not be treated as proof of live API behavior.

Live Task 0 remains required before production, end-to-end, or hardware validation. Before enabling real PayArc traffic, rerun the sandbox spike with real credentials supplied through a secure channel and replace or annotate these assumptions with observed evidence.

## Source endpoints

| Fixture key | PayArc documentation endpoint |
| --- | --- |
| `sale_request` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `sale_response_success_initiated` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `sale_response_failure` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `get_transaction_success` | [Get Transaction](https://docs.payarc.net/reference/get_v3-transactions-traceid) |
| `callback_success` | [Sale callback example](https://docs.payarc.net/reference/createsaletransaction) and [Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3) |
| `callback_decline` | [Sale callback example](https://docs.payarc.net/reference/createsaletransaction) and [Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3) |
| `callback_timeout` | [Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3) |
| `cancel_request` | [Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel) |
| `cancel_response_success` | [Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel) |
| `cancel_response_failure_already_processed` | [Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel) |

## Contract notes

- Fixture file: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/fixtures/payarc-connect-v3.json`.
- All identifiers, tokens, terminal IDs, transaction IDs, charge IDs, and timestamps are fake and safe for tests.
- Amounts use PayArc V3's documented amount object shape: `total`, `subtotal`, `currency`, `tip`, `tax`, and `approved` where the response/callback shape supports it.
- The fixture uses `109` minor units for `$1.09` so amount-unit assumptions stay explicit.
- Metadata is included as string key/value data: `metadata.order_id`, `metadata.terminal_id`, and `metadata.mode`.

## Unverified assumptions

These are documentation-derived assumptions only:

1. Direct bearer authentication works with the merchant PayArc Connect token.
2. A sale initiation response synchronously includes a `traceId` that can be used for get/cancel calls.
3. Amount fields are minor units in real request, get, and callback payloads.
4. Real statuses and error shapes match the docs/examples, including `SUCCESS`, `DECLINE`, and `TIMEOUT`.
5. PayArc echoes caller-supplied `metadata` consistently in callbacks and get-transaction responses.

## Callback handling rule

Callbacks are triggers only and success must be verified by `GET /v3/transactions/{traceId}` before fulfilling an order. Unit tests may use callback fixtures to drive state transitions, but production logic must treat the get-transaction response as the authoritative confirmation until live Task 0 proves otherwise.
