<?php

/**
 * Regression tests for merchant-test-ready PayArc Connect setup.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcConnectionService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/Services/PayArcConnectionService.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $record = array('url' => $url, 'args' => $args);
        $GLOBALS['patwc_http_requests'][] = $record;

        if (isset($GLOBALS['patwc_client_requests']) && is_array($GLOBALS['patwc_client_requests'])) {
            $GLOBALS['patwc_client_requests'][] = $record;
        }

        if (isset($GLOBALS['patwc_http_response_queue']) && is_array($GLOBALS['patwc_http_response_queue']) && count($GLOBALS['patwc_http_response_queue']) > 0) {
            return array_shift($GLOBALS['patwc_http_response_queue']);
        }

        return $GLOBALS['patwc_client_response'] ?? array('response' => array('code' => 500), 'body' => '{}');
    }
}

function patwc_connection_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_connection_assert_missing_secret(array $payload, string $secret, string $message): void
{
    $encoded = json_encode($payload);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode payload for secret check.');
    }

    if ($secret !== '' && strpos($encoded, $secret) !== false) {
        throw new RuntimeException($message . ' Leaked secret: ' . $secret);
    }
}

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'ErrorMessage' => 'Ok',
            'Terminals' => array(
                array(
                    'Terminal' => 'Front Counter A920',
                    'Type' => 'pax_A920',
                    'Is_enabled' => true,
                    'Device_id' => '00000000004221',
                    'Pos_identifier' => '1850528139',
                    'Code' => 'LOGINCODE1',
                ),
            ),
            'BearerTokenInfo' => array(
                'TokenType' => 'Bearer',
                'AccessToken' => 'connect-access-token',
                'ExpiresIn' => 3600,
                'RefreshToken' => 'refresh-token',
            ),
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'data' => array(
                array(
                    'object' => 'TerminalRegistry',
                    'id' => 'XBz5wN5w7MXNy7VJ',
                    'terminal' => 'Front Counter A920',
                    'type' => 'pax_A920',
                    'code' => 'XBz5wN5w7MXNy7VJ',
                    'is_enabled' => true,
                    'device_id' => '00000000004221',
                    'pos_identifier' => '1850528139',
                ),
                array(
                    'object' => 'TerminalRegistry',
                    'id' => 'disabled',
                    'terminal' => 'Disabled terminal',
                    'type' => 'pax_A920',
                    'code' => 'disabled',
                    'is_enabled' => false,
                    'device_id' => '00000000000000',
                    'pos_identifier' => '1850528140',
                ),
            ),
        )),
    ),
);

$stored = array();
$settings = new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
));
$service = new PayArcConnectionService($settings, static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});

$result = $service->connect();

patwc_connection_assert_same('connected', $result['status'], 'Connect should report connected status.');
patwc_connection_assert_same('123456789012', $result['tenant_id'], 'Tenant id should derive from last 12 MID digits.');
patwc_connection_assert_same(1, $result['terminal_count'], 'Only enabled terminals with 10-digit pos identifiers should be counted.');
patwc_connection_assert_same('1850528139', $result['terminals'][0]['terminal_id'], 'Terminal id should come from PayArc pos_identifier.');
patwc_connection_assert_same('Front Counter A920 (pax_A920) ••••••8139', $result['terminals'][0]['label'], 'Terminal label should be merchant-friendly and masked.');
patwc_connection_assert_same('connect-access-token', $stored['connect_access_token'], 'Connect access token should be stored server-side.');
patwc_connection_assert_same('123456789012', $stored['tenant_id'], 'Derived tenant id should be stored.');
patwc_connection_assert_same('1850528139', $stored['default_terminal_id'], 'Default terminal should be the discovered PayArc terminal id.');
patwc_connection_assert_same('1850528139', $stored['terminal_registry'][0]['terminal_id'], 'Normalized terminal registry should be stored.');
patwc_connection_assert_same(2, count($GLOBALS['patwc_http_requests']), 'Connect should call Login and terminal registry.');

$loginRequest = $GLOBALS['patwc_http_requests'][0];
patwc_connection_assert_same('https://testpayarcconnectapi.curvpos.com/Login', $loginRequest['url'], 'Login URL mismatch.');
patwc_connection_assert_same('POST', $loginRequest['args']['method'], 'Login method mismatch.');
patwc_connection_assert_same('Bearer merchant-api-token', $loginRequest['args']['headers']['Authorization'], 'Login should use Merchant Dashboard API bearer token.');
patwc_connection_assert_same(array(
    'Email' => 'merchant@example.com',
    'MID' => '0000123456789012',
    'ClientSecret' => 'client-secret',
    'SecretKey' => 'merchant-api-token',
), json_decode($loginRequest['args']['body'], true), 'Login payload mismatch.');

$registryRequest = $GLOBALS['patwc_http_requests'][1];
patwc_connection_assert_same('https://testapi.payarc.net/v1/terminalregistries', $registryRequest['url'], 'Terminal registry URL mismatch.');
patwc_connection_assert_same('GET', $registryRequest['args']['method'], 'Terminal registry method mismatch.');
patwc_connection_assert_same('Bearer merchant-api-token', $registryRequest['args']['headers']['Authorization'], 'Terminal registry should use Merchant Dashboard API bearer token.');

patwc_connection_assert_missing_secret($result, 'merchant-api-token', 'Connect result should not expose API bearer token.');
patwc_connection_assert_missing_secret($result, 'connect-access-token', 'Connect result should not expose Connect access token.');
patwc_connection_assert_missing_secret($result, 'client-secret', 'Connect result should not expose client secret.');


$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'data' => array(
                array(
                    'terminal' => 'Back Counter A920',
                    'type' => 'pax_A920',
                    'is_enabled' => true,
                    'device_id' => '00000000004222',
                    'pos_identifier' => '1850528141',
                ),
                array(
                    'terminal' => 'Front Counter A920',
                    'type' => 'pax_A920',
                    'is_enabled' => true,
                    'device_id' => '00000000004221',
                    'pos_identifier' => '1850528139',
                ),
            ),
        )),
    ),
);
$stored = array();
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_mid' => '0000123456789012',
    'connect_secret_key' => 'merchant-api-token',
    'default_terminal_id' => '1850528139',
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$refresh = $service->refresh_terminals();
patwc_connection_assert_same('1850528139', $stored['default_terminal_id'], 'Refresh should preserve the existing selected terminal when it is still discovered.');
patwc_connection_assert_same('1850528139', $refresh['default_terminal_id'], 'Refresh response should preserve the existing selected terminal when it is still discovered.');


$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'Terminals' => array(array(
                'Terminal' => 'Login Terminal',
                'Type' => 'pax_A920',
                'Is_enabled' => true,
                'Pos_identifier' => '1850528139',
            )),
            'BearerTokenInfo' => array(
                'AccessToken' => 'connect-access-token',
                'ExpiresIn' => 3600,
            ),
        )),
    ),
    array(
        'response' => array('code' => 401),
        'body' => json_encode(array('error' => 'Unauthenticated token=merchant-api-token secret client-secret')),
    ),
);
$stored = array();
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$result = $service->connect();
patwc_connection_assert_same(true, isset($result['warning']) && is_string($result['warning']), 'Registry failure should return a generic warning.');
patwc_connection_assert_missing_secret($result, 'merchant-api-token', 'Registry warning should not reflect secret-like upstream text.');
patwc_connection_assert_missing_secret($result, 'client-secret', 'Registry warning should not reflect client secret text.');
