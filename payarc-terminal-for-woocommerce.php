<?php
/**
 * Plugin Name: PayArc Terminal for WooCommerce
 * Description: PayArc PAX Terminal integration for WooCommerce POS.
 * Version: 0.1.18
 * Author: kilbot
 * License: GPL-3.0-or-later
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PATWC_VERSION', '0.1.18');
define('PATWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PATWC_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    $prefix = 'WCPOS\\WooCommercePOS\\PayArcTerminal\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = PATWC_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_readable($file)) {
        require $file;
    }
});

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, static function () {
        if (version_compare(PHP_VERSION, '7.4', '>=')) {
            // Terminal extensions are Pro-only at 2.0; Pro records the requirement for its own notice.
            if (function_exists('wcpos_pro_requires')) {
                wcpos_pro_requires('2.0.0', __FILE__);
            }

            return;
        }

        if (function_exists('deactivate_plugins') && function_exists('plugin_basename')) {
            deactivate_plugins(plugin_basename(__FILE__));
        }

        if (function_exists('wp_die')) {
            wp_die(esc_html__('PayArc Terminal for WooCommerce requires PHP 7.4 or higher.', 'payarc-terminal-for-woocommerce'));
        }
    });
}

if (function_exists('add_action')) {
    add_action('before_woocommerce_init', static function () {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    });

    add_action('admin_enqueue_scripts', static function () {
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('patwc-admin', PATWC_PLUGIN_URL . 'assets/js/admin.js', array(), PATWC_VERSION, true);
        }
    });

    // Terminal extensions are Pro-only at 2.0. WCPOS Pro defines wcpos_pro_requires() and its provider
    // registration from its own plugins_loaded hook at priority 20, so the gate runs at 30.
    add_action('plugins_loaded', 'patwc_init', 30);
}

/**
 * The notice shown when no compatible WCPOS Pro is active.
 */
function patwc_pro_requirement_notice(): void
{
    echo '<div class="notice notice-error"><p>' . esc_html__('PayArc Terminal for WooCommerce needs WooCommerce POS Pro 2.0.0 or newer.', 'payarc-terminal-for-woocommerce') . '</p></div>';
}

/**
 * Register everything, or nothing but a notice without a compatible WCPOS Pro.
 *
 * The POS keypad tile and the order-pay page both rest on Pro's shared payments base, so without it
 * the gateway, its AJAX actions, the legacy callback route and the server adapter all stay off.
 */
function patwc_init(): void
{
    $registration = 'WCPOS\\WooCommercePOS\\PayArcTerminal\\Server\\Registration';

    if (!function_exists('wcpos_pro_requires') || !wcpos_pro_requires($registration::REQUIRED_PRO_VERSION, __FILE__)) {
        add_action('admin_notices', 'patwc_pro_requirement_notice');

        return;
    }

    if (class_exists('WCPOS\\WooCommercePOS\\PayArcTerminal\\AjaxHandler')) {
        $ajaxHandler = new WCPOS\WooCommercePOS\PayArcTerminal\AjaxHandler();

        if (method_exists($ajaxHandler, 'init')) {
            $ajaxHandler->init();
        }
    }

    if (class_exists('WCPOS\\WooCommercePOS\\PayArcTerminal\\WebhookHandler')) {
        $webhookHandler = new WCPOS\WooCommercePOS\PayArcTerminal\WebhookHandler();

        if (method_exists($webhookHandler, 'init')) {
            $webhookHandler->init();
        }
    }

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gatewayClass = 'WCPOS\\WooCommercePOS\\PayArcTerminal\\Gateway';

        if (class_exists('WC_Payment_Gateway') && class_exists($gatewayClass) && is_subclass_of($gatewayClass, 'WC_Payment_Gateway')) {
            $gateways[] = $gatewayClass;
        }

        return $gateways;
    });

    $registration::register();
}
