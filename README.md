# PayArc Terminal for WooCommerce

PayArc PAX Terminal integration for WooCommerce POS.

## Installation

1. Copy this plugin directory to `wp-content/plugins/payarc-terminal-for-woocommerce`.
2. From the plugin directory, run `composer dump-autoload` if your deployment relies on Composer autoloading.
3. Activate **PayArc Terminal for WooCommerce** in WordPress.
4. Configure WooCommerce POS/PayArc settings when the gateway implementation is available.

## Setup notes

This repository is currently in `MOCK_CONTRACT_CREATED` status for PayArc Connect V3 behavior. The plugin has docs-derived fixtures for sale, get-transaction, callback, and cancel flows, but no live PayArc sandbox calls or terminal evidence have been collected yet. Do not claim production readiness until the sandbox validation guide is completed with sanitized live evidence.

Required secure settings in WooCommerce admin:

- PayArc test API bearer token.
- PayArc callback bearer token.
- Tenant ID, displayed only masked in diagnostics.
- PAX terminal ID, displayed only masked in diagnostics.
- Public HTTPS callback URL reachable by PayArc.

Use the gateway admin **Validate Settings** control for local checks only. It does not call PayArc; it verifies configured-token status, tenant/terminal formats, HTTPS callback URL, receipt enum, and tender enum without exposing secret values.

See `/Users/kilbot/Projects/payarc-terminal-for-woocommerce/docs/payarc-sandbox-validation.md` for the required live sandbox validation sequence.

## First release scope

Warning: the first release supports PayArc Connect V3 sale, callback, and cancel flows only. Other transaction types and PayArc APIs are out of scope for the initial release.
