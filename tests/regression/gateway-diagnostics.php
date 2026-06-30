<?php

/**
 * Regression tests for PayArc gateway admin diagnostics and local validation.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        if (array_key_exists($option, $GLOBALS['patwc_gateway_diagnostics_options'] ?? array())) {
            return $GLOBALS['patwc_gateway_diagnostics_options'][$option];
        }

        if (array_key_exists($option, $GLOBALS['patwc_gateway_options'] ?? array())) {
            return $GLOBALS['patwc_gateway_options'][$option];
        }

        if (array_key_exists($option, $GLOBALS['patwc_options'] ?? array())) {
            return $GLOBALS['patwc_options'][$option];
        }

        return $default;
    }
}


if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'nonce-for-' . (string) $action;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['patwc_gateway_diagnostics_actions'][] = array(
            'hook' => (string) $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );

        return true;
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
    $root . '/includes/Gateway.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

function patwc_gateway_diagnostics_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_gateway_diagnostics_assert_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing: ' . $needle . '. HTML: ' . $haystack);
    }
}

function patwc_gateway_diagnostics_assert_not_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message . ' Leaked: ' . $needle . '. HTML: ' . $haystack);
    }
}

$GLOBALS['patwc_gateway_diagnostics_options'] = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_settings' => array(
        'mode' => 'test',
        'connect_email' => 'merchant@example.com',
        'connect_mid' => '0000123456789012',
        'connect_secret_key' => 'api-secret-token-should-not-render',
        'connect_access_token' => 'connect-access-token-should-not-render',
        'callback_bearer_token' => 'callback-secret-token-should-not-render',
        'tenant_id' => '123456789012',
        'default_terminal_id' => '9876543210',
        'terminal_registry' => array(array('terminal_id' => '9876543210', 'label' => 'Front Counter ••••••3210', 'enabled' => true)),
        'tender_type' => 'CREDIT',
        'print_receipt' => '1',
    ),
    'patwc_last_callback_timestamp' => '2026-06-30T12:34:56Z',
    'patwc_last_payarc_error' => array(
        'code' => 'INVALID_TERMINAL',
        'message' => 'Terminal rejected request with bearer api-secret-token-should-not-render',
    ),
);

$gateway = new Gateway();

if (!method_exists($gateway, 'generate_patwc_diagnostics_html')) {
    throw new RuntimeException('Gateway should render a PayArc diagnostics admin field.');
}

if (!method_exists(Gateway::class, 'local_settings_diagnostics')) {
    throw new RuntimeException('Gateway should expose local settings diagnostics.');
}

$html = $gateway->generate_patwc_diagnostics_html('diagnostics', array('title' => 'Diagnostics'));
patwc_gateway_diagnostics_assert_contains('PayArc diagnostics', $html, 'Diagnostics table should have an accessible heading.');
patwc_gateway_diagnostics_assert_contains('Mode', $html, 'Diagnostics table should show mode.');
patwc_gateway_diagnostics_assert_contains('https://testpayarcconnectapi.curvpos.com', $html, 'Diagnostics table should show Connect Login URL.');
patwc_gateway_diagnostics_assert_contains('https://testpayarcconnectapi.payarc.net', $html, 'Diagnostics table should show Connect V3 URL.');
patwc_gateway_diagnostics_assert_contains('https://testapi.payarc.net', $html, 'Diagnostics table should show Merchant API URL.');
patwc_gateway_diagnostics_assert_contains('••••••••9012', $html, 'Diagnostics table should mask tenant id except last four.');
patwc_gateway_diagnostics_assert_contains('••••••3210', $html, 'Diagnostics table should mask terminal id except last four.');
patwc_gateway_diagnostics_assert_contains('admin-ajax.php?action=patwc_payarc_callback', $html, 'Diagnostics table should show webhook URL.');
patwc_gateway_diagnostics_assert_contains('2026-06-30T12:34:56Z', $html, 'Diagnostics table should show last callback timestamp.');
patwc_gateway_diagnostics_assert_contains('INVALID_TERMINAL', $html, 'Diagnostics table should show PayArc error code.');
patwc_gateway_diagnostics_assert_contains('[REDACTED]', $html, 'Diagnostics table should redact token-looking error fragments.');
patwc_gateway_diagnostics_assert_contains('data-action="patwc_validate_settings"', $html, 'Validate Settings control should use the existing AJAX action.');
patwc_gateway_diagnostics_assert_contains('nonce-for-patwc_validate_settings', $html, 'Validate Settings control should include the validate-settings nonce.');
patwc_gateway_diagnostics_assert_not_contains('api-secret-token-should-not-render', $html, 'Diagnostics HTML must not leak API token.');
patwc_gateway_diagnostics_assert_not_contains('connect-access-token-should-not-render', $html, 'Diagnostics HTML must not leak Connect AccessToken.');
patwc_gateway_diagnostics_assert_not_contains('callback-secret-token-should-not-render', $html, 'Diagnostics HTML must not leak callback token.');
patwc_gateway_diagnostics_assert_not_contains('123456789012', $html, 'Diagnostics HTML must not render raw tenant id.');
patwc_gateway_diagnostics_assert_not_contains('9876543210', $html, 'Diagnostics HTML must not render raw terminal id.');

$diagnostics = Gateway::local_settings_diagnostics(array(
    'connect_secret_key_configured' => false,
    'connect_access_token_configured' => false,
    'callback_bearer_token_configured' => false,
    'connect_mid' => 'bad-mid',
    'default_terminal_id' => '123',
    'webhook_url' => 'http://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
    'print_receipt' => '9',
    'tender_type' => 'CASH',
));

patwc_gateway_diagnostics_assert_same('error', $diagnostics['status'], 'Invalid local diagnostics should report error status.');
patwc_gateway_diagnostics_assert_same(array(
    'PayArc SecretKey/API bearer token must be configured.',
    'Click Connect using these credentials to fetch a Connect AccessToken.',
    'Callback bearer token must be configured.',
    'PayArc MID must contain at least 12 digits.',
    'Connect PayArc and select a discovered terminal.',
    'Callback URL must be HTTPS.',
    'Print receipt must be one of 0, 1, 2, or 3.',
    'Tender type must be CREDIT or DEBIT.',
), $diagnostics['errors'], 'Local validation errors should cover required Connect token, callback, MID, terminal, HTTPS, receipt, and tender checks.');

$encodedDiagnostics = json_encode($diagnostics);
if (!is_string($encodedDiagnostics)) {
    throw new RuntimeException('Unable to encode diagnostics result.');
}

foreach (array('api-secret-token-should-not-render', 'connect-access-token-should-not-render', 'callback-secret-token-should-not-render', 'bad-mid') as $secretOrRawValue) {
    if (strpos($encodedDiagnostics, $secretOrRawValue) !== false) {
        throw new RuntimeException('Local diagnostics should not echo raw secret or identifier values: ' . $secretOrRawValue);
    }
}

$validDiagnostics = Gateway::local_settings_diagnostics(array(
    'connect_secret_key_configured' => true,
    'connect_access_token_configured' => true,
    'callback_bearer_token_configured' => true,
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '9876543210',
    'webhook_url' => 'https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
    'print_receipt' => '0',
    'tender_type' => 'DEBIT',
));
patwc_gateway_diagnostics_assert_same('ok', $validDiagnostics['status'], 'Valid local diagnostics should report ok status.');
patwc_gateway_diagnostics_assert_same(array(), $validDiagnostics['errors'], 'Valid local diagnostics should have no errors.');

unset($GLOBALS['patwc_gateway_diagnostics_options']);
