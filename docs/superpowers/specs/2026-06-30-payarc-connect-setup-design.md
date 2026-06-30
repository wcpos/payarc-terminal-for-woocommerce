# PayArc Connect Setup UX Design

Date: 2026-06-30
Status: Approved design; implementation pending

This spec supersedes the manual-setup assumptions in the earlier implementation plan, specifically Task 2 settings/gateway fields, Task 8 admin/AJAX setup actions, and Task 10 diagnostics/setup documentation in `docs/superpowers/plans/2026-06-29-payarc-pax-terminal.md`.

## Problem

The current mock-only PayArc Terminal plugin asks the merchant to manually enter several low-level values:

- API bearer/access token
- callback bearer token
- tenant ID
- default PAX terminal ID

That is not the right normal setup experience. A merchant should not need to know or type a terminal ID if PayArc exposes registered terminals through its API. The settings page should guide the user through connecting PayArc, discovering merchant/tenant/terminal data, and choosing the default terminal.

Live PayArc behavior is still unverified in this repository. This design therefore separates the UX and local architecture from the exact live PayArc auth details. The implementation must keep production disabled until the live spike confirms the required token exchange, terminal registry payloads, and callback registration capabilities.

## Goals

1. Replace normal manual setup with a single **Connect PayArc** flow in WooCommerce payment settings.
2. Discover merchant/tenant identifiers from PayArc rather than asking users to type them when an API path exists.
3. Fetch the PayArc Terminal Registry and populate a terminal selector.
4. Store secrets server-side only and never render saved secret values into HTML, diagnostics, JavaScript, logs, docs, or AJAX responses.
5. Keep manual setup available only as an advanced troubleshooting fallback while integration details remain mock-only.
6. Preserve the existing payment architecture: browser/POS talks only to WordPress; WordPress talks to PayArc; PayArc talks to PAX.

## Non-goals

- Do not claim live PayArc OAuth, login, terminal registry, or callback-registration behavior until Task 0 is rerun with real PayArc credentials.
- Do not enable production mode in this pass.
- Do not add refunds/voids.
- Do not make the browser call PayArc or a terminal directly.

## Recommended User Flow

### Initial disconnected state

The WooCommerce gateway settings page shows a connection card:

- Status: **Not connected**
- Primary action: **Connect PayArc**
- Secondary action: **Manual / troubleshooting setup** collapsed by default
- Explanation: “Connect PayArc to discover your merchant account and terminals. No terminal ID needs to be typed manually.”

### Connect PayArc flow

1. Admin clicks **Connect PayArc**.
2. Plugin shows the minimum credential form required for the currently verified integration mode:
   - Preferred future mode: PayArc partner/OAuth authorization URL, if available.
   - Fallback mode: one-time PayArc API login/credential fields, submitted server-side only.
3. WordPress exchanges that credential server-side for the required PayArc API token(s).
4. WordPress fetches merchant/tenant information.
5. WordPress fetches the Terminal Registry.
6. Admin selects a default terminal from a dropdown populated by the registry response.
7. WordPress stores the selected terminal and discovered tenant/merchant identifiers.
8. Settings show **Connected** with masked IDs and a **Refresh terminals** button.

### Connected state

The settings page shows:

- Status: **Connected**
- Merchant/tenant: masked except last 4
- Default terminal: dropdown of fetched terminals, with enough friendly metadata to identify the PAX device
- Webhook URL: read-only public callback URL
- Last terminal refresh timestamp
- Last connection/PayArc error, redacted
- Actions:
  - **Refresh terminals**
  - **Reconnect PayArc**
  - **Disconnect**
  - **Validate Settings** local-only diagnostics

### Advanced fallback

Manual fields stay behind an “Advanced / troubleshooting” disclosure while the integration is mock-only:

- API/access token
- callback bearer secret
- tenant ID
- terminal ID

Fallback fields must be clearly labelled as temporary and not the recommended merchant path. They remain useful if PayArc sandbox access cannot expose a partner/OAuth flow yet.

## Components

### `Settings`

Add connection-oriented getters while preserving existing payment-service callers:

- `connection_status()` → computed on read as `not_connected`, `connected`, `error`, or `manual`
- `api_access_token()` / existing `api_bearer_token()` compatibility
- `api_refresh_token()` if PayArc supports refresh tokens
- `tenant_id()` sourced according to `connection_mode`
- `default_terminal_id()` sourced according to `connection_mode`
- `terminal_registry()` returning sanitized cached terminal summaries
- `callback_bearer_token()` sourced from the PayArc-provided/setup token by default, with generated-token support only if the live spike proves PayArc accepts merchant-generated callback secrets

Getter precedence is explicit:

- `connection_mode=auto`: transaction getters read only discovered/connected values. Manual fallback fields are ignored, even if populated.
- `connection_mode=manual`: transaction getters read only manual fallback fields. Discovered registry values are ignored for payment commands.
- No getter silently merges auto and manual sources. This prevents unclear “which tenant/terminal did the sale use?” behavior.
- Switching modes does not delete the other mode's stored values, but it changes which source is active.
- `is_available()` must gate checkout/POS availability on the active mode being valid, not merely on the WooCommerce `enabled` checkbox.

### `PayArcConnectionService`

New service responsible for setup/discovery only:

- Exchange credentials for token(s), once live auth details are verified.
- Fetch merchant/tenant identity.
- Fetch terminal registry.
- Refresh terminal registry.
- Disconnect and delete stored tokens/registry cache.
- Normalize PayArc errors into safe admin messages.

This service must not be used by browser JavaScript directly. Admin AJAX calls invoke it server-side.

### `PayArcClient`

Keep transaction APIs separate from setup APIs:

- Existing Connect V3 sale/get/cancel methods remain focused on payments.
- Add setup/client methods only if PayArc uses the same base URL/token family.
- If Terminal Registry uses a different Merchant API base/token, create a separate low-level client class instead of mixing auth domains.

### `Gateway`

Admin settings should become connection-first:

- Replace visible manual token/tenant/terminal fields with a connection card and terminal selector.
- Keep advanced manual fields collapsed.
- Render terminal dropdown from cached registry data.
- Disable gateway enablement until the plugin is connected or valid manual fallback is explicitly selected.
- Keep diagnostics non-secret and local-first.

### Admin AJAX

Add privileged, nonce-protected admin actions:

- `patwc_connect_payarc`
- `patwc_refresh_payarc_terminals`
- `patwc_disconnect_payarc`
- Existing `patwc_validate_settings`

All actions require `current_user_can('manage_woocommerce')` and a purpose-specific nonce. Responses must be allowlisted and must not include raw tokens, full authorization headers, or raw PayArc payloads.

### `assets/js/admin.js`

Admin JavaScript should:

- Handle Connect / Refresh terminals / Disconnect clicks.
- Show progress states and safe error messages.
- Update terminal dropdown after a successful refresh.
- Never store or log secrets client-side.
- Use only localized WordPress AJAX URLs/nonces.

## Data Storage

Use WooCommerce gateway settings for stable configuration and separate options/transients for volatile discovery state when useful.

Recommended fields:

- `connection_mode`: `auto`, `manual`
- `api_access_token`: secret, never rendered
- `api_refresh_token`: secret, if applicable
- `api_token_expires_at`: timestamp, if applicable
- `tenant_id`: discovered, masked in UI
- `merchant_id`: discovered, masked in UI if PayArc provides it
- `terminal_registry`: array of sanitized terminal summaries
- `default_terminal_id`: selected from registry
- `terminal_registry_refreshed_at`: timestamp
- `callback_bearer_token`: PayArc-provided/setup secret by default, never rendered; generated only if live verification proves PayArc accepts merchant-generated callback secrets
- `last_connection_error`: redacted code/message

Do not persist `connection_status` as a separate source of truth. Compute it from `connection_mode`, configured token presence, token expiry if known, selected/default terminal state, and `last_connection_error`. Persisting only the inputs avoids drift when tokens expire, credentials are removed, or the admin switches modes.

Tokens at rest are stored in WooCommerce gateway settings/options like sibling payment plugins store API keys. This is acceptable for the plugin architecture, but the UI/docs must acknowledge that refresh tokens are long-lived secrets and should be protected by normal WordPress admin/database access controls.

Terminal summaries should include only non-secret display fields PayArc returns, for example:

- terminal ID / serial
- label/name if available
- status if available
- model/type if available
- location/store if available

Do not store full raw terminal registry payloads unless they are scrubbed first.

## Callback Secret Strategy

Current best-evidenced path: PayArc provides or provisions the callback bearer token during account/callback setup, and the merchant/admin enters it during setup. The plugin stores it server-side and displays only whether it is configured.

If PayArc exposes callback registration through API, the Connect PayArc flow can register/update the callback URL automatically. If that API also accepts a merchant-provided secret, the plugin may generate a high-entropy callback bearer token and push it to PayArc. Both capabilities must be live-verified before implementation claims they work.

If PayArc requires the merchant to copy a callback token from PayArc, the connection setup should include a secret input for that PayArc-provided token. Keep any plugin-generated callback-secret path behind the live-spike result, not as the default implementation path.

## Validation and Error Handling

### Local validation

`Validate Settings` remains local-only and checks:

- connected/manual status
- access token configured
- callback bearer token configured from PayArc/setup, or generated only when live verification proves that path is supported
- tenant ID present and locally valid if known format is verified
- selected terminal exists in cached registry, or manual fallback terminal is valid
- callback URL is HTTPS
- tender/receipt enums are valid

### Remote validation

Remote checks happen only through explicit actions:

- **Connect PayArc**
- **Refresh terminals**

Do not call PayArc during ordinary settings render or ordinary local validation.

### Failure modes

- Auth/token exchange fails: show “Unable to connect PayArc. Check credentials and try again.” Store redacted error code/message only.
- Terminal registry empty: connected state can exist, but gateway cannot be enabled until a terminal is selected or manual fallback is valid.
- Token expired: payment calls should fail safely and admin should show “Reconnect PayArc” until refresh behavior is verified.
- Terminal selected then disappears: payment start should fail locally with a clear admin/operator message, not fall back to an arbitrary terminal.

## Testing Plan

Add regression tests before implementation for:

1. Settings default disconnected state.
2. Gateway admin renders Connect PayArc card and hides manual fields behind advanced fallback.
3. Successful mocked connection stores tokens server-side and returns no secret values in AJAX.
4. Refresh terminals populates sanitized terminal selector data.
5. Default terminal must be selected from fetched registry before gateway can be enabled in auto mode.
6. Manual fallback can still validate when explicitly enabled, but diagnostics mark it as manual/troubleshooting.
7. Admin AJAX actions require `manage_woocommerce` and valid nonces.
8. Disconnect clears stored tokens and terminal cache.
9. Logs/diagnostics/admin HTML do not expose raw tokens or raw terminal registry payloads.

## Security Review Points

- No browser-to-PayArc or browser-to-PAX requests.
- All setup actions privileged and nonce-protected.
- Raw credentials accepted only over admin POST/AJAX and never echoed back.
- Stored tokens never rendered in inputs or localized JavaScript.
- Terminal registry data sanitized before storage/display.
- Gateway enablement cannot silently use a stale/manual arbitrary terminal unless the admin intentionally selects manual fallback.
- Production mode remains disabled until live PayArc auth/registry/callback behavior is documented.

## Documentation Updates Required

Update these documents during implementation:

- `README.md`: explain Connect PayArc setup as the normal path.
- `docs/payarc-sandbox-validation.md`: change required setup from manual terminal ID entry to connect/refresh terminal registry flow.
- `docs/payarc-api-spike.md`: add a specific live spike section for auth exchange, tenant discovery, terminal registry, and callback registration/secret handling.
- `docs/payarc-mock-contract.md`: add mock fixtures for connection/token/terminal registry responses.

## Open Live-Spike Questions

These must be answered with real PayArc sandbox/partner credentials before claiming live setup works:

1. Does PayArc support OAuth/partner authorization for this use case, or only credential/token exchange?
2. Which endpoint issues Connect transaction tokens, and what are the expiry/refresh semantics?
3. Which endpoint returns the merchant tenant ID used by Connect V3 sale requests?
4. Which endpoint returns Terminal Registry data, and what identifier must be passed as `terminalId` to V3 sale/cancel?
5. Are Terminal Registry and Connect V3 transaction APIs authenticated with the same token family?
6. Can callback URL and callback bearer secret be registered/updated via API, or must the merchant configure them manually in PayArc?
7. What fields in terminal registry are safe and useful for merchants to identify each PAX device?
8. Does `POST /v3/transactions/sale` return `traceId` synchronously, or only later via callback?
9. Is the sale/get/callback `amount` shape the object `{total, subtotal, currency, tip, tax}`, and are numeric amounts minor units such as cents?

## Implementation Recommendation

Implement the connection-driven UI in two phases:

1. **Mock-backed setup architecture:** add connection service, admin AJAX, terminal registry cache, dropdown, tests, and docs using mock responses. Manual fallback remains available. No live claims.
2. **Live PayArc spike integration:** once credentials are available, wire the verified auth/token/terminal-registry endpoints into the service and update docs with sanitized live evidence.

This keeps the merchant-facing UX correct now while avoiding hard-coding unverified PayArc auth assumptions.
