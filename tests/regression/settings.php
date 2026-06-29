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
