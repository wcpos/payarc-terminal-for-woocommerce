# PayArc PAX Terminal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a new WooCommerce payment gateway plugin that sends card-present payments to PayArc-managed PAX terminals through PayArc Connect and reconciles final results through server callbacks, without connecting the browser directly to the terminal.

**Architecture:** The browser/POS talks only to WordPress AJAX. WordPress sends PayArc Connect transaction commands, stores the client-generated `transactionId` as the primary local correlation key, stores `traceId` when PayArc returns or later provides it, receives PayArc's asynchronous callback, verifies the callback bearer token, then re-fetches the transaction from PayArc before any successful order completion. The POS UI polls WordPress for status; PayArc remains the only system that communicates with the PAX terminal.

**Tech Stack:** WordPress/WooCommerce plugin, PHP 7.4-compatible service classes, WordPress HTTP API, WooCommerce order/payment APIs, jQuery-based POS/payment UI, plain regression PHP tests matching the Mollie plugin style.

---

## Source Evidence

- Existing local patterns:
  - `/Users/kilbot/Projects/mollie-terminal-for-woocommerce/includes/Services/MolliePaymentService.php`
  - `/Users/kilbot/Projects/mollie-terminal-for-woocommerce/includes/WebhookHandler.php`
  - `/Users/kilbot/Projects/sumup-terminal-for-woocommerce/includes/Services/ReaderService.php`
  - `/Users/kilbot/Projects/sumup-terminal-for-woocommerce/includes/AjaxHandler.php`
  - `/Users/kilbot/Projects/square-terminal-for-woocommerce/includes/AjaxHandler.php`
  - `/Users/kilbot/Projects/square-terminal-for-woocommerce/includes/WebhookHandler.php`
- PayArc docs used for this plan:
  - [PayArc docs index](https://docs.payarc.net/llms.txt)
  - [PayArc Connect Getting Started](https://docs.payarc.net/reference/getting-started-1)
  - [PayArc Connect V3 Sale](https://docs.payarc.net/reference/createsaletransaction)
  - [PayArc Connect V3 Transaction Callback](https://docs.payarc.net/reference/transaction-callback-v3)
  - [PayArc Connect V3 Get Transaction](https://docs.payarc.net/reference/get_v3-transactions-traceid)
  - [PayArc Connect V3 Cancel](https://docs.payarc.net/reference/post_v3-transactions-traceid-cancel)
  - [PayArc Terminal Registry](https://docs.payarc.net/reference/get-terminal-registry)
  - [PayArc Add New Terminal](https://docs.payarc.net/reference/add-new-terminal)
  - [PayArc PAX installation guide](https://support.payarc.com/hc/en-us/articles/36312704576535--PAX-Installation-Activations-Guide)

## Reviewer Confirmation Points

This plan makes these concrete MVP choices and hard gates:

1. Use **PayArc Connect V3** for sale, get-transaction, and cancel commands only after Task 0 proves the sandbox accepts the V3 flow for the available merchant/test terminal.
2. Do **not** assume the sale response contains `traceId`. Use the client-generated `transactionId` as the primary local correlation key and store `traceId` only when the sale response or callback provides it.
3. Do **not** assume a manually configured static bearer token is sufficient. Task 0 must prove whether `/v3/transactions/sale` accepts the configured bearer directly or requires an access-token exchange. If exchange is required, stop and revise Tasks 2 and 4 before implementation.
4. Configure a separate **PayArc-provisioned callback bearer token** in gateway settings and reject callbacks whose bearer authorization header does not match. The merchant/operator pastes the token PayArc is configured to send; the plugin does not invent this token.
5. Treat callback bodies as triggers, not as the source of truth for successful payment completion. For a successful callback, fetch the transaction from PayArc by `traceId` and reconcile the fetched record before `payment_complete()`.
6. Use one configured default terminal first: `tenantId` plus 10-digit PAX `terminalId`/serial. Terminal registry/listing can be added after the sale flow is proven.
7. Treat PayArc statuses `SUCCESS` and `APPROVED` as approved only when confirmed by the fetched PayArc transaction record; treat all other statuses as unpaid final or non-final according to the status normalizer below.
8. Do not implement refunds/voids in the first implementation pass. Store PayArc `chargeId`, `transactionId`, and `traceId` so void/refund work can be added safely after live sale reconciliation is validated.

If a reviewer rejects any of those choices, or Task 0 disproves any API assumption, revise this plan before implementation.

## File Structure

Create the new plugin under `/Users/kilbot/Projects/payarc-terminal-for-woocommerce`.

```text
/Users/kilbot/Projects/payarc-terminal-for-woocommerce/
  AGENTS.md
  README.md
  composer.json
  payarc-terminal-for-woocommerce.php
  assets/
    css/payment.css
    js/admin.js
    js/payment.js
  docs/
    payarc-api-spike.md
    payarc-sandbox-validation.md
    superpowers/plans/2026-06-29-payarc-pax-terminal.md
  includes/
    AjaxHandler.php
    Gateway.php
    Logger.php
    PaymentAttempt.php
    PaymentLock.php
    PaymentReconciler.php
    Settings.php
    WebhookHandler.php
    Services/
      PayArcClient.php
      PayArcPaymentService.php
      TerminalService.php
    Utils/
      Money.php
      PayArcIds.php
  tests/
    regression/
      ajax-access.php
      client-sale-payload.php
      gateway-ui.php
      money-safety.php
      payment-attempt.php
      payment-service.php
      reconciler-callbacks.php
      settings.php
      webhook-auth.php
    run.php
```

### Responsibilities

- `payarc-terminal-for-woocommerce.php`: plugin bootstrap, constants, PSR-4 autoloader, WooCommerce gateway registration, AJAX/webhook handler creation.
- `Settings.php`: reads gateway settings, returns mode/base URL/token/tenant/terminal/callback settings.
- `Gateway.php`: WooCommerce gateway settings, checkout redirect behavior, admin diagnostics, payment UI rendering.
- `PayArcClient.php`: low-level WordPress HTTP API wrapper for PayArc Connect V3.
- `TerminalService.php`: validates configured tenant/terminal data and later owns terminal listing.
- `PayArcPaymentService.php`: order-level start, poll, cancel orchestration with locking and attempt reuse.
- `PaymentAttempt.php`: stores current PayArc attempt and immutable attempt history in order meta.
- `PaymentLock.php`: order-scoped lock to prevent duplicate sale commands.
- `PaymentReconciler.php`: validates PayArc lookup payloads and updates WooCommerce order state; it never marks an order paid from an unverified callback body alone.
- `WebhookHandler.php`: external callback endpoint, bearer-token verification, JSON decode, order lookup, PayArc re-fetch by `traceId`, reconciliation.
- `AjaxHandler.php`: POS/browser actions for start, poll, cancel, and diagnostics.
- `Money.php`: converts WooCommerce order totals to PayArc minor units and builds the PayArc `amount` object.
- `PayArcIds.php`: generates PayArc-safe transaction IDs and idempotency keys.

---

## Task 0: PayArc Sandbox API Spike

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-api-spike.md`

This is a hard gate. Do not scaffold implementation code until this task records the real PayArc sandbox behavior for the merchant/test terminal that will be used for development.

- [ ] Create the project docs directory for spike evidence.

```bash
mkdir -p /Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs
```

- [ ] Export sandbox values in the shell without writing secrets to git.

```bash
read -rsp 'PayArc Connect token: ' PAYARC_CONNECT_TOKEN; printf '\n'; export PAYARC_CONNECT_TOKEN
read -rp 'PayArc tenant ID (12 digits): ' PAYARC_TENANT_ID; export PAYARC_TENANT_ID
read -rp 'PAX terminal ID / serial (10 digits): ' PAYARC_TERMINAL_ID; export PAYARC_TERMINAL_ID
read -rp 'Public HTTPS callback URL: ' PAYARC_CALLBACK_URL; export PAYARC_CALLBACK_URL
export PAYARC_IDEMPOTENCY_KEY="$(uuidgen)"
export PAYARC_TRANSACTION_ID="P$(date +%s | tail -c 10)"
```

Expected: the variables exist only in the current shell. Do not echo token values into terminal output that will be pasted into review notes.

- [ ] Send one V3 sale request with the PayArc `amount` object shape.

```bash
cat > /tmp/payarc-sale-payload.json <<JSON
{
  "tenantId": "${PAYARC_TENANT_ID}",
  "terminalId": "${PAYARC_TERMINAL_ID}",
  "transactionId": "${PAYARC_TRANSACTION_ID}",
  "tenderType": "CREDIT",
  "amount": {
    "total": 109,
    "subtotal": 109,
    "currency": "USD",
    "tip": 0,
    "tax": 0
  },
  "printReceipt": 0,
  "callbackURL": "${PAYARC_CALLBACK_URL}",
  "metadata": {
    "order_id": "sandbox-spike",
    "terminal_id": "${PAYARC_TERMINAL_ID}",
    "mode": "test"
  }
}
JSON

curl -sS -i \
  -X POST 'https://testpayarcconnectapi.payarc.net/v3/transactions/sale' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${PAYARC_CONNECT_TOKEN}" \
  -H "X-Idempotency-Key: ${PAYARC_IDEMPOTENCY_KEY}" \
  --data-binary @/tmp/payarc-sale-payload.json \
  -o /tmp/payarc-sale-response.txt
```

Expected: HTTP status is `200` and the response body proves one of these outcomes:
- **Proceed without auth changes:** the configured bearer token works directly and the body includes a synchronous `traceId` usable for get/cancel.
- **Stop and revise for trace handling:** the configured bearer token works directly but the body lacks synchronous `traceId`; browser polling can only show local pending state until callback provides `traceId`, and cancel cannot work unless PayArc exposes cancel-by-transactionId.
- **Stop and revise for auth:** the response is `401`, `403`, or otherwise proves an access-token exchange is required before sale calls.

- [ ] If a synchronous `traceId` is present, verify get-transaction returns the authoritative amount/status object.

```bash
read -rp 'PayArc traceId from sale response: ' PAYARC_TRACE_ID; export PAYARC_TRACE_ID

curl -sS -i \
  -X GET "https://testpayarcconnectapi.payarc.net/v3/transactions/${PAYARC_TRACE_ID}" \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer ${PAYARC_CONNECT_TOKEN}" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -o /tmp/payarc-get-transaction-response.txt
```

Expected: HTTP status is `200`; body contains the same transaction identity and an `amount` object. Record whether `amount.total`/`amount.approved` are minor units (`109` for `$1.09`) or decimal units.

- [ ] If a synchronous `traceId` is present, verify cancel behavior before card presentation.

```bash
cat > /tmp/payarc-cancel-payload.json <<JSON
{"tenantId":"${PAYARC_TENANT_ID}","terminalId":"${PAYARC_TERMINAL_ID}"}
JSON

curl -sS -i \
  -X POST "https://testpayarcconnectapi.payarc.net/v3/transactions/${PAYARC_TRACE_ID}/cancel" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${PAYARC_CONNECT_TOKEN}" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  --data-binary @/tmp/payarc-cancel-payload.json \
  -o /tmp/payarc-cancel-response.txt
```

Expected: record whether PayArc accepts cancel before card presentation, and record the exact failure shape if the terminal has already started processing.

- [ ] Complete or decline a sandbox transaction on the PAX terminal and capture the callback body from the HTTPS callback endpoint.

Expected callback facts to record:
- whether callback includes `traceId`
- whether callback echoes `transactionId`
- exact status values for approval, decline, timeout, and cancel when practical
- whether the callback `amount.total`/`amount.approved` use minor units
- whether the callback includes original `metadata.order_id`

- [ ] Save sanitized spike evidence to `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-api-spike.md`.

The evidence file must include these headings and must replace all secret values with redacted text before saving: `Authentication`, `Sale Response`, `Amount Units`, `Callback`, `Cancel`, and `Plan Impact`. Under each heading, record the actual HTTP status, redacted response shape, observed status names, observed amount units, whether synchronous `traceId` was returned, and the exact plan decision made before continuing.

- [ ] Apply the spike decision.

If the spike shows direct bearer auth works, amount values are minor-unit object fields, and synchronous `traceId` is returned, continue to Task 1 unchanged except for recording the evidence file. If any of those facts differ, stop implementation and update this plan before creating PHP code.

## Task 1: Scaffold Plugin Skeleton

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/AGENTS.md`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/composer.json`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/README.md`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/payarc-terminal-for-woocommerce.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Logger.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/run.php`
- Preserve: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-api-spike.md`

- [ ] Create the plugin directory and initialize git if the repository does not already exist. This is a brand-new standalone repository; if the repository already exists with a trunk branch, use an isolated git worktree before editing implementation files.

```bash
mkdir -p /Users/kilbot/Projects/payarc-terminal-for-woocommerce
cd /Users/kilbot/Projects/payarc-terminal-for-woocommerce
git init
git checkout -b feat/payarc-pax-terminal
```

Expected: `git status --short` shows untracked scaffold files only; if Task 0 already created `docs/payarc-api-spike.md`, keep it unmodified.

- [ ] Add `AGENTS.md` pointing to canonical rules, matching the existing plugin repos.

```markdown
# Global Agent Instructions

Canonical rule files live in `/Users/kilbot/.claude`.

Always treat these as the source of truth:
- `/Users/kilbot/.claude/CLAUDE.md`
- `/Users/kilbot/.claude/rules/*.mdc`

Canonical skills live in:
- `/Users/kilbot/.claude/skills`

Do not create duplicate rule/skill sets in `.codex` when the same content exists in `.claude`.
```

- [ ] Add `README.md` with a short description, installation and setup steps, and a warning that the first release supports PayArc Connect V3 sale/callback/cancel only.

- [ ] Add `composer.json` with PHP 7.4 compatibility and no PayArc SDK dependency.

```json
{
  "name": "wcpos/payarc-terminal-for-woocommerce",
  "description": "PayArc PAX Terminal integration for WooCommerce POS.",
  "type": "wordpress-plugin",
  "license": "GPL-3.0-or-later",
  "authors": [{"name": "kilbot", "email": "paul@kilbot.com"}],
  "require": {"php": ">=7.4", "ext-json": "*"},
  "require-dev": {},
  "config": {"platform": {"php": "7.4"}, "platform-check": false, "sort-packages": true},
  "scripts": {
    "lint": "find . -path ./vendor -prune -o -name '*.php' -print -exec php -l {} \\;",
    "test": "php tests/run.php"
  },
  "autoload": {"psr-4": {"WCPOS\\WooCommercePOS\\PayArcTerminal\\": "includes/"}}
}
```

- [ ] Add bootstrap file `payarc-terminal-for-woocommerce.php` with constants `PATWC_VERSION`, `PATWC_PLUGIN_DIR`, `PATWC_PLUGIN_URL`, a PSR-4 autoloader for `WCPOS\WooCommercePOS\PayArcTerminal\`, PHP 7.4 activation guard, gateway registration, `AjaxHandler`, and `WebhookHandler` initialization.

- [ ] Add `Logger::log($message, array $context = array(), $order = null): void` that sanitizes secrets before writing to WooCommerce logger source `payarc-terminal-for-woocommerce`.

- [ ] Add `tests/run.php` that executes every `tests/regression/*.php` file and exits non-zero on the first failure.

- [ ] Run syntax validation.

```bash
composer run lint
```

Expected: all created PHP files report `No syntax errors detected`.

- [ ] Commit scaffold.

```bash
git add AGENTS.md README.md composer.json payarc-terminal-for-woocommerce.php includes/Logger.php tests/run.php docs/payarc-api-spike.md
git commit -m "chore: scaffold PayArc terminal plugin"
```

---

## Task 2: Add Settings and Admin Gateway Fields

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Settings.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Gateway.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/assets/js/admin.js`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/settings.php`

- [ ] Add failing regression coverage for settings defaults and callback URL construction.

Assertions:
- default mode is `test`
- test Connect base URL is `https://testpayarcconnectapi.payarc.net`
- webhook URL is `admin_url('admin-ajax.php?action=patwc_payarc_callback')`
- callback token and API bearer token are never returned by diagnostics helpers

- [ ] Implement `Settings` with these methods:

```php
public const GATEWAY_ID = 'payarc_terminal_for_woocommerce';
public function mode(): string;
public function connect_base_url(): string;
public function api_bearer_token(): string;
public function callback_bearer_token(): string;
public function tenant_id(): string;
public function default_terminal_id(): string;
public function tender_type(): string;
public function print_receipt(): int;
public function webhook_url(): string;
public function diagnostics(): array;
```

- [ ] Implement gateway settings fields:

| Field | Purpose |
| --- | --- |
| `enabled` | Enable WooCommerce gateway. |
| `title` | Checkout title. |
| `description` | Checkout description. |
| `mode` | `test` or `production`; production disabled until production URL/token source is verified. |
| `api_bearer_token` | PayArc Connect bearer or access token proven by Task 0; used server-side only. |
| `callback_bearer_token` | PayArc-provisioned secret expected in callback `Authorization` header; merchants paste the value PayArc is configured to send. |
| `tenant_id` | Last 12 digits of merchant identifier. |
| `default_terminal_id` | 10-digit PAX terminal serial/id. |
| `tender_type` | `CREDIT` or `DEBIT`, default `CREDIT`. |
| `print_receipt` | PayArc receipt flag `0`, `1`, `2`, or `3`, default `0`. |
| `webhook_url` | Read-only displayed callback URL. |

- [ ] Add validation in `process_admin_options()`:
  - `tenant_id` must match `^[0-9]{12}$` when gateway is enabled.
  - `default_terminal_id` must match `^[0-9]{10}$` when gateway is enabled.
  - `tender_type` must be `CREDIT` or `DEBIT`.
  - `print_receipt` must be one of `0`, `1`, `2`, `3`.

- [ ] Run settings regression and syntax checks.

```bash
php tests/regression/settings.php
composer run lint
```

Expected: settings regression exits `0`, lint exits `0`.

- [ ] Commit settings and gateway fields.

```bash
git add includes/Settings.php includes/Gateway.php assets/js/admin.js tests/regression/settings.php
git commit -m "feat: add PayArc gateway settings"
```

---

## Task 3: Add Money and ID Utilities

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Utils/Money.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Utils/PayArcIds.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/money-safety.php`

- [ ] Write regression tests for minor-unit conversion and PayArc amount object construction:
  - `10.23 USD` becomes `1023`
  - `0.09 USD` becomes `9`
  - `10 JPY` becomes `10`
  - `Money::to_payarc_amount_object('10.23', 'USD')` returns `array('total' => 1023, 'subtotal' => 1023, 'currency' => 'USD', 'tip' => 0, 'tax' => 0)` when Task 0 confirms minor units
  - negative totals throw `InvalidArgumentException`
  - unsupported currency precision throws `InvalidArgumentException`

- [ ] Implement `Money` with explicit currency precision map for currencies accepted in the first release: `USD => 2`, `CAD => 2`, `GBP => 2`, `EUR => 2`, `JPY => 0`.

```php
public static function to_minor_units($amount, string $currency): int;
public static function to_payarc_amount_object($amount, string $currency, int $tip = 0, int $tax = 0): array;
```

`to_payarc_amount_object()` must return the PayArc V3 object shape with `total`, `subtotal`, `currency`, `tip`, and `tax`; it must not return a scalar amount.

- [ ] Write ID tests:
  - transaction IDs are 1-16 alphanumeric characters
  - transaction IDs are stable for the same order id and attempt uuid
  - idempotency keys are UUID strings

- [ ] Implement `PayArcIds`:

```php
public static function transaction_id(int $order_id, string $attempt_uuid): string;
public static function idempotency_key(): string;
```

`transaction_id()` must produce a PayArc-safe ID with prefix `P`, base36 order id, and a hash suffix trimmed to 16 characters.

- [ ] Run tests and lint.

```bash
php tests/regression/money-safety.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit utilities.

```bash
git add includes/Utils/Money.php includes/Utils/PayArcIds.php tests/regression/money-safety.php
git commit -m "feat: add PayArc money and ID utilities"
```

---

## Task 4: Add PayArc HTTP Client

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Services/PayArcClient.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/client-sale-payload.php`

- [ ] Before writing the client, read `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-api-spike.md`. If it says access-token exchange is required, stop and revise this task to add an access-token provider before sale/get/cancel calls.

- [ ] Add a test double for `wp_remote_request()` and assert the sale request uses:
  - `POST https://testpayarcconnectapi.payarc.net/v3/transactions/sale`
  - `Authorization` header value starts with `Bearer ` and uses the token mode proven by Task 0
  - `X-Idempotency-Key` header value is the generated UUID idempotency key
  - JSON body with `tenantId`, `terminalId`, `transactionId`, `tenderType`, `amount`, `printReceipt`, `callbackURL`, and `metadata`
  - exact amount object `array('total' => 1023, 'subtotal' => 1023, 'currency' => 'USD', 'tip' => 0, 'tax' => 0)` for a `$10.23` USD order when Task 0 confirms minor units

- [ ] Implement `PayArcClient` methods:

```php
public function sale(array $payload, string $idempotency_key): array;
public function get_transaction(string $trace_id): array;
public function cancel(string $trace_id, array $payload, string $idempotency_key): array;
```

- [ ] Normalize PayArc failures into `RuntimeException` messages that include non-secret `traceId`, PayArc error code, message, and friendly message when present.

- [ ] Never log the API bearer token, callback bearer token, raw `Authorization` header, or full request body when errors occur.

- [ ] Run client tests and lint.

```bash
php tests/regression/client-sale-payload.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit client.

```bash
git add includes/Services/PayArcClient.php tests/regression/client-sale-payload.php
git commit -m "feat: add PayArc Connect client"
```

---

## Task 5: Add Payment Attempt Storage and Locking

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/PaymentAttempt.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/PaymentLock.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/payment-attempt.php`

- [ ] Add tests for attempt lifecycle:
  - `record_new()` stores current attempt meta and appends history.
  - `update_status()` updates current status without erasing trace/transaction ids.
  - `is_non_final()` returns true for `created`, `pending`, `sent`, `processing`.
  - `is_final_unpaid()` returns true for `decline`, `declined`, `timeout`, `cancelled`, `canceled`, `failure`, `failed`, `dup transaction`.
  - successful status names normalize to `success`.

- [ ] Implement meta keys:

```php
_current_trace_id       = '_patwc_current_trace_id'
_current_transaction_id = '_patwc_current_transaction_id'
_current_status         = '_patwc_current_status'
_current_charge_id      = '_patwc_current_charge_id'
_current_terminal_id    = '_patwc_current_terminal_id'
_current_attempt        = '_patwc_current_attempt'
_attempt_history        = '_patwc_attempt_history'
_processed_callbacks    = '_patwc_processed_callbacks'
```

- [ ] Implement `PaymentLock::with_lock(int $order_id, string $operation, callable $callback): array` using a short-lived order option/transient key `patwc_lock_{$order_id}_{$operation}`, returning a conflict array if an active lock exists. Document in the class docblock that this lock is advisory and reduces duplicate commands, but is not a database-atomic mutex.

- [ ] Run attempt tests and lint.

```bash
php tests/regression/payment-attempt.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit attempt model and lock.

```bash
git add includes/PaymentAttempt.php includes/PaymentLock.php tests/regression/payment-attempt.php
git commit -m "feat: track PayArc payment attempts"
```

---

## Task 6: Add Payment Service Start/Poll/Cancel

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Services/TerminalService.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Services/PayArcPaymentService.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/payment-service.php`

- [ ] Implement `TerminalService::validate_default_terminal()` to enforce `tenantId` and `terminalId` format before a sale command is sent.

- [ ] Implement `PayArcPaymentService::start_payment_for_order($order, string $terminal_id = ''): array`:
  - return `already_paid` if `$order->is_paid()`
  - reuse existing non-final attempt instead of creating a duplicate sale
  - generate attempt UUID, client `transactionId`, and idempotency key
  - build V3 sale payload using `Money::to_payarc_amount_object()`, configured tender type, receipt flag, callback URL, and metadata `{order_id, terminal_id, mode}`
  - call `PayArcClient::sale()`
  - persist client `transactionId`, terminal ID, status `created`, response snapshot, and `traceId` only if PayArc returned it synchronously

- [ ] Implement `PayArcPaymentService::poll_order($order): array`:
  - return local current attempt if no `traceId`, with status `pending_callback` and `continue_polling` true
  - for non-final attempts with a `traceId`, call `get_transaction()` no more often than every 2 seconds per order
  - pass lookup payloads through `PaymentReconciler`; lookup payloads, not callback bodies, are authoritative for successful completion
  - return `continue_polling` true for non-final status and false for final status

- [ ] Implement `PayArcPaymentService::cancel_order_payment($order): array`:
  - if no current `traceId`, return `not_cancelable_without_trace` with an operator-facing message that PayArc did not provide a trace id yet
  - if current attempt is final, return reconciled final status
  - call `PayArcClient::cancel($trace_id, array('tenantId' => $settings->tenant_id(), 'terminalId' => $terminal_id), PayArcIds::idempotency_key())`
  - store status `cancel_requested` on accepted cancel
  - if PayArc reports transaction already processed, fetch transaction and reconcile instead of overwriting a completed payment

- [ ] Run service tests and lint.

```bash
php tests/regression/payment-service.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit service layer.

```bash
git add includes/Services/TerminalService.php includes/Services/PayArcPaymentService.php tests/regression/payment-service.php
git commit -m "feat: add PayArc payment service"
```

---

## Task 7: Add Callback Reconciliation

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/PaymentReconciler.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/WebhookHandler.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/reconciler-callbacks.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/webhook-auth.php`

- [ ] Add callback auth tests:
  - missing `Authorization` returns 401
  - non-bearer `Authorization` returns 401
  - wrong bearer token returns 401
  - matching bearer token with invalid JSON returns 400
  - matching bearer token with valid final payload and `traceId` calls `PayArcClient::get_transaction($trace_id)`, passes the fetched payload to the reconciler, and returns 200
  - matching bearer token with `SUCCESS` but no `traceId` returns 202 and does not call `payment_complete()`

- [ ] Add reconciliation tests:
  - fetched PayArc transaction with `SUCCESS` marks unpaid order paid and stores `chargeId`, `traceId`, card brand, entry mode, last4, processor response code/text
  - fetched PayArc transaction with `APPROVED` is treated as successful for compatibility with PayArc status wording
  - callback body saying `SUCCESS` but fetched PayArc transaction saying `DECLINE` leaves the order unpaid
  - `DECLINE` stores failure meta and leaves order unpaid
  - amount mismatch in the fetched PayArc transaction stores verification failure and leaves order unpaid
  - currency mismatch in the fetched PayArc transaction stores verification failure and leaves order unpaid
  - duplicate callback with same `traceId` and status is idempotent
  - paid order with a different transaction id produces conflict status and does not overwrite existing payment

- [ ] Implement `PaymentReconciler::reconcile($order, array $payload, string $source): array`:
  - accept PayArc lookup/fetched transaction payloads as authoritative data
  - normalize status with `normalize_status()`
  - verify metadata order id or current transaction id/trace id belongs to order
  - verify approved/total amount matches order total in minor units for successful payments
  - verify currency matches order currency
  - update `PaymentAttempt` for every valid lookup/fetched payload
  - call `$order->payment_complete($charge_id_or_trace_id)` only for valid successful fetched PayArc transaction payloads
  - add an order note for every final status

- [ ] Implement `WebhookHandler` AJAX routes:

```php
add_action('wp_ajax_patwc_payarc_callback', array($this, 'handle'));
add_action('wp_ajax_nopriv_patwc_payarc_callback', array($this, 'handle'));
```

- [ ] In `WebhookHandler::handle()`, read raw body, validate bearer token from `Authorization` or `HTTP_AUTHORIZATION`, decode JSON, find order by `metadata.order_id`, then fallback to `_patwc_current_trace_id` or `_patwc_current_transaction_id`. For any callback that includes `traceId`, call `PayArcClient::get_transaction($trace_id)` and pass the fetched response to `PaymentReconciler`; never mark an order paid from the callback body alone.

- [ ] Run callback tests and lint.

```bash
php tests/regression/webhook-auth.php
php tests/regression/reconciler-callbacks.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit callback reconciliation.

```bash
git add includes/PaymentReconciler.php includes/WebhookHandler.php tests/regression/reconciler-callbacks.php tests/regression/webhook-auth.php
git commit -m "feat: reconcile PayArc callbacks"
```

---

## Task 8: Add AJAX Payment Actions

**Files:**
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/AjaxHandler.php`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/ajax-access.php`

- [ ] Add tests for order access:
  - logged-in WooCommerce manager may start/poll/cancel
  - valid order token may start/poll/cancel for the matching order
  - invalid token is rejected with 403
  - missing order id is rejected with 400

- [ ] Implement AJAX routes:

```php
patwc_start_payment
patwc_poll_payment
patwc_cancel_payment
patwc_validate_settings
```

Register both privileged and nopriv actions for payment lifecycle routes because POS/order-pay can run without stable wp-admin cookies.

- [ ] Implement `can_access_order(int $order_id): bool` using:
  - `current_user_can('manage_woocommerce')`
  - `current_user_can('edit_shop_order', $order_id)`
  - signed `order_token` derived from order id, order key, and WordPress salt

- [ ] Return normalized JSON contracts:

```json
{"status":"created","trace_id":"de305d54-75b4-431b-adb2-eb6b9e546014","message":"Payment sent to terminal.","continue_polling":true}
{"status":"success","submit_form":true,"continue_polling":false}
{"status":"decline","retry_allowed":true,"continue_polling":false}
{"status":"cancel_requested","continue_polling":true}
```

- [ ] Run AJAX tests and lint.

```bash
php tests/regression/ajax-access.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit AJAX actions.

```bash
git add includes/AjaxHandler.php tests/regression/ajax-access.php
git commit -m "feat: add PayArc payment AJAX actions"
```

---

## Task 9: Add Gateway Rendering and Frontend Payment UI

**Files:**
- Modify: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Gateway.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/assets/js/payment.js`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/assets/css/payment.css`
- Test: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/tests/regression/gateway-ui.php`

- [ ] Add gateway tests:
  - `process_payment()` redirects unpaid orders to order-pay URL
  - `process_payment()` returns success for already-paid orders
  - `payment_fields()` renders order id, status region, start button, cancel button, and log container
  - localized script data contains AJAX URL, nonce, order id, order token, and user-facing strings

- [ ] Implement jQuery UI behavior:
  - Start button calls `patwc_start_payment`.
  - On success, disable start button, show cancel button, poll `patwc_poll_payment` every 1500ms.
  - Polling stops after 5 minutes with an operator-facing timeout message.
  - Successful status submits the WooCommerce order-pay form.
  - Decline/failure status re-enables start button and shows retry guidance.
  - Cancel button calls `patwc_cancel_payment`; if cancel is accepted, continue polling until final callback/lookup state.

- [ ] Keep browser logic terminal-agnostic. It must never connect to the PAX terminal IP, websocket, LAN address, or PayArc API directly.

- [ ] Run UI tests and lint.

```bash
php tests/regression/gateway-ui.php
composer run lint
```

Expected: tests and lint exit `0`.

- [ ] Commit UI.

```bash
git add includes/Gateway.php assets/js/payment.js assets/css/payment.css tests/regression/gateway-ui.php
git commit -m "feat: add PayArc terminal payment UI"
```

---

## Task 10: Add Admin Diagnostics and Sandbox Validation Notes

**Files:**
- Modify: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/includes/Gateway.php`
- Create: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-sandbox-validation.md`
- Modify: `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/README.md`

- [ ] Add admin diagnostics table showing non-secret values:
  - mode
  - Connect base URL
  - tenant id masked except last 4
  - default terminal id masked except last 4
  - webhook URL
  - last callback timestamp
  - last PayArc error code/message without tokens

- [ ] Add a `Validate Settings` admin action that performs local checks for required tokens, tenant id format, terminal id format, callback URL HTTPS, and receipt/tender enum values. Do not call PayArc from this admin action in the MVP.

- [ ] Carry forward the Task 0 spike results into `docs/payarc-sandbox-validation.md`, including the actual sanitized sale response shape, get-transaction shape, callback shape, token mode, and amount units.

- [ ] Document sandbox validation sequence:
  1. Activate plugin.
  2. Enter PayArc test bearer token, callback bearer token, tenant id, and PAX terminal id.
  3. Confirm callback URL is public HTTPS and reachable by PayArc.
  4. Start a test order payment from POS/order-pay.
  5. Confirm whether PayArc returned `traceId` synchronously or only via callback, and verify the plugin follows the Task 0 decision.
  6. Complete approved transaction on terminal.
  7. Confirm callback marks order paid and stores `chargeId`.
  8. Run decline, timeout, duplicate callback, and cancel-before-card-present scenarios.

- [ ] Run lint.

```bash
composer run lint
```

Expected: lint exits `0`.

- [ ] Commit diagnostics/docs.

```bash
git add includes/Gateway.php docs/payarc-sandbox-validation.md README.md
git commit -m "docs: add PayArc sandbox validation guide"
```

---

## Task 11: Full Validation and Review Gates

**Files:**
- Modify only files needed to fix issues found by validation.

- [ ] Run all local tests.

```bash
composer run test
composer run lint
```

Expected: both commands exit `0`.

- [ ] Run manual callback smoke test with a signed local payload against a public HTTPS test site or tunnel.

Use a sample body shaped like PayArc V3 callback payload:

```json
{
  "transactionId": "P123ABC456DEF",
  "transType": "SALE",
  "status": "SUCCESS",
  "chargeId": "charge_test_123",
  "authCode": "000000",
  "amount": {"total": 1023, "subtotal": 1023, "approved": 1023, "currency": "USD", "tip": 0, "tax": 0},
  "card": {"brand": "VISA", "entryMode": "CHIP", "last4": "1111"},
  "processor": {"type": "TRADITIONAL", "responseCode": "000000", "responseText": "OK"},
  "timestamp": "2026-06-29T12:00:00Z",
  "traceId": "de305d54-75b4-431b-adb2-eb6b9e546014",
  "metadata": {"order_id": "123", "terminal_id": "1850401309", "mode": "test"},
  "error": null
}
```

Expected: wrong bearer token returns `401`; correct bearer token returns `200`; matching unpaid order is marked paid only when amount/currency/order identity match.

- [ ] Run independent review before PR:

```bash
codex review --diff
```

Expected: no high-impact findings, or findings are fixed and re-reviewed.

- [ ] Run targeted security review focusing on callback authentication, order access tokens, amount/currency verification, duplicate callbacks, and secret redaction.

- [ ] Push branch and open PR only after tests/lint pass.

```bash
git status --short
git branch -vv | grep "$(git branch --show-current)" || true
gh pr list --head "$(git branch --show-current)" --state all
git push -u origin "$(git branch --show-current)"
gh pr create --title "Add PayArc PAX Terminal gateway" --body-file /tmp/payarc-terminal-pr.md
```

Expected: PR body includes validation results, PayArc docs links, known reviewer confirmation points, and sandbox evidence.

---

## Acceptance Criteria

- PayArc PAX payments are initiated only by the WordPress server; the browser never connects directly to the terminal or PayArc API.
- A PayArc sale command stores client transaction, terminal, attempt metadata, and `traceId` when PayArc provides it on the WooCommerce order.
- Callback authentication rejects missing, malformed, or wrong bearer tokens.
- Only successful PayArc responses verified by `get_transaction(traceId)` mark orders paid; a raw callback body alone never completes an order.
- Declines, failures, timeouts, and cancel responses leave orders unpaid and allow retry where safe.
- Duplicate callbacks and polling/callback races are idempotent.
- Amount and currency mismatch never mark an order paid.
- Logs and diagnostics never expose API bearer token, callback bearer token, or raw authorization headers.
- Local regression tests and syntax lint pass before push.

## Out of Scope for MVP

- Direct LAN/device connection to the PAX terminal.
- Browser EventSource/SSE connected to PayArc or PAX. Browser polling WordPress is enough for the first release.
- Refunds, voids, tip adjustment, pre-auth/post-auth, terminal registration UI, and V2 login-token automation.
- Multi-terminal selection UI beyond one configured default terminal.

## Self-Review

- Spec coverage: The plan covers pre-implementation PayArc API verification, server-driven sale creation, callback-triggered re-fetch, polling, cancel, admin settings, payment UI, test coverage, and review gates.
- Placeholder scan: No unresolved placeholder markers are present. Reviewer confirmation points are concrete assumptions, not implementation holes.
- Type consistency: File names, class names, method names, status names, and order meta keys are consistent across tasks.
- Scope check: The MVP is intentionally limited to sale/callback/poll/cancel for one configured terminal. Refunds, voids, terminal registry UI, and V2 auth automation are explicitly excluded until sale reconciliation is proven.
