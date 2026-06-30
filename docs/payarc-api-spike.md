# PayArc API Spike Notes

Date: 2026-06-29, updated 2026-06-30
Status: Superseded by merchant-test-ready implementation.

The original spike documented that the developer did not have a PayArc account. The implementation now follows the official PayArc docs so a merchant can test with their own credentials:

- `POST /Login` with PayArc dashboard email, MID, ClientSecret, and SecretKey/API bearer token.
- Store `BearerTokenInfo.AccessToken` as the Connect AccessToken for terminal transaction calls.
- Discover terminals from Login `Terminals` and Merchant API `GET /v1/terminalregistries`.
- Use terminal `pos_identifier` as the PayArc Connect V3 `terminalId`.
- Use `/v3/transactions/sale`, callback, get-transaction, and cancel endpoints for the payment lifecycle.

Current active validation guide: `docs/payarc-sandbox-validation.md`.
