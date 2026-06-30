# PayArc Merchant Validation Guide

Status: `MERCHANT_TEST_READY`

This plugin now contains the live-docs-backed PayArc Connect setup flow. A merchant with a PayArc account can install the release, press **Connect PayArc**, fetch a Connect AccessToken through PayArc Login, discover terminals, and run a low-value terminal payment.

## Official PayArc docs used

- PayArc Connect getting started: https://docs.payarc.net/reference/getting-started-1
- PayArc Login: https://docs.payarc.net/reference/login
- Terminal Registry: https://docs.payarc.net/reference/get-terminal-registry
- Sale transaction: https://docs.payarc.net/reference/createsaletransaction
- Transaction callback: https://docs.payarc.net/reference/transaction-callback-v3
- Get transaction: https://docs.payarc.net/reference/get_v3-transactions-traceid
- Cancel transaction: https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel

## Secure setup requirements

Use real PayArc values only through WooCommerce admin or another approved secure channel. Never commit PayArc tokens, callback secrets, real authorization headers, full MIDs, or full terminal ids.

Required settings:

1. PayArc login email.
2. PayArc MID.
3. PayArc ClientSecret.
4. PayArc SecretKey / Merchant API bearer token.
5. PayArc-provided callback bearer token.
6. Public HTTPS callback URL shown by the plugin.

## Merchant validation sequence

1. Activate **PayArc Terminal for WooCommerce**.
2. Open the PayArc Terminal gateway settings.
3. Enter the PayArc login email, MID, ClientSecret, SecretKey/API bearer token, and callback bearer token.
4. Press **Connect PayArc**.
5. Confirm the response reports connected status and one or more discovered terminals.
6. Select the intended PAX terminal from **Default terminal**.
7. Save settings and enable the gateway.
8. Confirm PayArc has the plugin callback URL configured if merchant-specific callback registration is required.
9. Start a low-value test order payment from WooCommerce POS/order-pay.
10. Complete the terminal interaction and confirm the order is paid only after PayArc callback/get-transaction confirmation.
11. Test decline, timeout/no-card, duplicate callback, and cancel-before-card-present when practical.

## Evidence to record after merchant validation

Record sanitized evidence only:

- PayArc Login HTTP status and whether `BearerTokenInfo.AccessToken` was returned.
- Terminal discovery count and whether terminal ids came from `pos_identifier`.
- Sale initiation HTTP status and whether a synchronous `traceId` was returned.
- Callback status values observed for approval/decline/timeout/cancel.
- Whether `GET /v3/transactions/{traceId}` returned the authoritative final payload.
- Whether amount values matched documented minor units/cents.
- Confirmation that logs, diagnostics, admin HTML, and AJAX responses did not expose secrets.

Do not paste real tokens, full MIDs, full terminal ids, full authorization headers, or cardholder data into docs or issues.
