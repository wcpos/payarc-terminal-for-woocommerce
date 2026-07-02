# PayArc Live Mode Credential Warnings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable PayArc Live mode safely by using live endpoints, verifying credentials against the selected environment, warning when credentials appear to belong to the opposite environment, and preventing stale test/live or stale credential tokens from being reused after a mode/credential switch.

**Architecture:** `Settings` owns endpoint selection, mode/connection metadata, and non-secret connection fingerprinting. `PayArcConnectionService` performs selected-environment Login/registry calls, probes the opposite Login endpoint only after a selected-mode authentication failure, returns generic mismatch warnings without secrets, and stores `connected_mode` and `connected_fingerprint` with successful connection state, and blocks connection changes while a PayArc terminal payment is in-flight. `Gateway` exposes Live mode, validates mode-aware safety requirements, and the admin JavaScript surfaces server warnings; tests drive each behavior before implementation.

**Tech Stack:** PHP 7.4-compatible WordPress/WooCommerce gateway plugin, WordPress HTTP API, plain PHP regression tests through `composer run test`, syntax lint through `composer run lint`.

---

## File Structure

- Modify `includes/Settings.php`: add live/test endpoint constants, endpoint selection by `mode`, `connected_mode()`, `is_connected_for_current_mode()`, and diagnostics for connected mode.
- Modify `includes/Gateway.php`: expose Live mode in the select field, remove unconditional production rejection, add live safety validation, add connected-mode validation, and include mode mismatch text in local diagnostics JavaScript.
- Modify `includes/Services/PayArcConnectionService.php`: store `connected_mode`, clear stale token/terminal state on disconnect, add opposite-environment Login probe after auth failure, and return warning details without secrets. Update `PayArcClient` to block transaction requests when the saved token belongs to a different mode.
- Modify `assets/js/admin.js`: keep posting `mode`, display returned warnings/details safely, and rely on server mismatch responses.
- Modify tests:
  - `tests/regression/settings.php` for endpoint selection and connected-mode validation.
  - `tests/regression/aa-connection-service.php` for selected-mode live URLs, auth-only opposite-mode mismatch detection, and stored `connected_mode`; `tests/regression/client-sale-payload.php` for runtime blocking of stale mode tokens.
  - `tests/regression/gateway-ui.php` or `tests/regression/gateway-diagnostics.php` for Live option/admin copy.
  - `tests/regression/ajax-access.php` only if public AJAX response allowlisting needs a new warning/detail assertion.
- Modify docs `README.md` and `docs/payarc-sandbox-validation.md` to document Test vs Live setup and sanitized evidence.

---

### Task 1: Settings endpoint selection and connected-mode metadata

**Files:**
- Modify: `tests/regression/settings.php`
- Modify: `includes/Settings.php`

- [ ] **Step 1: Write failing tests**

Add assertions to `tests/regression/settings.php` that prove:

```php
$liveSettings = new Settings(array('mode' => 'production'));
patwc_assert_same('production', $liveSettings->mode(), 'Production mode getter mismatch.');
patwc_assert_same('https://payarcconnectapi.curvpos.com', $liveSettings->connect_login_base_url(), 'Production Login URL mismatch.');
patwc_assert_same('https://payarcconnectapi.payarc.net', $liveSettings->connect_base_url(), 'Production Connect V3 URL mismatch.');
patwc_assert_same('https://api.payarc.net', $liveSettings->merchant_api_base_url(), 'Production Merchant API URL mismatch.');

$testConnected = new Settings(array(
    'mode' => 'test',
    'connected_mode' => 'test',
    'connect_access_token' => 'token',
    'default_terminal_id' => '1234567890',
));
patwc_assert_same('test', $testConnected->connected_mode(), 'Connected mode getter should return test.');
patwc_assert_same(true, $testConnected->is_connected_for_current_mode(), 'Matching test connection should be active.');

$staleConnected = new Settings(array(
    'mode' => 'production',
    'connected_mode' => 'test',
    'connect_access_token' => 'token',
    'default_terminal_id' => '1234567890',
));
patwc_assert_same(false, $staleConnected->is_connected_for_current_mode(), 'Stale test connection must not be active in production mode.');
```

- [ ] **Step 2: Run red test**

Run: `php tests/regression/settings.php`
Expected: FAIL because production URLs still return test URLs and `connected_mode()` / `is_connected_for_current_mode()` do not exist.

- [ ] **Step 3: Implement Settings support**

Add production endpoint constants and make endpoint getters mode-aware unless explicit override is set. Add `connected_mode()` and `is_connected_for_current_mode()` methods. Keep `production` as the stored production mode string for compatibility with existing validation code.

- [ ] **Step 4: Run green test**

Run: `php tests/regression/settings.php`
Expected: PASS.

---

### Task 2: Gateway Live mode validation and admin UI warnings

**Files:**
- Modify: `tests/regression/settings.php`
- Modify: `tests/regression/gateway-ui.php`
- Modify: `includes/Gateway.php`

- [ ] **Step 1: Write failing tests**

In `tests/regression/settings.php`, replace the old production-rejection expectation with mode-aware validation:

```php
patwc_assert_same(array(
    'Press Connect PayArc after changing mode so the Connect AccessToken and terminal list match Live mode.',
    'Callback URL must be HTTPS before enabling Live mode.',
), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'mode' => 'production',
    'connected_mode' => 'test',
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
    'webhook_url' => 'http://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
)), 'Live mode should require a matching connection and HTTPS callback URL.');

patwc_assert_same(array(), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'mode' => 'production',
    'connected_mode' => 'production',
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
    'webhook_url' => 'https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
)), 'Live mode should be allowed after matching connection and HTTPS callback URL.');
```

In `tests/regression/gateway-ui.php`, assert the rendered settings include `Live` as an option and user-facing copy warns that Live can process real payments.

- [ ] **Step 2: Run red tests**

Run:

```bash
php tests/regression/settings.php
php tests/regression/gateway-ui.php
```

Expected: FAIL because Live option is absent and production mode is unconditionally rejected.

- [ ] **Step 3: Implement gateway changes**

Add `production => Live` to the mode select. Update descriptions to state Test uses PayArc test dashboard/test Connect app and Live can process real payments. Update validation to reject unknown modes, require reconnect after mode switches when enabled, and require HTTPS callback URL for Live. Preserve existing tenant, terminal, tender, and print receipt validation.

- [ ] **Step 4: Run green tests**

Run:

```bash
php tests/regression/settings.php
php tests/regression/gateway-ui.php
```

Expected: PASS.

---

### Task 3: Connection service live endpoints, mismatch probes, and stale-token clearing

**Files:**
- Modify: `tests/regression/aa-connection-service.php`
- Modify: `includes/Services/PayArcConnectionService.php`

- [ ] **Step 1: Write failing tests**

Add tests to `tests/regression/aa-connection-service.php` that prove a production connection uses:

```php
'https://payarcconnectapi.curvpos.com/Login'
'https://api.payarc.net/v1/terminalregistries'
```

and stores:

```php
$stored['connected_mode'] === 'production'
```

Add mismatch tests using queued responses:

1. Selected test Login returns HTTP 401.
2. Opposite production Login returns HTTP 200 with `ErrorCode` 0 and an `AccessToken`.
3. `connect()` throws a generic message containing `These look like Live PayArc credentials` and no submitted secret.

Repeat inverse case for selected production failing and test Login succeeding.

- [ ] **Step 2: Run red test**

Run: `php tests/regression/aa-connection-service.php`
Expected: FAIL because `connected_mode` is not stored and opposite-environment probes do not exist.

- [ ] **Step 3: Implement connection changes**

Make successful `connect()` persist `connected_mode` equal to selected `Settings::mode()`. Add a private selected-mode Login wrapper that catches selected-mode auth failures, probes the opposite mode by constructing a `Settings` clone with `mode` flipped and no endpoint overrides, and throws a generic mismatch exception when opposite Login succeeds. Preserve transport/server/JSON failures without probing the opposite endpoint. Never call terminal registry in the opposite-mode probe. Ensure `disconnect()` clears `connected_mode`, `connect_access_token`, token expiry, terminal registry, and default terminal.

- [ ] **Step 4: Run green test**

Run: `php tests/regression/aa-connection-service.php`
Expected: PASS.

---

### Task 4: Admin AJAX/JavaScript response safety

**Files:**
- Modify: `tests/regression/ajax-access.php` if needed
- Modify: `includes/AjaxHandler.php` if needed
- Modify: `assets/js/admin.js`

- [ ] **Step 1: Write failing test only if response allowlist blocks warnings/details**

If `AjaxHandler::public_connection_body()` already passes safe `warning`/`details` fields, skip production code changes. If not, add a regression assertion that an injected connection-service body containing generic `warning`/`details` strings is returned while raw secrets are not.

- [ ] **Step 2: Run red test if written**

Run: `php tests/regression/ajax-access.php`
Expected: FAIL only if the allowlist lacks required safe fields.

- [ ] **Step 3: Implement minimal response/UI change**

Allow only generic non-secret `warning`/`details` fields if absent. Update `assets/js/admin.js` to append warning/details text already returned by the server, without rendering HTML.

- [ ] **Step 4: Run green test**

Run: `php tests/regression/ajax-access.php`
Expected: PASS.

---

### Task 5: Documentation and full verification

**Files:**
- Modify: `README.md`
- Modify: `docs/payarc-sandbox-validation.md`

- [ ] **Step 1: Update docs**

Document:

- Test mode uses test dashboard/test Connect app/test endpoints and test credentials.
- Live mode uses live dashboard/live terminal/live endpoints and can process real payments.
- Press **Connect PayArc** after changing modes.
- If credentials look like the opposite environment, the plugin warns and does not store the selected-mode connection.
- Never paste real credentials or full IDs into issues/docs/logs.

- [ ] **Step 2: Run targeted tests**

Run:

```bash
php tests/regression/settings.php
php tests/regression/aa-connection-service.php
php tests/regression/gateway-ui.php
php tests/regression/ajax-access.php
```

Expected: PASS.

- [ ] **Step 3: Run full test and lint**

Run:

```bash
composer run test
composer run lint
```

Expected: all regression tests pass and PHP lint reports no syntax errors.

- [ ] **Step 4: Commit**

Run:

```bash
git status --short
git add includes/Settings.php includes/Gateway.php includes/Services/PayArcConnectionService.php assets/js/admin.js tests/regression/settings.php tests/regression/aa-connection-service.php tests/regression/gateway-ui.php tests/regression/ajax-access.php README.md docs/payarc-sandbox-validation.md docs/superpowers/plans/2026-07-03-payarc-live-mode-credential-warnings.md
git commit -m "feat: add PayArc live mode safeguards"
```

Expected: commit succeeds after tests/lint pass.

---

## Self-Review

- Spec coverage: endpoint selection, Live UI, selected-mode verification, opposite-mode warning, stale mode/credential token prevention and in-flight payment connection-change blocking, HTTPS Live callback validation, secret redaction, and docs are covered by Tasks 1-5.
- Placeholder scan: no TBD/TODO placeholders remain; Task 4 is explicitly conditional because code inspection may prove existing allowlisting is sufficient before writing production code.
- Type consistency: mode values remain `test` and `production`; user-facing label is `Live`; new setting key is `connected_mode`.
