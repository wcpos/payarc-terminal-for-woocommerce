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


if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        $GLOBALS['patwc_options'][$option] = $value;
        return true;
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
            $option = 'woocommerce_' . $this->id . '_settings';
            $data = $this->get_post_data();
            $settings = array();
            $prefix = 'woocommerce_' . $this->id . '_';

            foreach ($this->form_fields as $key => $field) {
                $postedKey = $prefix . $key;
                if ($key === 'enabled' && !array_key_exists($postedKey, $data)) {
                    $settings[$key] = 'no';
                    continue;
                }

                if (!array_key_exists($postedKey, $data) || !is_scalar($data[$postedKey])) {
                    continue;
                }

                $value = trim((string) $data[$postedKey]);
                $validator = 'validate_' . $field['type'] . '_field';
                if (is_array($field) && isset($field['type']) && method_exists($this, $validator)) {
                    $value = $this->{$validator}($key, $value);
                }

                $settings[$key] = $value;
            }

            update_option($option, $settings);
            $this->settings = $settings;

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



$merchantSettings = new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
    'connect_access_token' => 'connect-access-token',
    'terminal_registry' => array(array(
        'terminal_id' => '1850528139',
        'label' => 'Front Counter A920 (pax_A920) ••••••8139',
        'enabled' => true,
    )),
));
patwc_assert_same('merchant@example.com', $merchantSettings->connect_email(), 'Connect email getter mismatch.');
patwc_assert_same('0000123456789012', $merchantSettings->connect_mid(), 'Connect MID getter mismatch.');
patwc_assert_same('merchant-api-token', $merchantSettings->connect_secret_key(), 'Merchant API token getter mismatch.');
patwc_assert_same('connect-access-token', $merchantSettings->connect_access_token(), 'Connect access token getter mismatch.');
patwc_assert_same('123456789012', $merchantSettings->tenant_id(), 'Tenant id should derive from last 12 MID digits.');
patwc_assert_same('1850528139', $merchantSettings->default_terminal_id(), 'Default terminal id should derive from discovered registry when no manual default is stored.');
patwc_assert_same(array('1850528139' => 'Front Counter A920 (pax_A920) ••••••8139'), $merchantSettings->terminal_registry_options(), 'Terminal registry options mismatch.');

$staleTenantSettings = new Settings(array(
    'tenant_id' => '999999999999',
    'connect_mid' => '0000123456789012',
));
patwc_assert_same('123456789012', $staleTenantSettings->tenant_id(), 'Connect MID-derived tenant should override stale hidden tenant_id.');

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
    'PayArc MID must contain at least 12 digits so the tenant ID can be derived when the gateway is enabled.',
    'Select a discovered PayArc terminal before enabling the gateway.',
    'Tender type must be CREDIT or DEBIT.',
    'Print receipt must be one of 0, 1, 2, or 3.',
), $validationErrors, 'Gateway validation errors mismatch.');

patwc_assert_same(array(), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
)), 'Valid gateway settings should not return errors.');

patwc_assert_same(array(
    'Production mode cannot be saved until PayArc production Connect URLs are verified.',
), Gateway::validate_settings(array(
    'enabled' => 'yes',
    'mode' => 'production',
    'connect_mid' => '0000123456789012',
    'default_terminal_id' => '1234567890',
    'tender_type' => 'CREDIT',
    'print_receipt' => '0',
)), 'Production mode should be rejected until verified.');

$GLOBALS['patwc_actions'] = array();
$gateway = new Gateway();

patwc_assert_same('text', $gateway->form_fields['connect_email']['type'], 'Connect email field should be visible.');
patwc_assert_same('text', $gateway->form_fields['connect_mid']['type'], 'Connect MID field should be visible.');
patwc_assert_same('patwc_secret', $gateway->form_fields['connect_client_secret']['type'], 'ClientSecret field should use the custom secret type.');
patwc_assert_same('patwc_secret', $gateway->form_fields['connect_secret_key']['type'], 'SecretKey/API bearer field should use the custom secret type.');
patwc_assert_same('select', $gateway->form_fields['default_terminal_id']['type'], 'Default terminal field should be a discovered terminal select.');
patwc_assert_same('patwc_connection', $gateway->form_fields['connection']['type'], 'Gateway should render a PayArc Connect control panel.');

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
    'woocommerce_' . Settings::GATEWAY_ID . '_connect_mid' => '0000123456789012',
    'woocommerce_' . Settings::GATEWAY_ID . '_default_terminal_id' => '1234567890',
    'woocommerce_' . Settings::GATEWAY_ID . '_tender_type' => 'CREDIT',
    'woocommerce_' . Settings::GATEWAY_ID . '_print_receipt' => '0',
);

patwc_assert_same(false, $gateway->process_admin_options(), 'Gateway admin save should reject posted production mode.');
$_POST = array();

$GLOBALS['patwc_options'] = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_settings' => array(
        'enabled' => 'no',
        'connect_email' => 'merchant@example.com',
        'connect_mid' => '0000123456789012',
        'connect_secret_key' => 'merchant-api-token',
        'connect_client_secret' => 'client-secret',
        'connect_access_token' => 'connect-access-token',
        'connect_token_expires_at' => '1893456000',
        'terminal_registry' => array(array(
            'terminal_id' => '1850528139',
            'label' => 'Front Counter A920 (pax_A920) ••••••8139',
            'enabled' => true,
        )),
        'tenant_id' => '123456789012',
        'default_terminal_id' => '1850528139',
        'callback_bearer_token' => 'callback-secret-token',
    ),
);
$gateway = new Gateway();
$_POST = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_enabled' => 'yes',
    'woocommerce_' . Settings::GATEWAY_ID . '_title' => 'PayArc Terminal',
    'woocommerce_' . Settings::GATEWAY_ID . '_description' => 'Pay in person at a PayArc PAX terminal.',
    'woocommerce_' . Settings::GATEWAY_ID . '_mode' => 'test',
    'woocommerce_' . Settings::GATEWAY_ID . '_connect_email' => 'merchant@example.com',
    'woocommerce_' . Settings::GATEWAY_ID . '_connect_mid' => '0000123456789012',
    'woocommerce_' . Settings::GATEWAY_ID . '_connect_client_secret' => '',
    'woocommerce_' . Settings::GATEWAY_ID . '_connect_secret_key' => '',
    'woocommerce_' . Settings::GATEWAY_ID . '_callback_bearer_token' => '',
    'woocommerce_' . Settings::GATEWAY_ID . '_connection' => '',
    'woocommerce_' . Settings::GATEWAY_ID . '_default_terminal_id' => '1850528139',
    'woocommerce_' . Settings::GATEWAY_ID . '_tender_type' => 'CREDIT',
    'woocommerce_' . Settings::GATEWAY_ID . '_print_receipt' => '0',
    'woocommerce_' . Settings::GATEWAY_ID . '_webhook_url' => 'https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
    'woocommerce_' . Settings::GATEWAY_ID . '_diagnostics' => '',
);
patwc_assert_same(true, $gateway->process_admin_options(), 'Gateway admin save should accept connected merchant settings.');
$savedAfterConnect = $GLOBALS['patwc_options']['woocommerce_' . Settings::GATEWAY_ID . '_settings'];
patwc_assert_same('connect-access-token', $savedAfterConnect['connect_access_token'], 'Connection state should survive WooCommerce save.');
patwc_assert_same('1893456000', $savedAfterConnect['connect_token_expires_at'], 'Token expiry should survive WooCommerce save.');
patwc_assert_same(array(array(
    'terminal_id' => '1850528139',
    'label' => 'Front Counter A920 (pax_A920) ••••••8139',
    'enabled' => true,
)), $savedAfterConnect['terminal_registry'], 'Terminal registry should survive WooCommerce save.');
$_POST = array();

patwc_assert_same(true, is_subclass_of(Gateway::class, 'WC_Payment_Gateway'), 'Gateway must extend the WooCommerce payment gateway base class.');
$gatewaySource = file_get_contents($gatewayFile);
if (!is_string($gatewaySource)) {
    throw new RuntimeException('Unable to read Gateway source.');
}
patwc_assert_same(false, strpos($gatewaySource, "} else {\n    class Gateway"), 'Gateway source must not define a non-WooCommerce fallback class.');

$GLOBALS['patwc_options'] = array(
    'woocommerce_' . Settings::GATEWAY_ID . '_settings' => array(
        'mode' => 'test',
        'api_bearer_token' => 'api-secret-token',
        'callback_bearer_token' => 'callback-secret-token',
        'connect_mid' => '0000123456789012',
        'default_terminal_id' => '1234567890',
    ),
);
$gateway = new Gateway();

$clientSecretHtml = $gateway->generate_patwc_secret_html('connect_client_secret', $gateway->form_fields['connect_client_secret']);
$apiSecretHtml = $gateway->generate_patwc_secret_html('connect_secret_key', $gateway->form_fields['connect_secret_key']);
$callbackSecretHtml = $gateway->generate_patwc_secret_html('callback_bearer_token', $gateway->form_fields['callback_bearer_token']);
$secretRenderData = json_encode($gateway->form_fields) . $clientSecretHtml . $apiSecretHtml . $callbackSecretHtml;
if (!is_string($secretRenderData)) {
    throw new RuntimeException('Unable to encode gateway form fields.');
}

if (strpos($secretRenderData, 'api-secret-token') !== false || strpos($secretRenderData, 'callback-secret-token') !== false) {
    throw new RuntimeException('Secret field rendering leaked a saved token.');
}

patwc_assert_same('patwc_secret', $gateway->form_fields['connect_secret_key']['type'], 'SecretKey/API bearer field should use the custom secret type.');
patwc_assert_same('patwc_secret', $gateway->form_fields['callback_bearer_token']['type'], 'Callback token field should use the custom secret type.');
patwc_assert_same('api-secret-token', $gateway->validate_patwc_secret_field('connect_secret_key', ''), 'Blank SecretKey save should preserve the existing secret.');
patwc_assert_same('replacement-api-token', $gateway->validate_patwc_secret_field('connect_secret_key', 'replacement-api-token'), 'Non-empty SecretKey save should replace the existing secret.');

$GLOBALS['patwc_admin_errors'] = array();
patwc_assert_same('callback-secret-token', $gateway->validate_patwc_secret_field('callback_bearer_token', "bad
secret"), 'Invalid callback token save should preserve the existing secret.');
patwc_assert_same(array('Callback bearer token cannot contain control characters or newlines.'), $GLOBALS['patwc_admin_errors'], 'Invalid callback token save should report a clear error.');
