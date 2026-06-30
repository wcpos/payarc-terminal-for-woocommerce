# PayArc Merchant Test Ready Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the first PayArc plugin from a docs-derived/mock-contract release into a merchant-testable build that a real PayArc merchant can install, connect, discover terminals, and run against PayArc Connect V3.

**Architecture:** Use PayArc's documented two-step flow: merchant dashboard API/Bearer token calls `POST /Login`, then the returned Connect `AccessToken` authenticates terminal transaction endpoints. Terminal ids are discovered from Login `Terminals` and/or Merchant API `GET /v1/terminalregistries`, with `pos_identifier`/`Pos_identifier` used as the 10-digit `terminalId` in `/v3/transactions/sale`. The callback bearer token remains merchant/PayArc-provided and is validated server-side without ever being sent in transaction requests.

**Tech Stack:** WordPress/WooCommerce payment gateway PHP 7.4, WordPress HTTP API (`wp_remote_request`), WooCommerce admin settings, vanilla admin JavaScript, existing PHP regression runner.

---

## Source of Truth Checked

Official PayArc docs pulled on 2026-06-30:

- `https://docs.payarc.net/reference/getting-started-1.md` — PayArc Connect uses Login with Merchant Dashboard API/Bearer token, then AccessToken for Connect calls.
- `https://docs.payarc.net/reference/login.md` — `POST https://testpayarcconnectapi.curvpos.com/Login` body: `Email`, `MID`, `ClientSecret`, `SecretKey`; response includes `Terminals[]` and `BearerTokenInfo.AccessToken`.
- `https://docs.payarc.net/reference/createsaletransaction.md` — `POST https://testpayarcconnectapi.payarc.net/v3/transactions/sale`; amount is in cents; required `tenantId`, `terminalId`, `transactionId`, `tenderType`, `amount`, `printReceipt`, `callbackURL`; send `X-Idempotency-Key`.
- `https://docs.payarc.net/reference/get-terminal-registry.md` — `GET https://testapi.payarc.net/v1/terminalregistries`; response `data[]` includes `terminal`, `type`, `device_id`, and `pos_identifier`.
- `https://docs.payarc.net/reference/transaction-callback-v3.md` — callback includes `Authorization: Bearer <merchant configured callback token>` and returns terminal result payload.

## File Structure

- Modify `includes/Settings.php`: environment URLs, login credentials, stored Connect access token, token expiry, terminal registry, selected terminal, diagnostics.
- Create `includes/Services/PayArcConnectionService.php`: Login, terminal registry fetch, terminal normalization, option persistence, redacted result payloads.
- Modify `includes/Services/PayArcClient.php`: use Connect access token for V3 transactions; add refresh-before-request via connection service; keep Merchant API token only for Login/registry.
- Modify `includes/Services/TerminalService.php`: validate terminal from normalized registry/default terminal; derive tenant id from MID where possible.
- Modify `includes/AjaxHandler.php`: add manager-only `patwc_connect_payarc`, `patwc_refresh_payarc_terminals`, and `patwc_disconnect_payarc` endpoints; keep lifecycle endpoints unchanged.
- Modify `includes/Gateway.php`: replace manual-only tenant/terminal setup with Connect button, credentials, callback token, terminal dropdown, diagnostics; preserve advanced/manual override only where needed for field recovery.
- Modify `assets/js/admin.js`: wire Connect/Refresh/Disconnect buttons without exposing secrets in DOM output.
- Modify tests under `tests/regression/`: add connection service tests, admin AJAX tests, settings/gateway validation updates, sale client token test updates, no-mock-copy guard.
- Modify `README.md`, `docs/payarc-sandbox-validation.md`, and optionally supersede `docs/payarc-mock-contract.md`: merchant test instructions, exact required PayArc dashboard values, callback URL/token guidance, what cannot be verified without a merchant account.
- Modify `payarc-terminal-for-woocommerce.php`: version bump for the new test-ready release.

## Task 1: Connection Service Tests First

**Files:**
- Create test: `tests/regression/connection-service.php`
- Create implementation later: `includes/Services/PayArcConnectionService.php`
- Modify: `tests/run.php` only if needed by the existing glob runner (not expected)

- [ ] **Step 1: Write failing test**

Test desired behavior against fake `wp_remote_request` responses:

```php
$settings = new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
));
$service = new PayArcConnectionService($settings, $option_store);
$result = $service->connect();
assert($result['status'] === 'connected');
assert($result['tenant_id'] === '123456789012');
assert($result['terminals'][0]['terminal_id'] === '1850528139');
assert($stored['connect_access_token'] === 'connect-access-token');
assert($stored['terminal_registry'][0]['terminal_id'] === '1850528139');
```

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL because `PayArcConnectionService` does not exist.

- [ ] **Step 3: Implement minimal service**

Create service with:

```php
public function connect(): array;
public function refresh_terminals(): array;
public function disconnect(): array;
public function ensure_connect_access_token(): string;
public function login(): array;
public function terminal_registry(): array;
```

Use URLs from `Settings`; authenticate Login/registry with `Authorization: Bearer <connect_secret_key>`; authenticate V3 requests later with stored `connect_access_token`.

- [ ] **Step 4: Run green test**

Run: `composer run test`
Expected: all tests pass.

## Task 2: Settings and Terminal Derivation

**Files:**
- Modify: `includes/Settings.php`
- Modify: `includes/Services/TerminalService.php`
- Modify tests: `tests/regression/settings.php`, `tests/regression/payment-service.php`

- [ ] **Step 1: Write failing tests**

Test that:

```php
$settings = new Settings(array(
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '',
    'terminal_registry' => array(array('terminal_id' => '1850528139', 'enabled' => true)),
));
assert($settings->tenant_id() === '123456789012');
assert($settings->default_terminal_id() === '1850528139');
```

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL because current settings do not derive tenant/terminal.

- [ ] **Step 3: Implement minimal settings/terminal updates**

Add getters for credential fields, `connect_access_token`, `connect_token_expires_at`, `terminal_registry`, `merchant_api_base_url`, `connect_login_base_url`, and derive tenant id from the last 12 MID digits. Preserve old fields as advanced overrides where tests require backwards compatibility.

- [ ] **Step 4: Run green test**

Run: `composer run test`
Expected: all tests pass.

## Task 3: Admin AJAX Connection Endpoints

**Files:**
- Modify: `includes/AjaxHandler.php`
- Modify tests: `tests/regression/ajax-access.php`

- [ ] **Step 1: Write failing tests**

Assert `init()` registers:

```php
wp_ajax_patwc_connect_payarc
wp_ajax_patwc_refresh_payarc_terminals
wp_ajax_patwc_disconnect_payarc
```

Assert endpoints require `manage_woocommerce` and nonce `patwc_payarc_connection`, call a fake connection service, and return sanitized fields only: `status`, `message`, `tenant_id_configured`, `terminal_count`, `default_terminal_id_configured`, `terminals` labels. Secrets must not be returned.

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL because endpoints do not exist.

- [ ] **Step 3: Implement endpoints**

Add optional constructor dependency for connection service. Keep payment lifecycle auth unchanged. Add request parsing for posted unsaved credential fields so a merchant can press Connect before Save.

- [ ] **Step 4: Run green test**

Run: `composer run test`
Expected: all tests pass.

## Task 4: Gateway Admin UX

**Files:**
- Modify: `includes/Gateway.php`
- Modify: `assets/js/admin.js`
- Modify tests: `tests/regression/gateway-diagnostics.php`, `tests/regression/gateway-ui.php`, `tests/regression/settings.php`

- [ ] **Step 1: Write failing tests**

Assert gateway fields include PayArc Connect credentials, callback token, terminal select, and a Connect panel. Assert existing secret rendering still does not print saved secrets. Assert old mock/manual-copy text is absent.

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL because fields/panel are absent.

- [ ] **Step 3: Implement UX**

Use field types:

```php
connect_email: text
connect_mid: text
connect_client_secret: patwc_secret
connect_secret_key: patwc_secret
callback_bearer_token: patwc_secret
default_terminal_id: select from Settings::terminal_registry_options()
connection: patwc_connection
```

The Connect button calls AJAX, persists returned options via service, and instructs merchant to save settings after a successful connection if WooCommerce requires it.

- [ ] **Step 4: Run green test**

Run: `composer run test`
Expected: all tests pass.

## Task 5: Transaction Client Uses Real Connect Token

**Files:**
- Modify: `includes/Services/PayArcClient.php`
- Modify tests: `tests/regression/client-sale-payload.php`

- [ ] **Step 1: Write failing tests**

Assert `sale()` sends `Authorization: Bearer <connect_access_token>`, not the Merchant Dashboard API token. Assert if token is missing/expired, client calls `ensure_connect_access_token()` before request.

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL because current client uses `api_bearer_token` directly.

- [ ] **Step 3: Implement client token update**

Inject connection service or create default one. Use Connect V3 URL `https://testpayarcconnectapi.payarc.net` for transactions. Preserve safe error messages and no secret leakage.

- [ ] **Step 4: Run green test**

Run: `composer run test`
Expected: all tests pass.

## Task 6: Docs, Version, Package

**Files:**
- Modify: `README.md`
- Modify: `docs/payarc-sandbox-validation.md`
- Modify: `docs/payarc-mock-contract.md`
- Modify: `payarc-terminal-for-woocommerce.php`
- Create release artifact after validation: `dist/payarc-terminal-for-woocommerce-<version>.zip`

- [ ] **Step 1: Write failing doc guard**

Add or extend a regression test that fails if README advertises `MOCK_CONTRACT_CREATED` or tells merchants to manually type terminal id as the primary path.

- [ ] **Step 2: Run red test**

Run: `composer run test`
Expected: FAIL until docs are updated.

- [ ] **Step 3: Update docs/version**

Document merchant-test-ready scope:

- Install plugin.
- Enter PayArc dashboard email, MID, ClientSecret, SecretKey/API bearer token.
- Enter PayArc-provided callback bearer token.
- Press Connect to run real PayArc Login and terminal discovery.
- Select terminal discovered from PayArc; terminal id is not typed manually in normal setup.
- Set callback URL/token with PayArc if required.
- Run a low-value test payment from WooCommerce POS.
- Developer did not live-certify with a merchant account; the build is ready for merchant validation against real PayArc endpoints.

- [ ] **Step 4: Full verification**

Run:

```bash
composer run test
composer run lint
```

Expected: all tests pass and PHP lint reports no syntax errors.

## Task 7: Commit, Push, PR/Release

**Files:** Git metadata and release zip only after validation.

- [ ] **Step 1: Pre-push checks**

Run:

```bash
git status --short
git branch -vv | grep "$(git branch --show-current)"
gh pr list --head "$(git branch --show-current)" --state all
```

- [ ] **Step 2: Commit**

Commit message:

```bash
git commit -m "feat: make PayArc setup merchant-test ready"
```

- [ ] **Step 3: Push and open PR**

Push branch and open a ready PR with validation evidence.

- [ ] **Step 4: Release**

Because `v0.1.0` is already published, do not move that tag. After merge to `main`, tag the next patch version and upload a zip artifact.
