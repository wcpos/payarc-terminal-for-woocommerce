# PayArc Merchant Validation Guide

Status: `MERCHANT_TEST_AND_LIVE_READY_WITH_ENVIRONMENT_WARNINGS`

This plugin now contains the live-docs-backed PayArc Connect setup flow. A merchant with a PayArc account can install the release, use the default **Live** mode or switch to **Test**, press **Connect PayArc**, fetch a Connect AccessToken through PayArc Login, enter a PayArc-confirmed terminal serial number, and run a terminal payment. Live mode can process real payments.

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

1. Gateway mode: **Live** is the default for live dashboard/live terminal credentials; switch to **Test** for test dashboard/test Connect app credentials.
2. PayArc login email for the selected environment.
3. PayArc MID for the selected environment.
4. PayArc ClientSecret for the selected environment.
5. PayArc SecretKey / Merchant API bearer token for the selected environment.
6. PayArc-provided callback bearer token.
7. PayArc-confirmed terminal serial number for the PAX terminal (found on the back of the device, labeled S/N).
8. Public HTTPS callback URL shown by the plugin. HTTPS is required before enabling Live mode.

## Merchant validation sequence

1. Activate **PayArc Terminal for WooCommerce**.
2. Open the PayArc Terminal gateway settings.
3. Leave **Live** selected when the merchant intends to connect live credentials and a live terminal, or switch to **Test** for test credentials/test terminal validation.
4. Enter the PayArc login email, MID, ClientSecret, SecretKey/API bearer token, and callback bearer token for that same environment.
5. Press **Connect PayArc**.
6. If the plugin warns that the credentials look like the opposite environment, switch the mode or replace the credentials before continuing.
7. Confirm the response reports connected status. Terminal Registry records may be shown, but they are reporting metadata only and do not activate terminal processing.
8. Enter the intended PAX terminal's serial number (found on the back of the device, labeled S/N) after PayArc validates it for the merchant.
9. Save settings and enable the gateway. Transaction requests are blocked if the saved Test/Live mode or PayArc credentials do not match the context used by the stored Connect AccessToken.
10. Confirm PayArc has the plugin callback URL configured if merchant-specific callback registration is required.
11. For Test, start a low-value test order payment from WooCommerce POS/order-pay. For Live, start with the smallest practical live transaction and confirm with PayArc/support before broader rollout.
12. Complete the terminal interaction and confirm the order is paid only after PayArc callback/get-transaction confirmation.
13. Test decline, timeout/no-card, duplicate callback, and cancel-before-card-present when practical. Do not change PayArc mode, credentials, Connect state, or disconnect while a terminal payment is still in progress; the plugin blocks those connection changes to protect reconciliation.

## Evidence to record after merchant validation

Record sanitized evidence only:

- Selected mode (`Test` or `Live`) and confirmation that credentials came from the matching PayArc environment.
- PayArc Login HTTP status and whether `BearerTokenInfo.AccessToken` was returned.
- Terminal Registry count if PayArc returned registry metadata.
- PayArc-confirmed terminal serial number was entered and used as the V3 `terminalId`.
- Sale initiation HTTP status and whether a synchronous `traceId` was returned.
- Callback status values observed for approval/decline/timeout/cancel.
- Whether `GET /v3/transactions/{traceId}` returned the authoritative final payload.
- Whether amount values matched documented minor units/cents.
- Confirmation that logs, diagnostics, admin HTML, and AJAX responses did not expose secrets.

Do not paste real tokens, full MIDs, full terminal ids, full authorization headers, cardholder data, or live customer payment details into docs or issues.
