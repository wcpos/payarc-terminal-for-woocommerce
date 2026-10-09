<?php

declare(strict_types=1);

// No Pro helpers at all: only the hooks the plugin file touches.
define('ABSPATH', '/');
$GLOBALS['hooks'] = array();
$GLOBALS['priorities'] = array();
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; $GLOBALS['priorities'][$name][] = $priority; }
function add_filter() { throw new LogicException('Registered a filter without Pro'); }
function register_activation_hook() {}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.test/'; }
function esc_html__($text, $domain) { return $text; }
function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, 'FAILED: ' . $what . "\n"); exit(1); } }

require dirname(__DIR__, 3) . '/payarc-terminal-for-woocommerce.php';

foreach ($GLOBALS['hooks']['plugins_loaded'] as $callback) { $callback(); }
check(count($GLOBALS['hooks']['plugins_loaded']) === 1 && $GLOBALS['priorities']['plugins_loaded'][0] === 30, 'one plugins_loaded hook, at 30 (Pro defines its helpers at 20)');
check(array_keys($GLOBALS['hooks']) === array('before_woocommerce_init', 'admin_enqueue_scripts', 'plugins_loaded', 'admin_notices'), 'without Pro, only the notice is added');
ob_start(); foreach ($GLOBALS['hooks']['admin_notices'] as $callback) { $callback(); } $notice = ob_get_clean();
check(strpos($notice, 'WooCommerce POS Pro 2.0.0') !== false, 'the notice names the requirement');
check(!class_exists('WCPOS\\WooCommercePOS\\PayArcTerminal\\AjaxHandler', false) && !class_exists('WCPOS\\WooCommercePOS\\PayArcTerminal\\Gateway', false), 'no handler and no gateway were loaded');
echo "bootstrap gate (no Pro) cases passed\n";
