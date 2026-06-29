# PayArc Terminal for WooCommerce

PayArc PAX Terminal integration for WooCommerce POS.

## Installation

1. Copy this plugin directory to `wp-content/plugins/payarc-terminal-for-woocommerce`.
2. From the plugin directory, run `composer dump-autoload` if your deployment relies on Composer autoloading.
3. Activate **PayArc Terminal for WooCommerce** in WordPress.
4. Configure WooCommerce POS/PayArc settings when the gateway implementation is available.

## Setup notes

This repository is currently scaffolded for mock-only development. Live PayArc integration remains gated until real account credentials and approved integration details are available.

## First release scope

Warning: the first release supports PayArc Connect V3 sale, callback, and cancel flows only. Other transaction types and PayArc APIs are out of scope for the initial release.
