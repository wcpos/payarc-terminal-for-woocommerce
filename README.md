# PayArc Terminal for WooCommerce

PayArc PAX Terminal integration for WooCommerce POS using PayArc Connect V3 server-driven terminal payments.

## Installation

1. Download the release ZIP and install it from **WordPress Admin → Plugins → Add New → Upload Plugin**.
2. Activate **PayArc Terminal for WooCommerce**.
3. Open **WooCommerce → Settings → Payments → PayArc Terminal**.

## Merchant test setup

This build is ready for a PayArc merchant to test with their own PayArc account and PAX terminal. The developer does not need a PayArc account because the plugin setup screen calls PayArc directly from the merchant's WordPress site.

Required PayArc values from the merchant dashboard/API section:

- PayArc login email.
- PayArc MID.
- PayArc `ClientSecret`.
- PayArc `SecretKey` / Merchant API bearer token.
- PayArc-provided callback bearer token for validating terminal result callbacks.

Setup flow:

1. Enter the PayArc values in the gateway settings.
2. Press Connect PayArc. The plugin performs PayArc Login, stores the returned Connect AccessToken server-side, and runs terminal discovery.
3. Select the discovered PAX terminal from the Default terminal dropdown. The terminal id comes from PayArc `pos_identifier`; normal setup does not require manually typing a terminal id.
4. Confirm the Webhook URL is public HTTPS and give it to PayArc if callback configuration is required for the merchant account.
5. Save settings and enable the gateway.
6. Run a low-value test payment from WooCommerce POS/order-pay and complete the payment on the selected PAX terminal.

## What is verified in this repository

- PayArc Connect V3 request/response shapes are implemented from current official PayArc docs.
- The setup flow calls real PayArc endpoints when the merchant presses Connect PayArc.
- Secrets are stored server-side and are not rendered back into admin HTML, diagnostics, AJAX responses, or terminal transaction payloads.
- Terminal transactions use the PayArc Login `AccessToken`; the Merchant API token is only used for Login/terminal discovery.

A merchant with PayArc credentials still needs to perform live terminal validation and report sanitized evidence. See `docs/payarc-sandbox-validation.md`.
