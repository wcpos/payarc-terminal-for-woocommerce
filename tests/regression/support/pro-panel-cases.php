<?php

declare(strict_types=1);

require_once __DIR__ . '/pro-stubs.php';

use WCPOS\WooCommercePOS\PayArcTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcPaymentService;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\WebhookHandler;

function wp_salt($scheme = 'auth') { return 'salt'; }
function get_query_var($var, $default = '') { return $GLOBALS['query_vars'][$var] ?? $default; }
function wc_add_notice($m, $t = 'success') { $GLOBALS['notices'][] = $m; }
function wp_create_nonce($a = -1) { return 'nonce'; }
function wp_verify_nonce($n, $a = -1) { return $n === 'nonce' ? 1 : false; }
function current_user_can($cap, ...$args) { return !empty($GLOBALS['caps'][$cap]); }
function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_localize_script() {}
function admin_url($p = '') { return 'https://store.test/wp-admin/' . $p; }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); } function esc_url($v) { return $v; }
function get_transient($k) { return false; } function set_transient($k, $v, $e = 0) { return true; }
foreach (array('Gateway', 'AjaxHandler', 'WebhookHandler', 'Utils/Money', 'Utils/PayArcIds', 'Services/TerminalService', 'Services/PayArcPaymentService') as $file) { require_once PATWC_PLUGIN_DIR . 'includes/' . $file . '.php'; }
class PatwcPanelOrder extends PatwcOrder { public function get_order_key() { return 'wc_order_key' . $this->id; } }

$settings = array('mode' => 'test', 'connect_mid' => '123456789012', 'tenant_id' => '123456789012', 'connect_secret_key' => 's', 'default_terminal_id' => '1234567890', 'terminal_registry' => array(array('terminal_id' => '1234567890', 'label' => 'Front')), 'callback_bearer_token' => 'bearertok', 'tender_type' => 'CREDIT', 'print_receipt' => '0');
$live = array('attempt_uuid' => 'u1', 'transaction_id' => 'P1ABCDEF12345678', 'terminal_id' => '1234567890', 'status' => 'processing', 'trace_id' => 'trace-1');
function world(array $settings): void { patwc_reset_world(); $GLOBALS['options']['woocommerce_' . Settings::GATEWAY_ID . '_settings'] = $settings; $GLOBALS['query_vars'] = array(); $GLOBALS['notices'] = array(); $GLOBALS['caps'] = array(); $GLOBALS['reads'] = array(); }
function render(Gateway $gateway, int $order_id): string { $GLOBALS['query_vars']['order-pay'] = $order_id; ob_start(); $gateway->payment_fields(); return (string) ob_get_clean(); }
function panel_order(int $id, array $attempt = array(), bool $fresh = true): PatwcPanelOrder
{
    $order = new PatwcPanelOrder($id); $GLOBALS['orders'][$id] = $order;
    if ($attempt !== array()) { PaymentAttempt::record_new($order, $attempt); if (!$fresh) { $i = get_option(PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS, array()); $i[(string) $id]['updated_at'] = time() - 1801; update_option(PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS, $i); } }
    return $order;
}

// The page is Pro's panel: it adopts first, then renders Pro's panel; refusals show a notice and no panel.
world($settings); $gateway = new Gateway();
check(Settings::uses_pro_panel() && in_array('refunds', $gateway->supports, true), 'under the gate the order-pay page is Pro\'s panel and the gateway supports refunds');
panel_order(1);
$html = render($gateway, 1);
check(strpos($html, 'id="wcpos-pro-panel" data-order="1"') !== false && strpos($html, 'patwc-start-payment') === false, 'an order with nothing to adopt renders Pro\'s panel and none of the old one');
$order = panel_order(2, array_merge($live, array('trace_id' => 'trace-2')));
$html = render($gateway, 2);
check(strpos($html, 'id="wcpos-pro-panel" data-order="2"') !== false && $order->get_meta(Legacy_Adoption::META_ADOPTED) === 'trace-2', 'a live sale the old panel left is adopted before Pro\'s panel renders');
panel_order(3, array_merge($live, array('trace_id' => 'trace-3'))); $GLOBALS['free_lock_held'] = true;
$html = render($gateway, 3);
check(strpos($html, 'Another request is handling this order') !== false && strpos($html, 'wcpos-pro-panel') === false, 'a held lock shows a reload notice and no panel');
$GLOBALS['free_lock_held'] = false;
panel_order(4, array('status' => 'created', 'transaction_id' => 'P1ABCDEF12345679', 'attempt_uuid' => 'u4'));
$html = render($gateway, 4);
check(strpos($html, 'has not confirmed the start') !== false && strpos($html, 'wcpos-pro-panel') === false, 'an unanswered start shows its notice and no panel');
panel_order(5, array_merge($live, array('trace_id' => 'trace-5')), false); Legacy_Adoption::$reader = static function (string $t): array { throw new PayArcRequestException('nf', 'TRANSACTION_NOT_FOUND', 400); };
$html = render($gateway, 5);
check(strpos($html, 'not visible under the current PayArc credentials') !== false && strpos($html, 'wcpos-pro-panel') === false, 'a sale PayArc cannot see holds the panel back with its notice');
Legacy_Adoption::$reader = null;
panel_order(6, array_merge($live, array('trace_id' => 'trace-6'))); $GLOBALS['pro_refuses_adoption'] = true;
$html = render($gateway, 6);
check(strpos($html, 'could not be handed to WooCommerce POS') !== false && strpos($html, 'wcpos-pro-panel') === false, 'Pro refusing shows its notice and no panel');
$GLOBALS['pro_refuses_adoption'] = false;
$html = render($gateway, 999);
check(strpos($html, 'Open this page from the order') !== false, 'no order: a hint, no panel');
$GLOBALS['filters']['patwc_uses_pro_panel'] = false; panel_order(7);
check(strpos(render($gateway, 7), 'patwc-start-payment') !== false, 'with Pro\'s panel filtered off the old panel still renders (until 1.0.0 removes it)');
$GLOBALS['filters'] = array();

// The form submit and WooCommerce refunds go through Pro.
world($settings); $gateway = new Gateway(); panel_order(8);
check($gateway->process_payment(8) === array('result' => 'success', 'redirect' => 'https://store.test/received/8') && $GLOBALS['pro_processed'] === array(8), 'process_payment hands the submit to Pro');
check($gateway->process_payment(404) === array('result' => 'failure'), 'a missing order still fails safely');
$GLOBALS['ledger'][8] = array(array('id' => 'r1', 'method_id' => Settings::GATEWAY_ID, 'status' => 'captured', 'capture_mode' => 'server', 'amount' => '92.95'));
check($gateway->process_refund(8, 10.0, 'why') === true && $GLOBALS['pro_refunds'] === array(array(8, 10.0, 'why')), 'a payment Pro\'s ledger holds refunds through Pro');
$GLOBALS['ledger'][8] = array(array('id' => 'r1', 'method_id' => Settings::GATEWAY_ID, 'status' => 'captured', 'capture_mode' => 'webview', 'amount' => '92.95'));
$r = $gateway->process_refund(8, 10.0);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_refund_in_payarc' && count($GLOBALS['pro_refunds']) === 1, 'a payment the old panel completed is refunded from the PayArc dashboard, as before');
$GLOBALS['ledger'][8] = array(array('id' => 'r1', 'method_id' => 'other', 'status' => 'captured', 'capture_mode' => 'server', 'amount' => '92.95'));
check($gateway->process_refund(8, 10.0) instanceof WP_Error, 'another gateway\'s row is not this gateway\'s to refund');

// The old start, poll and cancel yield to Pro, judged under the lock on a fresh read.
world($settings); $s = new Settings($settings);
$client = new class { public $calls = array(); public function __call($m, $a) { $this->calls[] = $m; throw new RuntimeException('no PayArc call expected: ' . $m); } };
$service = new PayArcPaymentService($s, $client, new TerminalService($s), null);
$order = panel_order(9);
$r = $service->start_payment_for_order($order);
check($r['status'] === 'handled_by_pos' && !empty($r['handled_by_pos']) && $r['continue_polling'] === false && $client->calls === array() && !isset($order->meta[PaymentAttempt::META_CURRENT_ATTEMPT]) && !PaymentAttempt::is_in_flight($order), 'under Pro\'s panel the old start is refused outright: no sale, no attempt');
$GLOBALS['filters']['patwc_uses_pro_panel'] = false; $GLOBALS['ledger'][9] = array(array('id' => 'p1', 'method_id' => Settings::GATEWAY_ID, 'status' => 'pending', 'capture_mode' => 'server'));
$r = $service->start_payment_for_order($order);
check($r['status'] === 'handled_by_pos' && $client->calls === array(), 'a live Pro row on the order refuses an old start however the page renders');
$GLOBALS['filters'] = array(); $GLOBALS['ledger'] = array();
$order = panel_order(10, array_merge($live, array('trace_id' => 'trace-10'))); Legacy_Adoption::adopt_order(10);
check($service->poll_order($order)['status'] === 'handled_by_pos' && $service->cancel_order_payment($order)['status'] === 'handled_by_pos' && $client->calls === array(), 'the old poll and cancel of a sale Pro owns answer handled_by_pos without touching PayArc');
patwc_set_ledger_status(10, 'row-trace-10', 'cancelled');
try { $service->poll_order($order); } catch (RuntimeException $e) { }
check($client->calls === array('get_transaction'), 'once Pro\'s leg ended without money the old poll asks PayArc again, as before');

// The AJAX layer answers a handled_by_pos body with 409 and the flag the script stops on.
world($settings);
$order = panel_order(11); $fake = new class { public function poll_order($o) { return PayArcPaymentService::handled_by_pos(); } public function start_payment_for_order($o, $t = '') { return PayArcPaymentService::handled_by_pos(); } public function cancel_order_payment($o) { return PayArcPaymentService::handled_by_pos(); } };
$handler = new AjaxHandler($fake, static function (int $id) { return $GLOBALS['orders'][$id] ?? null; }, null, new stdClass());
$r = $handler->handle_poll(array('order_id' => '11', 'order_token' => AjaxHandler::order_token_for($order)));
check($r['status_code'] === 409 && $r['body']['handled_by_pos'] === true && $r['body']['status'] === 'handled_by_pos' && $r['body']['continue_polling'] === false && strpos($r['body']['message'], 'Reload the page') !== false, 'the AJAX answer is 409 with handled_by_pos');

// The old callback route: a sale Pro owns is acknowledged, not reconciled; Pro's capture closes the old attempt.
world($settings); $s = new Settings($settings);
$order = panel_order(12, array_merge($live, array('trace_id' => 'trace-12'))); Legacy_Adoption::adopt_order(12);
$reconciler = new class { public $calls = 0; public function reconcile($o, $p, $src) { ++$this->calls; return array('status' => 'success'); } };
$client = new class { public $calls = 0; public function get_transaction($t) { ++$this->calls; return array('traceId' => $t, 'status' => 'APPROVED'); } };
$webhook = new WebhookHandler($s, $client, $reconciler, static function (array $c) { return $GLOBALS['orders'][12]; });
$server = array('HTTP_AUTHORIZATION' => 'Bearer bearertok');
$r = $webhook->handle_request(json_encode(array('traceId' => 'trace-12', 'transactionId' => 'P1ABCDEF12345678', 'status' => 'APPROVED')), $server);
check($r['status_code'] === 202 && $r['body']['status'] === 'handled_by_pos' && $client->calls === 0 && $reconciler->calls === 0 && PaymentAttempt::current($order)['status'] === 'processing', 'a callback for a sale Pro owns is acknowledged and neither read nor reconciled here');
patwc_set_ledger_status(12, 'row-trace-12', 'captured');
$r = $webhook->handle_request(json_encode(array('traceId' => 'trace-12', 'status' => 'APPROVED')), $server);
check($r['status_code'] === 202 && PaymentAttempt::current($order)['status'] === 'success' && !PaymentAttempt::is_in_flight($order) && strpos(end($order->notes), 'completed through WooCommerce POS') !== false, 'Pro\'s capture closes the old attempt: the in-flight guard lets go, and the order says so');
$r = $webhook->handle_request(json_encode(array('traceId' => 'trace-12', 'status' => 'APPROVED')), $server);
check($r['status_code'] === 202 && count($order->notes) === 1, 'a repeat callback adds nothing');
patwc_set_ledger_status(12, 'row-trace-12', 'cancelled'); PaymentAttempt::update_status($order, 'processing');
$r = $webhook->handle_request(json_encode(array('traceId' => 'trace-12', 'status' => 'APPROVED')), $server);
check($r['status_code'] === 200 && $client->calls === 1 && $reconciler->calls === 1, 'once Pro\'s leg ended without money the callback route reconciles again, as before');
echo "pro panel cases passed\n";
