<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

define('ABSPATH', '/');
define('PATWC_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
class WC_Payment_Gateway
{
    public $id = ''; public $enabled = 'no'; public $form_fields = array(); public $settings = array();
    public $title = ''; public $description = ''; public $method_title = ''; public $method_description = ''; public $has_fields = false; public $supports = array();
    // As WooCommerce does: the saved checkbox value decides $enabled, 'no' when none; this plugin must ignore it.
    public function init_settings(): void { $this->settings = get_option('woocommerce_' . $this->id . '_settings', array()); $this->enabled = ($this->settings['enabled'] ?? 'no') === 'yes' ? 'yes' : 'no'; }
    public function get_option($key, $default = null) { return $this->settings[$key] ?? $default; }
}
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function add_action() {}
function woocommerce_pos_request() { return $GLOBALS['ctx']['pos']; }
function is_checkout_pay_page() { return $GLOBALS['ctx']['pay_page']; }
function is_checkout() { return $GLOBALS['ctx']['checkout']; }
function current_user_can($cap) { return $cap === 'access_woocommerce_pos' && $GLOBALS['ctx']['pos_user']; }
function wcpos_get_settings($group) { return array('gateways' => array(Settings::GATEWAY_ID => array('enabled' => $GLOBALS['ctx']['switch']))); }
function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, 'FAILED: ' . $what . "\n"); exit(1); } }

require PATWC_PLUGIN_DIR . 'includes/Settings.php';
require PATWC_PLUGIN_DIR . 'includes/Gateway.php';

$configured = array('tenant_id' => '123456789012', 'default_terminal_id' => '1234567890', 'enabled' => 'no'); // The old web-checkout checkbox, as most sites saved it: it counts for nothing either way.
function available(array $settings, array $ctx): bool
{
    $GLOBALS['options'] = array('woocommerce_' . Settings::GATEWAY_ID . '_settings' => $settings);
    $GLOBALS['ctx'] = $ctx + array('pos' => false, 'pay_page' => false, 'checkout' => false, 'pos_user' => false, 'switch' => false);
    Settings::reset_enabled_for_pos_cache();
    return (new Gateway())->is_available();
}

check(available($configured, array('pos' => true)), 'available on a POS request');
check(!available($configured, array('checkout' => true, 'pos_user' => true, 'switch' => true)), 'never on the shop checkout, whoever is looking and whatever is saved');
check(available($configured, array('pay_page' => true, 'pos_user' => true, 'switch' => true)), 'available on the order-pay page to a POS user when the POS switch is on');
check(!available($configured, array('pay_page' => true, 'pos_user' => true, 'switch' => false)), 'the order-pay page honours the POS switch: off means unavailable, whatever the old checkbox saved');
check(!available($configured, array('pay_page' => true, 'pos_user' => false, 'switch' => true)), 'the order-pay page is for users who may run the POS');
check(!available(array('tenant_id' => '', 'default_terminal_id' => '1234567890'), array('pos' => true)), 'unconfigured (no MID) is unavailable even to the POS');
check(!available(array('tenant_id' => '123456789012', 'default_terminal_id' => ''), array('pos' => true)), 'unconfigured (no terminal) is unavailable even to the POS');
$old_checkbox_on = array('enabled' => 'yes') + $configured; // array union keeps the left operand's keys
check($old_checkbox_on['enabled'] === 'yes' && !available($old_checkbox_on, array('checkout' => true, 'pos_user' => true, 'switch' => true)) && available($old_checkbox_on, array('pos' => true)), 'a saved enabled=yes neither brings web checkout back nor is needed by the POS');
// The connection checks follow the POS switch, not the old checkbox.
$GLOBALS['ctx'] = array('switch' => false); Settings::reset_enabled_for_pos_cache();
check(Gateway::validate_settings(array('tenant_id' => '', 'default_terminal_id' => '', 'tender_type' => 'CREDIT', 'print_receipt' => '0', 'enabled' => 'yes')) === array(), 'with the POS switch off, a saved enabled=yes does not make the connection checks apply');
$GLOBALS['ctx'] = array('switch' => true); Settings::reset_enabled_for_pos_cache();
check(count(Gateway::validate_settings(array('tenant_id' => '', 'default_terminal_id' => '', 'tender_type' => 'CREDIT', 'print_receipt' => '0'))) === 2, 'with the POS switch on, the MID and serial are required');
check(!array_key_exists('enabled', (new Gateway())->form_fields) && isset((new Gateway())->form_fields['section_pos']), 'the web-checkout checkbox is gone; the settings page says where the switch is');
echo "gateway availability cases passed\n";
