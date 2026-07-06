# PayArc Terminal for WooCommerce

PayArc PAX Terminal integration for WooCommerce POS using PayArc Connect V3 server-driven terminal payments.

## Installation

1. Download the release ZIP and install it from **WordPress Admin → Plugins → Add New → Upload Plugin**.
2. Activate **PayArc Terminal for WooCommerce**.
3. Open **WooCommerce → Settings → Payments → PayArc Terminal**.

## Merchant setup

This build is ready for a PayArc merchant to connect with their own PayArc account and PAX terminal. The developer does not need a PayArc account because the plugin setup screen calls PayArc directly from the merchant's WordPress site.

**Live is the default gateway mode.** Switch to Test before pressing **Connect PayArc** only when validating with PayArc test credentials:

- **Test** uses PayArc test dashboard credentials, PayArc test endpoints, and the PayArc Connect Test app/terminal environment.
- **Live** uses PayArc live dashboard credentials, live endpoints, and live terminals. Live mode can process real payments.

Required PayArc values from the matching PayArc dashboard/API section:

- PayArc login email.
- PayArc MID.
- PayArc `ClientSecret`.
- PayArc `SecretKey` / Merchant API bearer token.
- PayArc-provided callback bearer token for validating terminal result callbacks.

Setup flow:

1. Leave **Live** selected for live credentials/terminals, or switch to **Test** for PayArc test credentials and the Connect Test app.
2. Enter the PayArc values for that same environment in the gateway settings.
3. Press Connect PayArc. The plugin performs PayArc Login against the selected environment, stores the returned Connect AccessToken server-side, and runs terminal discovery.
4. If the credentials authenticate against the opposite environment, the plugin warns you to switch modes instead of storing a mismatched connection.
5. Select the discovered PAX terminal from the Default terminal dropdown. The terminal id comes from PayArc `pos_identifier`; normal setup does not require manually typing a terminal id.
6. Confirm the Webhook URL is public HTTPS and give it to PayArc if callback configuration is required for the merchant account. HTTPS is required before enabling Live mode.
7. Save settings and enable the gateway.
8. For Test, run a low-value test payment. For Live, start with the smallest practical live transaction and confirm settlement/reporting expectations with PayArc.

## What is verified in this repository

- PayArc Connect V3 request/response shapes are implemented from current official PayArc docs.
- The setup flow calls real PayArc endpoints when the merchant presses Connect PayArc.
- Secrets are stored server-side and are not rendered back into admin HTML, diagnostics, AJAX responses, or terminal transaction payloads.
- Terminal transactions use the PayArc Login `AccessToken`; the Merchant API token is only used for Login/terminal discovery.
- Connected tokens and terminal lists are tied to the selected Test/Live mode and PayArc credentials. After changing modes or credentials, press **Connect PayArc** again and save settings; transaction requests are blocked if the saved mode/credentials and token context do not match.
- If live credentials are entered while Test is selected, or test credentials are entered while Live is selected, the plugin warns and does not store that mismatched selected-mode connection. Connection changes are blocked while a terminal payment is in progress.

A merchant with PayArc credentials still needs to perform terminal validation and report sanitized evidence. See `docs/payarc-sandbox-validation.md`.
