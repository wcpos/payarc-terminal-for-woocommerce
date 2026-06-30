# PayArc Connect V3 Fixture Notes

Date: 2026-06-29
Status: Superseded by merchant-test-ready implementation on 2026-06-30.

This file is retained only as historical fixture documentation for regression tests. The active plugin setup no longer depends on a fixture-only setup path: merchants press **Connect PayArc** to call PayArc Login and terminal discovery from their WordPress admin.

Current active validation guide: `docs/payarc-sandbox-validation.md`.

## Source endpoints

| Fixture key | PayArc documentation endpoint |
| --- | --- |
| `sale_request` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `sale_response_success_initiated` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `sale_response_failure` | [Sale](https://docs.payarc.net/reference/createsaletransaction) |
| `get_transaction_success` | [Get Transaction](https://docs.payarc.net/reference/get_v3-transactions-traceid) |
| `callback_success` | [Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3) |
| `cancel_request` | [Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel) |

Fixtures contain fake identifiers and remain safe for automated tests.
