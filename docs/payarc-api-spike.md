# PayArc API Spike Notes

Date: 2026-06-29, updated 2026-06-30
Status: Superseded by merchant-test-ready implementation.

The original spike documented that the developer did not have a PayArc account. The implementation now follows the official PayArc docs so a merchant can test with their own credentials:

- `POST /Login` with PayArc dashboard email, MID, ClientSecret, and SecretKey/API bearer token.
- Store `BearerTokenInfo.AccessToken` as the Connect AccessToken for terminal transaction calls.
- Discover terminals from Login `Terminals` and Merchant API `GET /v1/terminalregistries`.
- Use the terminal's 10-digit serial number as the PayArc Connect V3 `terminalId`. The Sale schema documents `terminalId` as a "10-digit terminal serial number" (`^[0-9]{10}$`), and `tenantId` as the last 12 digits of the MID (`^[0-9]{12}$`).
- Terminal Registry `pos_identifier` is nullable and is NOT the sale `terminalId`. A registry record without one is normal metadata, not a provisioning fault.
- Use `/v3/transactions/sale`, callback, get-transaction, and cancel endpoints for the payment lifecycle.

Current active validation guide: `docs/payarc-sandbox-validation.md`.
