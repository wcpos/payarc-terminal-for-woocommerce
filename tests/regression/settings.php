<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Gateway;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return array_key_exists($option, $GLOBALS['patwc_options']) ? $GLOBALS['patwc_options'][$option] : $default;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://merchant.example/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback)
    {
        $GLOBALS['patwc_actions'][] = array(
            'hook' => $hook,
            'callback' => $callback,
        );
    }
}

if (!class_exists('WC_Admin_Settings')) {
    class WC_Admin_Settings
    {
        public static function add_error($error): void
        {
            $GLOBALS['patwc_admin_errors'][] = $error;
        }
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
    }
}

$settingsFile = dirname(__DIR__, 2) . '/includes/Settings.php';
$gatewayFile = dirname(__DIR__, 2) . '/includes/Gateway.php';

if (!is_readable($settingsFile)) {
    throw new RuntimeException('Settings class file is missing.');
}

if (!is_readable($gatewayFile)) {
    throw new RuntimeException('Gateway class file is missing.');
}

require_once $settingsFile;
require_once $gatewayFile;

function patwc_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_assert_missing_secret(array $diagnostics, string $secret): void
{
    $encoded = json_encode($diagnostics);

    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode diagnostics.');
    }

    if (strpos($encoded, $secret) !== false) {
        throw new RuntimeException('Diagnostics leaked secret: ' . $secret);
    }
}

$GLOBALS['patwc_options'] = array();
$settings = new Settings();

patwc_assert_same('test', $settings->mode(), 'Default mode should be test.');
patwc_assert_same('https://testpayarcconnectapi.payarc.net', $settings->connect_base_url(), 'Test Connect base URL mismatch.');
patwc_assert_same('https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback', $settings->webhook_url(), 'Webhook URL mismatch.');

$GLOBALS['patwc_options'] = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_settings' => array(
        'mode' => 'test',
        'api_bearer_token' => 'api-secret-token',
        'callback_bearer_token' => 'callback-secret-token',
        'tenant_id' => '123456789012',
        'default_terminal_id' => '1234567890',
        'tender_type' => 'DEBIT',
        'print_receipt' => '2',
    ),
);

$settings = new Settings();

patwc_assert_same('api-secret-token', $settings->api_bearer_token(), 'API bearer token getter should return raw server-side value.');
patwc_assert_same('callback-secret-token', $settings->callback_bearer_token(), 'Callback bearer token getter should return raw server-side value.');
patwc_assert_same('123456789012', $settings->tenant_id(), 'Tenant id mismatch.');
patwc_assert_same('1234567890', $settings->default_terminal_id(), 'Default terminal id mismatch.');
patwc_assert_same('DEBIT', $settings->tender_type(), 'Tender type mismatch.');
patwc_assert_same(2, $settings->print_receipt(), 'Print receipt mismatch.');

$diagnostics = $settings->diagnostics();
patwc_assert_missing_secret($diagnostics, 'api-secret-token');
patwc_assert_missing_secret($diagnostics, 'callback-secret-token');

if (array_key_exists('api_bearer_token', $diagnostics) || array_key_exists('callback_bearer_token', $diagnostics)) {
    throw new RuntimeException('Diagnostics must not include token keys.');
}

$validationErrors = Gateway::validate_settings(array(
    'enabled' => 'yes',
    'tenant_id' => 'not-12-digits',
    'default_terminal_id' => '123',
    'tender_type' => 'CASH',
    'print_receipt' => '9',
));

patwc_assert_same(array(
    'Tenant ID must be exactly 12 digits when the gateway is enabled.',
    'Default terminal ID must be exactly 10 digits when the gateway is enabled.',
    'Tender type must be CREDIT or DEBIT.',
    'Print receipt must be one of 0, 1, 2, or 3.',
), $validationErrors, 'Gateway validation errors mismatch.');

patwc_assert_same(array(), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'tenant_id' => '123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
)), 'Valid gateway settings should not return errors.');

patwc_assert_same(array(
    'Production mode cannot be saved until the production URL/token source is verified.',
), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'mode' => 'production',
    'tenant_id' => '123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
)), 'Production mode should be rejected until verified.');

$GLOBALS['patwc_actions'] = array();
$gateway = new Gateway();
$expectedHook = 'woocommerce_update_options_payment_gateways_' . Settings::GATEWAY_ID;
$registeredSettingsSaveHook = false;

foreach ($GLOBALS['patwc_actions'] as $action) {
    if ($action['hook'] !== $expectedHook || !is_array($action['callback'])) {
        continue;
    }

    if ($action['callback'][0] === $gateway && $action['callback'][1] === 'process_admin_options') {
        $registeredSettingsSaveHook = true;
    }
}

patwc_assert_same(true, $registeredSettingsSaveHook, 'Gateway should register process_admin_options on the WooCommerce settings-save hook.');

$_POST = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_enabled' => 'yes',
    'woocommerce_' . Settings::GATEWAY_ID . '_mode' => 'production',
    'woocommerce_' . Settings::GATEWAY_ID . '_tenant_id' => '123456789012',
    'woocommerce_' . Settings::GATEWAY_ID . '_default_terminal_id' => '1234567890',
    'woocommerce_' . Settings::GATEWAY_ID . '_tender_type' => 'CREDIT',
    'woocommerce_' . Settings::GATEWAY_ID . '_print_receipt' => '0',
);

patwc_assert_same(false, $gateway->process_admin_options(), 'Gateway admin save should reject posted production mode.');
$_POST = array();

patwc_assert_same(true, is_subclass_of(Gateway::class, 'WC_Payment_Gateway'), 'Gateway must extend the WooCommerce payment gateway base class.');
$gatewaySource = file_get_contents($gatewayFile);
if (!is_string($gatewaySource)) {
    throw new RuntimeException('Unable to read Gateway source.');
}
patwc_assert_same(false, strpos($gatewaySource, "} else {\n    class Gateway"), 'Gateway source must not define a non-WooCommerce fallback class.');

$apiSecretHtml = $gateway->generate_patwc_secret_html('api_bearer_token', $gateway->form_fields['api_bearer_token']);
$callbackSecretHtml = $gateway->generate_patwc_secret_html('callback_bearer_token', $gateway->form_fields['callback_bearer_token']);
$secretRenderData = json_encode($gateway->form_fields) . $apiSecretHtml . $callbackSecretHtml;
if (!is_string($secretRenderData)) {
    throw new RuntimeException('Unable to encode gateway form fields.');
}

if (strpos($secretRenderData, 'api-secret-token') !== false || strpos($secretRenderData, 'callback-secret-token') !== false) {
    throw new RuntimeException('Secret field rendering leaked a saved token.');
}

patwc_assert_same('patwc_secret', $gateway->form_fields['api_bearer_token']['type'], 'API token field should use the custom secret type.');
patwc_assert_same('patwc_secret', $gateway->form_fields['callback_bearer_token']['type'], 'Callback token field should use the custom secret type.');
patwc_assert_same('api-secret-token', $gateway->validate_patwc_secret_field('api_bearer_token', ''), 'Blank API token save should preserve the existing secret.');
patwc_assert_same('replacement-api-token', $gateway->validate_patwc_secret_field('api_bearer_token', 'replacement-api-token'), 'Non-empty API token save should replace the existing secret.');

$GLOBALS['patwc_admin_errors'] = array();
patwc_assert_same('callback-secret-token', $gateway->validate_patwc_secret_field('callback_bearer_token', "bad
secret"), 'Invalid callback token save should preserve the existing secret.');
patwc_assert_same(array('Callback bearer token cannot contain control characters or newlines.'), $GLOBALS['patwc_admin_errors'], 'Invalid callback token save should report a clear error.');
