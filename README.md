# PayArc Terminal for WooCommerce

PayArc Terminal for WooCommerce adds an in-person PayArc PAX terminal payment method to WooCommerce. It is designed for merchants who take payments at a physical terminal and want the WooCommerce order to update after PayArc confirms the terminal result.

The plugin connects your WooCommerce site to PayArc Connect V3, sends sale requests to a PayArc-confirmed terminal serial number, and reconciles the order from PayArc callbacks and transaction lookups.

## What the plugin does

- Adds a **PayArc Terminal** payment gateway for staff on WooCommerce POS. It is never offered on the shop's checkout.
- Supports **Live** and **Test** PayArc environments.
- Uses **Connect PayArc** in the gateway settings to sign in to PayArc and store a server-side Connect access token.
- Lets you enter the PAX terminal serial number (found on the back of the device, labeled S/N) that PayArc has confirmed for the merchant.
- Starts in-person terminal payments from the POS, and from the WooCommerce order payment page opened by a user who may run the POS.
- Polls PayArc and accepts PayArc callbacks until the terminal transaction reaches a final status.
- Marks the WooCommerce order paid only after PayArc returns a successful transaction result.
- Stores sensitive PayArc credentials server-side and does not print them back into admin pages, AJAX responses, or terminal transaction payloads.

## Requirements

- WordPress with WooCommerce installed and active.
- WooCommerce POS Pro 2.0.0 or newer. Without it the plugin shows an admin notice and registers no gateway, AJAX actions or callback route.
- PHP 7.4 or newer.
- A PayArc merchant account with PayArc Connect access.
- A PayArc-supported PAX terminal assigned to the merchant account.
- PayArc credentials for the environment you want to use: **Live** credentials for live terminals, or **Test** credentials for PayArc's test environment.
- A public HTTPS site URL before enabling Live mode, so PayArc can reach the callback URL.

## Installation

1. Download the plugin release ZIP.
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate **PayArc Terminal for WooCommerce**.
4. Go to **WooCommerce → Settings → Payments → PayArc Terminal**.

## PayArc settings you need

Ask PayArc or check the matching PayArc dashboard/API section for these values:

- PayArc login email.
- PayArc MID.
- PayArc `ClientSecret`.
- PayArc `SecretKey` / Merchant API bearer token.
- PayArc-provided callback bearer token.
- PayArc-confirmed terminal serial number for the PAX terminal (found on the back of the device, labeled S/N).

Use values from one PayArc environment at a time. If the gateway is set to **Live**, enter Live credentials and use a live terminal. If the gateway is set to **Test**, enter Test credentials and use PayArc's test terminal environment.

## Configure the gateway

1. Open **WooCommerce → Settings → Payments → PayArc Terminal**.
2. Choose the gateway **Mode**:
   - **Live** is the default and can process real payments.
   - **Test** is only for PayArc test credentials and PayArc's test terminal environment.
3. Enter the PayArc login email, MID, `ClientSecret`, `SecretKey` / API bearer token, and callback bearer token.
4. Press Connect PayArc.
   - The plugin calls PayArc Login for the selected mode.
   - It stores the returned Connect access token on your WordPress server.
   - It may read Terminal Registry records for display/reporting metadata, but registry records do not connect or activate a terminal.
5. If the plugin warns that the credentials look like the opposite environment, switch the mode or replace the credentials, then click **Connect PayArc** again.
6. Enter the terminal serial number (found on the back of the device, labeled S/N) that PayArc has confirmed for this merchant.
7. Confirm the displayed **Webhook URL** uses public HTTPS. Give this URL to PayArc if PayArc needs to configure callbacks for your merchant account.
8. Click **Save changes**.
9. Switch the gateway on under **POS → Settings → Checkout** when you are ready to accept terminal payments. That is the only switch: the Enabled toggle on WooCommerce's Payments list has no effect, and the gateway never appears on the shop's checkout.

After changing the mode or any PayArc credential, click **Connect PayArc** again and save the gateway settings. The plugin blocks terminal payments when the saved mode or credentials do not match the stored PayArc connection.

## Taking a payment

1. Create or open a WooCommerce order that should be paid in person.
2. Choose **PayArc Terminal** as the payment method.
3. The order payment page is WooCommerce POS Pro's payment panel: pick the terminal and start the payment there. A payment started on this plugin's own panel before the upgrade is carried into POS first (see below), so one order never has two sales on a terminal.
4. Complete the payment on the selected PAX terminal.
5. Keep the payment page open while the plugin waits for PayArc.
6. When PayArc confirms approval, the plugin submits the order payment form and WooCommerce marks the order paid.

If the terminal returns a decline, failure, cancellation, or timeout, the payment page lets the cashier try again after checking the terminal.

## Callbacks and reconciliation

PayArc terminal payments are asynchronous: a sale request can be accepted before the shopper finishes on the terminal. To keep WooCommerce in sync, the plugin uses both:

- **PayArc callback/webhook events** sent to the Webhook URL shown in the gateway settings.
- **Transaction polling** with PayArc's `GET /v3/transactions/{traceId}` endpoint.

The plugin verifies that the returned PayArc transaction belongs to the WooCommerce order before completing payment. It records PayArc transaction details such as trace ID, transaction ID, charge ID, card brand, entry mode, last four digits, and processor response details when PayArc provides them.

## WCPOS Pro 2.0 payments base

The plugin is Pro-only: everything it registers sits behind `wcpos_pro_requires('2.0.0')` from `plugins_loaded` at priority 30 (Pro defines its helpers at 20), and without a compatible Pro only an admin notice remains (plus the HPOS compatibility declaration and the admin script). With Pro 2.0 active, it registers a server adapter (`includes/Server/`) with Pro's shared payments base, so the POS app can drive a PayArc terminal through Pro's ledger: one sale per ledger row (the row id is the sale's `X-Idempotency-Key`, and its 16-character `transactionId` is derived from the order and the row), polling by PayArc's `traceId`, cancellation, refunds as terminal commands through `POST /v3/transactions/refund`, and callbacks delivered to Pro's route. The order-pay page is Pro's panel too: it renders through `wcpos_pro_order_pay_panel()`, submits through `wcpos_pro_order_pay_process()`, and a WooCommerce refund of a payment Pro's ledger holds goes through `wcpos_pro_order_pay_refund()` to the terminal refund command (a payment the plugin's own panel completed before the upgrade is refunded from the PayArc dashboard or the terminal, as before).

**Sales the old panel left mid-flight** are adopted into Pro's ledger (`includes/Legacy_Adoption.php`): once on upgrade, a page of orders per request, and again for one order whenever Pro's panel renders it. Under Free's per-order lock and the old panel's own locks, on a fresh read: a live attempt with a traceId that the in-flight guard still counts (under thirty minutes old) becomes a pending ledger row with the traceId as its action, and Pro reads the sale from PayArc from then on; an older one is read from PayArc once first (a final answer settles it through the old reconciler; a sale still on the terminal is adopted; a sale PayArc cannot see under the current credentials holds the panel back with a notice, because Pro could neither poll nor cancel it); a start PayArc never answered is waited out while the guard counts it and closed with a note after. While Pro owns a sale, the old callback route, poll and cancel answer "handled by WooCommerce POS"; when Pro captures it the old attempt is closed too. The old panel's start is refused outright under Pro's panel.

Facts the adapter rests on, and what they mean for the store:

- A sale is accepted at once and decided on the terminal. `APPROVED` is money; `DECLINE`, `DUP TRANSACTION` and a failure are failures with the processor's text; `TIMEOUT` (no card presented) ends the sale as expired; `ABORTED` follows a cancel. For a few seconds after a sale PayArc answers the read with `TRANSACTION_NOT_FOUND`, which the adapter treats as "not visible yet", never as gone.
- A lost answer to a sale is not a refusal: the row stays pending and the replay carries the same key, which PayArc answers with the same sale ("reuse the same key only when retrying the same payload"). A `409 TERMINAL_OFFLINE` sent nothing and is final.
- A cancel is confirmed by reading the sale back (`ABORTED`); one PayArc refuses because the card has begun processing leaves the next poll to decide.
- Refunds are terminal commands linked to the sale by its `transactionId`, answered with a `traceId` and decided on the terminal; the WooCommerce refund stays pending until the terminal's callback, which is noted on the order. A refund request PayArc did not answer keeps its record pending under a key saved before the request and is asked again every two minutes (up to five times) under that key, so no second refund command results from a lost answer. The order's transaction id for a Pro payment is PayArc's `traceId`; a refund of a sale the plugin's own panel completed finds that sale through the attempt kept on the order.
- The callback to Pro's route (`wcpos/v2/payments/webhook?provider=payarc`, with the plugin's own callback token in the URL) is authenticated by that token or by PayArc's callback bearer token, before anything is read; the authenticated read of the transaction, not the posted body, is the evidence. The URL token is minted when Connect PayArc runs; on a site that has not connected and has no bearer token, every callback is refused (401) and payments settle by polling alone.
- While a sale may be on a terminal, the gateway's mode, credentials and Connect state cannot be changed or disconnected, as for the plugin's own panel; each sale holds its own marker (with the command as sent, so a replay of a lost answer is byte-identical whatever the settings say now), released when that sale ends and stale after thirty minutes. A refund request the client refused to send (missing credentials, a Connect state that does not match the mode) is a plain error, never a pending record.

### Conformance suite

Pro's provider conformance suite (`tests/conformance/`) runs the real adapter over a scripted PayArc (behind `pre_http_request`) under wp-env with the sibling Pro checkout and compares the recorded transcripts in `tests/conformance/transcripts/`. It needs Docker and a sibling checkout at `../woocommerce-pos-pro` on `next` with its Composer dependencies installed:

```bash
composer install
npx wp-env start
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

A missing transcript fails. To record one, set the opt-in inside the PHPUnit process (wp-env forwards no host variables), review the JSON, rerun without it, then commit:

```bash
npx wp-env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli -- env WCPOS_RECORD_TRANSCRIPTS=1 vendor/bin/phpunit -c phpunit.conformance.xml.dist
```

## Live mode safety notes

Live mode can process real payments. Before using it with customers:

- Confirm the gateway is set to **Live** and connected with Live PayArc credentials.
- Confirm the terminal serial number is the intended live PAX terminal and PayArc support has validated it for the merchant.
- Confirm the Webhook URL is public HTTPS and reachable by PayArc.
- For Test mode, run a low-value test payment first. For Live mode, run a small live transaction first and confirm the order, PayArc dashboard, settlement, and receipts match expectations.
- Do not change PayArc mode, credentials, terminal selection, or connection state while a terminal payment is in progress. The plugin blocks these changes where possible to protect reconciliation.

## Refreshing or disconnecting PayArc

Use the connection controls in the gateway settings:

- **Refresh Terminals** fetches current Terminal Registry records from PayArc for display/reporting context. It does not activate a terminal.
- **Disconnect PayArc** clears the stored Connect access token and Terminal Registry metadata. Saved credentials and the entered terminal serial number are left in place so you can reconnect quickly.
- **Connect PayArc** reconnects with the currently entered credentials and selected mode.

## Troubleshooting

### Terminal Registry is empty

- Terminal Registry records are reporting metadata only and are not required to activate processing.
- Confirm the PayArc MID, login email, `ClientSecret`, and `SecretKey` are from the selected environment.
- Ask PayArc to validate the terminal serial number for the merchant account, then enter that serial number in gateway settings.
- Check **WooCommerce → Status → Logs** and choose the `payarc-terminal-for-woocommerce` log source.

### The plugin says the credentials look like the opposite environment

The gateway mode and credentials do not match. Switch **Mode** to the environment named in the warning, or replace the credentials with values from the selected environment, then click **Connect PayArc** again.

### Live mode cannot be enabled

Live mode requires a current Live PayArc connection, a PayArc-confirmed terminal serial number, and a public HTTPS callback URL. Reconnect PayArc in Live mode, enter the terminal serial number, and confirm your WordPress site URL is HTTPS.

### A payment is waiting too long

- Check the terminal screen first. The shopper may still need to approve, cancel, or remove the card.
- Keep the order payment page open so polling can continue.
- If the payment page times out, check the order notes and PayArc dashboard before trying again.
- Use WooCommerce logs for technical details. The plugin avoids logging secrets, but you should still avoid sharing full MIDs, terminal IDs, tokens, cardholder data, or live customer payment details in support requests.

## Security and data handling

- PayArc credentials and tokens are stored in WooCommerce gateway settings on the server.
- Secret fields are not rendered back into the admin form after saving.
- Terminal sale requests use the PayArc Connect access token returned by PayArc Login.
- The Merchant API bearer token is used for PayArc Login and optional Terminal Registry reporting metadata.
- Callback requests must include the configured callback bearer token.
- Logs and diagnostics are designed to mask identifiers and avoid exposing secrets.

## Development

This repository includes lightweight PHP linting and regression tests for the gateway logic.

```bash
composer run lint
composer run test
```

Merchant validation guidance is available in [`docs/payarc-sandbox-validation.md`](docs/payarc-sandbox-validation.md).
