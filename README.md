# PayArc Terminal for WooCommerce

PayArc Terminal for WooCommerce adds an in-person PayArc PAX terminal payment method to WooCommerce. It is designed for merchants who take payments at a physical terminal and want the WooCommerce order to update after PayArc confirms the terminal result.

The plugin connects your WooCommerce site to PayArc Connect V3, sends sale requests to a PayArc-confirmed terminal serial number, and reconciles the order from PayArc callbacks and transaction lookups.

## What the plugin does

- Adds a **PayArc Terminal** payment gateway in WooCommerce.
- Supports **Live** and **Test** PayArc environments.
- Uses **Connect PayArc** in the gateway settings to sign in to PayArc and store a server-side Connect access token.
- Lets you enter the 10-digit PAX terminal serial number PayArc has confirmed for the merchant.
- Starts in-person terminal payments from the WooCommerce order payment page.
- Polls PayArc and accepts PayArc callbacks until the terminal transaction reaches a final status.
- Marks the WooCommerce order paid only after PayArc returns a successful transaction result.
- Stores sensitive PayArc credentials server-side and does not print them back into admin pages, AJAX responses, or terminal transaction payloads.

## Requirements

- WordPress with WooCommerce installed and active.
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
- PayArc-confirmed 10-digit terminal serial number for the PAX terminal.

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
6. Enter the 10-digit terminal serial number PayArc has confirmed for this merchant.
7. Confirm the displayed **Webhook URL** uses public HTTPS. Give this URL to PayArc if PayArc needs to configure callbacks for your merchant account.
8. Click **Save changes**.
9. Enable the gateway when you are ready to accept terminal payments.

After changing the mode or any PayArc credential, click **Connect PayArc** again and save the gateway settings. The plugin blocks terminal payments when the saved mode or credentials do not match the stored PayArc connection.

## Taking a payment

1. Create or open a WooCommerce order that should be paid in person.
2. Choose **PayArc Terminal** as the payment method.
3. On the order payment page, click **Start Payment**.
4. Complete the payment on the selected PAX terminal.
5. Keep the payment page open while the plugin waits for PayArc.
6. When PayArc confirms approval, the plugin submits the order payment form and WooCommerce marks the order paid.

If the terminal returns a decline, failure, cancellation, or timeout, the payment page lets the cashier try again after checking the terminal.

## Callbacks and reconciliation

PayArc terminal payments are asynchronous: a sale request can be accepted before the shopper finishes on the terminal. To keep WooCommerce in sync, the plugin uses both:

- **PayArc callback/webhook events** sent to the Webhook URL shown in the gateway settings.
- **Transaction polling** with PayArc's `GET /v3/transactions/{traceId}` endpoint.

The plugin verifies that the returned PayArc transaction belongs to the WooCommerce order before completing payment. It records PayArc transaction details such as trace ID, transaction ID, charge ID, card brand, entry mode, last four digits, and processor response details when PayArc provides them.

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
- Ask PayArc to validate the terminal serial number for the merchant account, then enter that 10-digit serial number in gateway settings.
- Check **WooCommerce → Status → Logs** and choose the `payarc-terminal-for-woocommerce` log source.

### The plugin says the credentials look like the opposite environment

The gateway mode and credentials do not match. Switch **Mode** to the environment named in the warning, or replace the credentials with values from the selected environment, then click **Connect PayArc** again.

### Live mode cannot be enabled

Live mode requires a current Live PayArc connection, a 10-digit PayArc-confirmed terminal serial number, and a public HTTPS callback URL. Reconnect PayArc in Live mode, enter the terminal serial number, and confirm your WordPress site URL is HTTPS.

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
