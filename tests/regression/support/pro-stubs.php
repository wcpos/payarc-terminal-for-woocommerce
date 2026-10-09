<?php

/**
 * WordPress, WooCommerce, Free (Order_Lock, Ledger) and WCPOS Pro (wcpos_pro_*) stand-ins for the
 * cases that run the plugin under Pro's order-pay panel. Loaded by isolated case files only.
 */

declare(strict_types=1);

namespace WCPOS\WooCommercePOS\Payments\Contract {
    final class Order_Lock
    {
        public static function instance(): self { static $i = null; return $i ?? ($i = new self()); }
        public function with_lock(int $order_id, callable $callback)
        {
            if (!empty($GLOBALS['free_lock_held'])) {
                return new \WP_Error('wcpos_payment_locked', 'Another payment operation is running on this order.', array('status' => 409));
            }
            $GLOBALS['free_lock_depth'] = ($GLOBALS['free_lock_depth'] ?? 0) + 1;
            try { return $callback(); } finally { --$GLOBALS['free_lock_depth']; }
        }
    }
    final class Ledger
    {
        public const LIVE_STATUSES = array('pending', 'authorized', 'captured');
        public const COUNTING_STATUSES = array('authorized', 'captured');
        public static function instance(): self { static $i = null; return $i ?? ($i = new self()); }
        public function read($order): array { return $GLOBALS['ledger'][$order->get_id()] ?? array(); }
        public function find($order, string $id): ?array { foreach ($this->read($order) as $row) { if ($row['id'] === $id) { return $row; } } return null; }
    }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', '/'); }
    if (!defined('PATWC_PLUGIN_DIR')) { define('PATWC_PLUGIN_DIR', dirname(__DIR__, 3) . '/'); }
    class WC_Payment_Gateway
    {
        public $id = ''; public $enabled = 'no'; public $form_fields = array(); public $settings = array();
        public $title = ''; public $description = ''; public $method_title = ''; public $method_description = ''; public $has_fields = false; public $supports = array();
        public function init_settings(): void { $this->settings = get_option('woocommerce_' . $this->id . '_settings', array()); }
        public function get_option($key, $default = null) { return $this->settings[$key] ?? $default; }
    }
    class WP_Error
    {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
    class PatwcOrder
    {
        public $id; public $meta = array(); public $notes = array(); public $paid = false; public $saves = 0; public $total = '92.95'; public $currency = 'USD'; public $completed_with = '';
        public function __construct(int $id) { $this->id = $id; }
        public function get_id() { return $this->id; }
        public function is_paid() { return $this->paid; }
        public function needs_payment() { return !$this->paid; }
        public function get_total() { return $this->total; }
        public function get_currency() { return $this->currency; }
        public function get_status() { return $this->paid ? 'processing' : 'pending'; }
        public function get_meta($k, $single = true) { return $this->meta[$k] ?? ($single ? '' : array()); }
        public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
        public function delete_meta_data($k) { unset($this->meta[$k]); }
        public function add_order_note($n) { $this->notes[] = $n; }
        public function save() { $this->saves++; }
        public function read_meta_data($force = false) {}
        public function payment_complete($txn = '') { $this->paid = true; $this->completed_with = (string) $txn; }
        public function get_payment_method() { return $this->meta['_payment_method'] ?? ''; }
        public function get_payment_method_title() { return $this->meta['_payment_method_title'] ?? ''; }
        public function set_payment_method($m) { $this->meta['_payment_method'] = $m; }
        public function set_payment_method_title($t) { $this->meta['_payment_method_title'] = $t; }
        public function get_checkout_payment_url($on_checkout = false) { return 'https://store.test/pay/' . $this->id; }
    }
    function wc_get_order($id) { $o = $GLOBALS['orders'][(int) $id] ?? false; if ($o && !empty($GLOBALS['clone_orders'])) { $c = clone $o; $GLOBALS['orders'][(int) $id] = $c; return $c; } return $o; } // With clone_orders, each read is a separate object, as WooCommerce gives.
    function wc_get_orders($args) { $GLOBALS['order_queries'][] = $args; return $GLOBALS['order_ids'] ?? array(); }
    function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
    function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
    function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
    function is_wp_error($t) { return $t instanceof WP_Error; }
    function apply_filters($hook, $value) { return $GLOBALS['filters'][$hook] ?? $value; }
    function add_action($h, $c, $p = 10, $a = 1) { $GLOBALS['actions'][$h][] = $c; }
    function clean_post_cache($id) { $GLOBALS['cache_cleared'][] = $id; }
    function wcpos_pro_order_pay_panel($gateway, $order) { echo '<div id="wcpos-pro-panel" data-order="' . $order->get_id() . '" data-adopted="' . $order->get_meta(WCPOS\WooCommercePOS\PayArcTerminal\Legacy_Adoption::META_ADOPTED) . '"></div>'; }
    function wcpos_pro_order_pay_process($order) { $GLOBALS['pro_processed'][] = $order->get_id(); return array('result' => 'success', 'redirect' => 'https://store.test/received/' . $order->get_id()); }
    function wcpos_pro_order_pay_refund($order, $amount, $reason = '') { $GLOBALS['pro_refunds'][] = array($order->get_id(), $amount, $reason); return true; }
    function wcpos_pro_payment_id_for_action($provider, $ref) { $GLOBALS['lookups'][] = array($provider, $ref); return $GLOBALS['adopted'][$ref] ?? null; }
    function wcpos_pro_adopt_legacy_attempt($order, $gateway_id, $ref, $amount, $currency)
    {
        if (!empty($GLOBALS['pro_refuses_adoption'])) { return new WP_Error('wcpos_adopt_unsupported', 'refused'); }
        $row = array('id' => 'row-' . $ref, 'method_id' => $gateway_id, 'provider' => 'payarc', 'capture_mode' => 'server', 'source' => 'webview', 'amount' => $amount, 'currency' => $currency, 'status' => 'pending', 'provider_refs' => array('action' => $ref));
        $GLOBALS['ledger'][$order->get_id()][] = $row;
        $GLOBALS['adopted'][$ref] = $row['id'];
        $GLOBALS['adoptions'][] = array($order->get_id(), $ref, $amount, $currency);
        return $row;
    }
    function patwc_reset_world(): void
    {
        $GLOBALS['orders'] = array(); $GLOBALS['options'] = array(); $GLOBALS['ledger'] = array(); $GLOBALS['adopted'] = array(); $GLOBALS['adoptions'] = array(); $GLOBALS['lookups'] = array();
        $GLOBALS['free_lock_held'] = false; $GLOBALS['filters'] = array(); $GLOBALS['order_ids'] = array(); $GLOBALS['order_queries'] = array(); $GLOBALS['pro_refuses_adoption'] = false; $GLOBALS['pro_processed'] = array(); $GLOBALS['pro_refunds'] = array(); $GLOBALS['cache_cleared'] = array(); $GLOBALS['reads'] = array(); $GLOBALS['clone_orders'] = false;
        WCPOS\WooCommercePOS\PayArcTerminal\Legacy_Adoption::$reader = null;
    }
    function patwc_set_ledger_status(int $order_id, string $row_id, string $status): void
    {
        foreach ($GLOBALS['ledger'][$order_id] as &$row) { if ($row['id'] === $row_id) { $row['status'] = $status; } }
    }
    function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, 'FAILED: ' . $what . "\n"); exit(1); } }

    foreach (array('Settings', 'Logger', 'Utils/Money', 'Utils/PayArcIds', 'PaymentAttempt', 'PaymentLock', 'PaymentReconciler', 'Legacy_Adoption', 'Server/Sale_Guard', 'Services/PayArcRequestException', 'Services/PayArcNotSentException') as $file) {
        require_once PATWC_PLUGIN_DIR . 'includes/' . $file . '.php';
    }
}
