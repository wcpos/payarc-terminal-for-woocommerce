<?php

/**
 * Regression tests for the PayArc order-pay gateway UI.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\AjaxHandler;

if (!defined('PATWC_VERSION')) {
    define('PATWC_VERSION', '0.1.0-test');
}

if (!defined('PATWC_PLUGIN_URL')) {
    define('PATWC_PLUGIN_URL', 'https://merchant.example/wp-content/plugins/payarc-terminal-for-woocommerce/');
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        if (array_key_exists($option, $GLOBALS['patwc_gateway_options'] ?? array())) {
            return $GLOBALS['patwc_gateway_options'][$option];
        }

        if (array_key_exists($option, $GLOBALS['patwc_options'] ?? array())) {
            return $GLOBALS['patwc_options'][$option];
        }

        return $default;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback)
    {
        $GLOBALS['patwc_gateway_actions'][] = array('hook' => $hook, 'callback' => $callback);
        return true;
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        $key = (int) $order_id;
        return $GLOBALS['patwc_gateway_orders'][$key] ?? false;
    }
}

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $notice_type = 'success')
    {
        $GLOBALS['patwc_gateway_notices'][] = array('message' => (string) $message, 'type' => (string) $notice_type);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'nonce-for-' . (string) $action;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth')
    {
        return 'patwc-gateway-ui-salt-' . (string) $scheme;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        $key = (string) $capability;
        if (!empty($args)) {
            $key .= ':' . implode(':', array_map('strval', $args));
        }

        return !empty($GLOBALS['patwc_gateway_caps'][$key]) || !empty($GLOBALS['patwc_gateway_caps'][(string) $capability]);
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var($query_var, $default = '')
    {
        return array_key_exists((string) $query_var, $GLOBALS['patwc_gateway_query_vars'])
            ? $GLOBALS['patwc_gateway_query_vars'][(string) $query_var]
            : $default;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false)
    {
        $GLOBALS['patwc_gateway_scripts'][(string) $handle] = array(
            'src' => (string) $src,
            'deps' => $deps,
            'ver' => $ver,
            'in_footer' => $in_footer,
        );
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false)
    {
        $GLOBALS['patwc_gateway_styles'][(string) $handle] = array(
            'src' => (string) $src,
            'deps' => $deps,
            'ver' => $ver,
        );
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        $GLOBALS['patwc_gateway_localized'][(string) $handle] = array(
            'object_name' => (string) $object_name,
            'data' => $l10n,
        );
    }
}

if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        /** @var string */
        public $id = '';
        /** @var string */
        public $method_title = '';
        /** @var string */
        public $method_description = '';
        /** @var bool */
        public $has_fields = false;
        /** @var string[] */
        public $supports = array();
        /** @var string */
        public $title = '';
        /** @var string */
        public $description = '';
        /** @var string */
        public $enabled = 'no';
        /** @var array<string, mixed> */
        public $form_fields = array();
        /** @var array<string, mixed> */
        public $settings = array();

        public function init_settings(): void
        {
            $option = 'woocommerce_' . $this->id . '_settings';
            $settings = function_exists('get_option') ? get_option($option, array()) : array();
            $this->settings = is_array($settings) ? $settings : array();
        }

        public function get_option($key, $default = null)
        {
            return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
        }

        public function get_field_key($key): string
        {
            return 'woocommerce_' . $this->id . '_' . $key;
        }

        public function get_post_data(): array
        {
            return $_POST;
        }

        public function process_admin_options()
        {
            return true;
        }

        public function get_return_url($order = null): string
        {
            return is_object($order) && method_exists($order, 'get_return_url')
                ? $order->get_return_url()
                : 'https://merchant.example/checkout/order-received/';
        }
    }
}

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/AjaxHandler.php',
    $root . '/includes/Gateway.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

class PatwcGatewayUiOrder
{
    /** @var int */
    private $id;
    /** @var bool */
    private $paid;
    /** @var string */
    private $key;

    public function __construct(int $id, bool $paid, string $key)
    {
        $this->id = $id;
        $this->paid = $paid;
        $this->key = $key;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function is_paid(): bool
    {
        return $this->paid;
    }

    public function get_order_key(): string
    {
        return $this->key;
    }

    public function get_checkout_payment_url($on_checkout = false): string
    {
        return 'https://merchant.example/checkout/order-pay/' . $this->id . '/?pay_for_order=' . ($on_checkout ? 'true' : 'false') . '&key=' . $this->key;
    }

    public function get_return_url(): string
    {
        return 'https://merchant.example/checkout/order-received/' . $this->id . '/?key=' . $this->key;
    }
}

function patwc_gateway_ui_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_gateway_ui_assert_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing: ' . $needle . '. HTML: ' . $haystack);
    }
}

function patwc_gateway_ui_reset(): void
{
    $GLOBALS['patwc_gateway_options'] = array();
    $GLOBALS['patwc_gateway_orders'] = array(
        2001 => new PatwcGatewayUiOrder(2001, false, 'wc_order_unpaid'),
        2002 => new PatwcGatewayUiOrder(2002, true, 'wc_order_paid'),
    );
    $GLOBALS['patwc_gateway_caps'] = array();
    $GLOBALS['patwc_ajax_caps'] = array();
    $GLOBALS['patwc_gateway_notices'] = array();
    $GLOBALS['patwc_gateway_query_vars'] = array('order-pay' => 2001, 'key' => 'wc_order_unpaid');
    $GLOBALS['patwc_gateway_scripts'] = array();
    $GLOBALS['patwc_gateway_styles'] = array();
    $GLOBALS['patwc_gateway_localized'] = array();
    $_GET = array();
}

/**
 * @param array<string, mixed> $queryVars
 * @param array<string, mixed> $get
 * @param array<string, bool> $caps
 * @return array<string, mixed>|null
 */
function patwc_gateway_ui_render_payment_data(array $queryVars, array $get = array(), array $caps = array()): ?array
{
    patwc_gateway_ui_reset();
    $GLOBALS['patwc_gateway_query_vars'] = $queryVars;
    $GLOBALS['patwc_gateway_caps'] = $caps;
    $GLOBALS['patwc_ajax_caps'] = $caps;
    $_GET = $get;

    $gateway = new Gateway();
    ob_start();
    $gateway->payment_fields();
    ob_get_clean();

    $localized = $GLOBALS['patwc_gateway_localized']['patwc-payment'] ?? null;

    return is_array($localized) && isset($localized['data']) && is_array($localized['data']) ? $localized['data'] : null;
}

/**
 * @param array<string, mixed>|null $data
 */
function patwc_gateway_ui_order_token(?array $data): string
{
    if ($data === null || !isset($data['orderToken']) || !is_scalar($data['orderToken'])) {
        return '';
    }

    return (string) $data['orderToken'];
}

patwc_gateway_ui_reset();
$gateway = new Gateway();

if (!method_exists($gateway, 'process_payment')) {
    throw new RuntimeException('Gateway should implement process_payment().');
}

if (!method_exists($gateway, 'payment_fields')) {
    throw new RuntimeException('Gateway should implement payment_fields().');
}

$connectionHtml = $gateway->generate_patwc_connection_html('connection', $gateway->form_fields['connection']);
patwc_gateway_ui_assert_contains('Connect using these credentials', $connectionHtml, 'Connection settings should make the Connect button action explicit.');
patwc_gateway_ui_assert_contains('This does not fetch your PayArc credentials', $connectionHtml, 'Connection settings should say credentials must be entered before connecting.');
patwc_gateway_ui_assert_contains('MID means merchant ID, not terminal ID', $connectionHtml, 'Connection settings should clarify MID vs terminal id.');
patwc_gateway_ui_assert_contains('fetch and store the Connect AccessToken', $connectionHtml, 'Connection settings should explain what the Connect button actually does.');

$unpaidResult = $gateway->process_payment(2001);
patwc_gateway_ui_assert_same(array(
    'result' => 'success',
    'redirect' => 'https://merchant.example/checkout/order-pay/2001/?pay_for_order=true&key=wc_order_unpaid',
), $unpaidResult, 'process_payment should redirect unpaid orders to the order-pay URL.');

$paidResult = $gateway->process_payment(2002);
patwc_gateway_ui_assert_same(array(
    'result' => 'success',
    'redirect' => 'https://merchant.example/checkout/order-received/2002/?key=wc_order_paid',
), $paidResult, 'process_payment should return success for already-paid orders.');

$missingResult = $gateway->process_payment(9999);
patwc_gateway_ui_assert_same(array('result' => 'failure'), $missingResult, 'process_payment should fail safely when the order is missing.');
patwc_gateway_ui_assert_same(array(array('message' => 'Unable to find the order for this payment.', 'type' => 'error')), $GLOBALS['patwc_gateway_notices'], 'Missing orders should add a safe WooCommerce notice.');

ob_start();
$gateway->payment_fields();
$html = (string) ob_get_clean();

patwc_gateway_ui_assert_contains('data-patwc-order-id="2001"', $html, 'payment_fields should render the order id.');
patwc_gateway_ui_assert_contains('id="patwc-payment-status"', $html, 'payment_fields should render a status region.');
patwc_gateway_ui_assert_contains('role="status"', $html, 'payment_fields status should be announced accessibly.');
patwc_gateway_ui_assert_contains('id="patwc-start-payment"', $html, 'payment_fields should render a start button.');
patwc_gateway_ui_assert_contains('id="patwc-cancel-payment"', $html, 'payment_fields should render a cancel button.');
patwc_gateway_ui_assert_contains('id="patwc-payment-log"', $html, 'payment_fields should render a log container.');

if (!isset($GLOBALS['patwc_gateway_scripts']['patwc-payment'])) {
    throw new RuntimeException('payment_fields should enqueue the payment script.');
}

if (!isset($GLOBALS['patwc_gateway_styles']['patwc-payment'])) {
    throw new RuntimeException('payment_fields should enqueue the payment stylesheet.');
}

$localized = $GLOBALS['patwc_gateway_localized']['patwc-payment'] ?? null;
if (!is_array($localized)) {
    throw new RuntimeException('payment_fields should localize payment script data.');
}

patwc_gateway_ui_assert_same('patwcPaymentData', $localized['object_name'], 'Localized object name mismatch.');
$data = $localized['data'];
patwc_gateway_ui_assert_same('admin-ajax.php', $data['ajaxUrl'] ?? null, 'Localized ajax URL mismatch.');
patwc_gateway_ui_assert_same('nonce-for-patwc_payment', $data['nonce'] ?? null, 'Localized payment nonce mismatch.');
patwc_gateway_ui_assert_same(2001, $data['orderId'] ?? null, 'Localized order id mismatch.');
patwc_gateway_ui_assert_same(AjaxHandler::order_token_for($GLOBALS['patwc_gateway_orders'][2001]), $data['orderToken'] ?? null, 'Localized order token mismatch.');

$strings = $data['strings'] ?? null;
if (!is_array($strings)) {
    throw new RuntimeException('Localized data should include user-facing strings.');
}

foreach (array('ready', 'starting', 'waiting', 'approved', 'retry', 'canceling', 'timeout', 'error') as $key) {
    if (!isset($strings[$key]) || !is_string($strings[$key]) || $strings[$key] === '') {
        throw new RuntimeException('Localized strings should include non-empty key: ' . $key);
    }
}

$missingKeyData = patwc_gateway_ui_render_payment_data(array('order-pay' => 2001));
patwc_gateway_ui_assert_same('', patwc_gateway_ui_order_token($missingKeyData), 'Guest order-pay render without key must not localize a usable order token.');

$wrongKeyData = patwc_gateway_ui_render_payment_data(array('order-pay' => 2001, 'key' => 'wrong-key'));
patwc_gateway_ui_assert_same('', patwc_gateway_ui_order_token($wrongKeyData), 'Guest order-pay render with wrong key must not localize a usable order token.');

$orderIdFallbackData = patwc_gateway_ui_render_payment_data(array(), array('order_id' => 2001));
patwc_gateway_ui_assert_same('', patwc_gateway_ui_order_token($orderIdFallbackData), 'Guest order_id fallback must not localize a usable order token.');

$privilegedData = patwc_gateway_ui_render_payment_data(array('order-pay' => 2001), array(), array('edit_shop_order:2001' => true));
patwc_gateway_ui_assert_same(AjaxHandler::order_token_for($GLOBALS['patwc_gateway_orders'][2001]), patwc_gateway_ui_order_token($privilegedData), 'Privileged users may localize an order token without an order key.');
