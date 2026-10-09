<?php

declare(strict_types=1);

// Pro present: the gate passes only when Pro says the version is compatible.
define('ABSPATH', '/');
$GLOBALS['hooks'] = array(); $GLOBALS['priorities'] = array(); $GLOBALS['filters'] = array(); $GLOBALS['providers'] = array(); $GLOBALS['requirements'] = array();
$GLOBALS['compatible'] = true;
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; $GLOBALS['priorities'][$name][] = $priority; }
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['filters'][] = $name; $GLOBALS['filter_callbacks'][$name] = $callback; }
function register_activation_hook($file, $callback) { $GLOBALS['activation'] = $callback; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.test/'; }
function esc_html__($text, $domain) { return $text; }
function wp_next_scheduled($hook) { return time(); }
function wp_schedule_event() {}
class WC_Payment_Gateway {}
function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, 'FAILED: ' . $what . "\n"); exit(1); } }

require dirname(__DIR__, 3) . '/payarc-terminal-for-woocommerce.php';

// Pro defines its helpers from its own plugins_loaded hook at priority 20.
add_action('plugins_loaded', static function () {
    if (function_exists('wcpos_pro_requires')) { return; } // The second boot runs this hook again.
    function wcpos_pro_requires($version, $file = '') { $GLOBALS['requirements'][] = array($version, basename((string) $file)); return $GLOBALS['compatible']; }
    function wcpos_pro_register_server_provider($id, $class) { $GLOBALS['providers'][] = array($id, $class); }
}, 20);
$callbacks = $GLOBALS['hooks']['plugins_loaded']; $priorities = $GLOBALS['priorities']['plugins_loaded'];
array_multisort($priorities, $callbacks);

function boot(bool $compatible): array
{
    global $callbacks;
    $GLOBALS['compatible'] = $compatible; $GLOBALS['filters'] = array(); $GLOBALS['providers'] = array(); $GLOBALS['requirements'] = array();
    $before = array_keys($GLOBALS['hooks']);
    $GLOBALS['filter_callbacks'] = array();
    foreach ($callbacks as $callback) { $callback(); }
    $notices = array_map(static function ($c) { return is_string($c) ? $c : 'other'; }, $GLOBALS['hooks']['admin_notices'] ?? array());
    $added = array_values(array_diff(array_keys($GLOBALS['hooks']), $before)); // What the boot registered, read before cleanup.
    $gateways = isset($GLOBALS['filter_callbacks']['woocommerce_payment_gateways']) ? $GLOBALS['filter_callbacks']['woocommerce_payment_gateways'](array('other')) : null;
    $GLOBALS['hooks'] = array_intersect_key($GLOBALS['hooks'], array_flip($before)); // Keep plugins_loaded; drop what the boot added.
    return array('notices' => $notices, 'filters' => array_values(array_unique($GLOBALS['filters'])), 'providers' => $GLOBALS['providers'], 'requirements' => $GLOBALS['requirements'], 'added' => $added, 'gateways' => $gateways);
}

$ok = boot(true);
check($ok['requirements'] === array(array('2.0.0', 'payarc-terminal-for-woocommerce.php')), 'the gate asks Pro for 2.0.0 and names the plugin file');
check($ok['notices'] === array(), 'a compatible Pro gets no requirement notice');
check(in_array('woocommerce_payment_gateways', $ok['filters'], true), 'the gateway is registered behind the gate');
check($ok['providers'] === array(array('payarc_terminal_for_woocommerce', 'WCPOS\\WooCommercePOS\\PayArcTerminal\\Server\\PayArc_Server_Provider')), 'the server adapter is registered once');
foreach (array('wp_ajax_patwc_start_payment', 'wp_ajax_nopriv_patwc_poll_payment', 'wp_ajax_patwc_connect_payarc', 'wp_ajax_patwc_payarc_callback', 'wp_ajax_nopriv_patwc_payarc_callback') as $hook) {
    check(in_array($hook, $ok['added'], true), 'the gate registers ' . $hook);
}
check($ok['gateways'] === array('other', 'WCPOS\\WooCommercePOS\\PayArcTerminal\\Gateway'), 'the gateway filter adds the gateway class');

$GLOBALS['hooks'] = array('plugins_loaded' => $GLOBALS['hooks']['plugins_loaded']);
$old = boot(false);
check($old['notices'] === array('patwc_pro_requirement_notice'), 'an incompatible Pro gets the notice');
check($old['filters'] === array() && $old['providers'] === array() && $old['added'] === array('admin_notices'), 'an incompatible Pro registers neither the gateway, the adapter, nor any AJAX or callback action');

// Activation records the requirement for Pro's own notice, after the PHP check.
$GLOBALS['requirements'] = array();
$GLOBALS['activation']();
check($GLOBALS['requirements'] === array(array('2.0.0', 'payarc-terminal-for-woocommerce.php')), 'activation records the Pro requirement');
echo "bootstrap gate (Pro) cases passed\n";
