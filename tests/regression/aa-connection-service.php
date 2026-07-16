<?php

/**
 * Regression tests for merchant-test-ready PayArc Connect setup.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcConnectionService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/Logger.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/Services/PayArcConnectionService.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger()
    {
        return new class {
            public function info($message, array $context = array()): void
            {
                $this->capture('info', $message, $context);
            }

            public function warning($message, array $context = array()): void
            {
                $this->capture('warning', $message, $context);
            }

            public function error($message, array $context = array()): void
            {
                $this->capture('error', $message, $context);
            }

            private function capture(string $level, $message, array $context): void
            {
                $entry = array('level' => $level, 'message' => $message, 'context' => $context);

                if (isset($GLOBALS['captured']) && is_array($GLOBALS['captured'])) {
                    $GLOBALS['captured'][] = $entry;
                }

                if (isset($GLOBALS['patwc_captured_logs']) && is_array($GLOBALS['patwc_captured_logs'])) {
                    $GLOBALS['patwc_captured_logs'][] = $entry;
                }
            }
        };
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        foreach (array('patwc_options', 'patwc_gateway_options', 'patwc_gateway_diagnostics_options', 'patwc_payment_service_options', 'patwc_payment_attempt_options') as $store) {
            if (isset($GLOBALS[$store]) && is_array($GLOBALS[$store]) && array_key_exists($option, $GLOBALS[$store])) {
                return $GLOBALS[$store][$option];
            }
        }

        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        $GLOBALS['patwc_options'][$option] = $value;
        return true;
    }
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
            $response = array_shift($GLOBALS['patwc_http_response_queue']);
            if (isset($GLOBALS['patwc_after_http_request']) && is_callable($GLOBALS['patwc_after_http_request'])) {
                call_user_func($GLOBALS['patwc_after_http_request'], $record);
            }

            return $response;
        }

        if (isset($GLOBALS['patwc_after_http_request']) && is_callable($GLOBALS['patwc_after_http_request'])) {
            call_user_func($GLOBALS['patwc_after_http_request'], $record);
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

function patwc_connection_assert_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing: ' . $needle . '. Haystack: ' . $haystack);
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

class PatwcStructuredAuthFailureConnectionService extends PayArcConnectionService
{
    /** @var int */
    public $login_calls = 0;

    public function login(?Settings $settings = null): array
    {
        $this->login_calls++;

        if ($this->login_calls === 1) {
            throw new RuntimeException('PayArc Login authentication failed; trace id trace-structured-auth.', 401);
        }

        return array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-test-token-structured'),
        );
    }
}

$GLOBALS['patwc_options'] = array();
$GLOBALS['patwc_captured_logs'] = array();
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
                array(
                    'object' => 'TerminalRegistry',
                    'id' => 'alphanumeric',
                    'terminal' => 'Alphanumeric terminal',
                    'type' => 'pax_A920',
                    'code' => 'alphanumeric',
                    'is_enabled' => true,
                    'device_id' => '00000000000001',
                    'pos_identifier' => 'ABC123',
                ),
                array(
                    'object' => 'TerminalRegistry',
                    'id' => 'short',
                    'terminal' => 'Short identifier terminal',
                    'type' => 'pax_A920',
                    'code' => 'short',
                    'is_enabled' => true,
                    'device_id' => '00000000000002',
                    'pos_identifier' => '42',
                ),
                array(
                    'object' => 'TerminalRegistry',
                    'id' => 'empty',
                    'terminal' => 'Missing identifier terminal',
                    'type' => 'pax_A920',
                    'code' => 'empty',
                    'is_enabled' => true,
                    'device_id' => '00000000000003',
                    'pos_identifier' => '',
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
patwc_connection_assert_same(3, $result['terminal_count'], 'Enabled terminals with any non-empty pos identifier should be counted.');
patwc_connection_assert_same('1850528139', $result['terminals'][0]['terminal_id'], 'Terminal id should come from PayArc pos_identifier.');
patwc_connection_assert_same('ABC123', $result['terminals'][1]['terminal_id'], 'Alphanumeric PayArc pos identifiers should be normalized.');
patwc_connection_assert_same('42', $result['terminals'][2]['terminal_id'], 'Short PayArc pos identifiers should be normalized.');
patwc_connection_assert_same('Front Counter A920 (pax_A920) ••••••8139', $result['terminals'][0]['label'], 'Terminal label should be merchant-friendly and masked.');
patwc_connection_assert_same('connect-access-token', $stored['connect_access_token'], 'Connect access token should be stored server-side.');
patwc_connection_assert_same(false, array_key_exists('mode', $stored), 'Connect should not switch the active saved mode before WooCommerce settings are saved.');
patwc_connection_assert_same('test', $stored['connected_mode'], 'Successful test connection should store connected mode.');
patwc_connection_assert_same((new Settings(array_merge(array('mode' => 'test'), $stored)))->connection_fingerprint(), $stored['connected_fingerprint'], 'Successful test connection should bind the token to the submitted PayArc credentials.');
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

$encodedLogs = json_encode($GLOBALS['patwc_captured_logs']);
if (!is_string($encodedLogs)) {
    throw new RuntimeException('Unable to encode captured PayArc connection logs.');
}
patwc_connection_assert_contains('PayArc connection attempt started', $encodedLogs, 'Connect should log when a connection attempt starts.');
patwc_connection_assert_contains('connect_mid_masked', $encodedLogs, 'Connect logs should include masked merchant context.');
patwc_connection_assert_contains('PayArc connection completed', $encodedLogs, 'Connect should log a successful connection summary.');
$connectDropWarnings = array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry): bool {
    return $entry['message'] === 'PayArc terminal record dropped during normalization';
}));
patwc_connection_assert_same(2, count($connectDropWarnings), 'Connect should log one warning per dropped registry record (disabled + missing identifier).');
patwc_connection_assert_same('warning', $connectDropWarnings[0]['level'], 'Dropped-record log level mismatch during connect.');
patwc_connection_assert_same('disabled', $connectDropWarnings[0]['context']['drop_reason'], 'Dropped disabled registry record should state the disabled reason.');
patwc_connection_assert_same('••••••8140', $connectDropWarnings[0]['context']['terminal_id_masked'], 'Dropped-record log should mask the terminal identifier during connect.');
patwc_connection_assert_same('missing_identifier', $connectDropWarnings[1]['context']['drop_reason'], 'Registry record without any identifier should state the missing_identifier reason.');
patwc_connection_assert_same('Not configured', $connectDropWarnings[1]['context']['terminal_id_masked'], 'Missing identifier should mask to the Not configured placeholder.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'merchant-api-token', 'Connect logs should redact API bearer token.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'connect-access-token', 'Connect logs should redact Connect access token.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'client-secret', 'Connect logs should redact client secret.');

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'Terminals' => array(array(
                'Terminal' => 'Live Counter A920',
                'Type' => 'pax_A920',
                'Is_enabled' => true,
                'Pos_identifier' => '1850528150',
            )),
            'BearerTokenInfo' => array(
                'AccessToken' => 'live-connect-access-token',
                'ExpiresIn' => 3600,
            ),
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'data' => array(array(
                'terminal' => 'Live Counter A920',
                'type' => 'pax_A920',
                'is_enabled' => true,
                'pos_identifier' => '1850528150',
            )),
        )),
    ),
);
$stored = array();
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'live-client-secret',
    'connect_secret_key' => 'live-merchant-api-token',
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$liveResult = $service->connect();
patwc_connection_assert_same(false, array_key_exists('mode', $stored), 'Live connect should not switch the active saved mode before WooCommerce settings are saved.');
patwc_connection_assert_same('production', $stored['connected_mode'], 'Successful Live connection should store connected mode.');
patwc_connection_assert_same((new Settings(array_merge(array('mode' => 'production'), $stored)))->connection_fingerprint(), $stored['connected_fingerprint'], 'Successful Live connection should bind the token to the submitted PayArc credentials.');
patwc_connection_assert_same('1850528150', $liveResult['default_terminal_id'], 'Live connection should select a live terminal.');
patwc_connection_assert_same('https://payarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][0]['url'], 'Live Login URL mismatch.');
patwc_connection_assert_same('https://api.payarc.net/v1/terminalregistries', $GLOBALS['patwc_http_requests'][1]['url'], 'Live terminal registry URL mismatch.');
patwc_connection_assert_missing_secret($liveResult, 'live-merchant-api-token', 'Live connect result should not expose API bearer token.');
patwc_connection_assert_missing_secret($liveResult, 'live-client-secret', 'Live connect result should not expose client secret.');

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 401),
        'body' => json_encode(array('error' => 'wrong environment test selected live-token-secret')),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-live-token'),
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'live-client-secret',
    'connect_secret_key' => 'live-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Test-mode connect should fail when credentials authenticate against Live.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_contains('These look like Live PayArc credentials', $exception->getMessage(), 'Test-mode connect should warn when credentials look Live.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'live-token-secret', 'Live mismatch warning must not leak API token.');
    patwc_connection_assert_same('https://testpayarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][0]['url'], 'Mismatch probe should try selected Test Login first.');
    patwc_connection_assert_same('https://payarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][1]['url'], 'Mismatch probe should try opposite Live Login second.');
    patwc_connection_assert_same(2, count($GLOBALS['patwc_http_requests']), 'Mismatch probe should not call terminal registry.');
}

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 401),
        'body' => json_encode(array('error' => 'wrong environment live selected test-token-secret')),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-test-token'),
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'test-client-secret',
    'connect_secret_key' => 'test-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Live-mode connect should fail when credentials authenticate against Test.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_contains('These look like Test PayArc credentials', $exception->getMessage(), 'Live-mode connect should warn when credentials look Test.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'test-token-secret', 'Test mismatch warning must not leak API token.');
    patwc_connection_assert_same('https://payarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][0]['url'], 'Mismatch probe should try selected Live Login first.');
    patwc_connection_assert_same('https://testpayarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][1]['url'], 'Mismatch probe should try opposite Test Login second.');
    patwc_connection_assert_same(2, count($GLOBALS['patwc_http_requests']), 'Inverse mismatch probe should not call terminal registry.');
}

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 401,
            'ErrorMessage' => 'wrong environment live selected test-token-secret',
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-test-token-from-error-code'),
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'test-client-secret',
    'connect_secret_key' => 'test-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Live-mode connect should probe Test when Login returns an API-level auth ErrorCode.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_contains('These look like Test PayArc credentials', $exception->getMessage(), 'API-level Login auth failure should warn when credentials look Test.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'test-token-secret', 'API-level mismatch warning must not leak API token.');
    patwc_connection_assert_same(2, count($GLOBALS['patwc_http_requests']), 'API-level auth mismatch should probe opposite Login only.');
}

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 1,
            'ErrorMessage' => 'wrong environment live selected test-token-secret',
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-test-token-from-error-code-1'),
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'test-client-secret',
    'connect_secret_key' => 'test-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Live-mode connect should probe Test when Login returns ErrorCode 1.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_contains('These look like Test PayArc credentials', $exception->getMessage(), 'ErrorCode 1 Login auth failure should warn when credentials look Test.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'test-token-secret', 'ErrorCode 1 mismatch warning must not leak API token.');
    patwc_connection_assert_same(2, count($GLOBALS['patwc_http_requests']), 'ErrorCode 1 auth mismatch should probe opposite Login only.');
}

$service = new PatwcStructuredAuthFailureConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'test-client-secret',
    'connect_secret_key' => 'test-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Live-mode connect should probe Test when Login throws a structured auth failure.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_contains('These look like Test PayArc credentials', $exception->getMessage(), 'Structured auth failure should warn when credentials look Test.');
    patwc_connection_assert_same(2, $service->login_calls, 'Structured auth mismatch should probe opposite Login only.');
}

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 500),
        'body' => json_encode(array('error' => 'temporary upstream outage')),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'BearerTokenInfo' => array('AccessToken' => 'opposite-mode-token-that-must-not-be-used'),
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'production',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'live-client-secret',
    'connect_secret_key' => 'live-token-secret',
)));
try {
    $service->connect();
    throw new RuntimeException('Live-mode connect should preserve a selected-mode server error instead of probing Test.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_same('PayArc request failed. HTTP status: 500.', $exception->getMessage(), 'Non-auth Login failure should not be replaced by a wrong-mode warning.');
    patwc_connection_assert_same(1, count($GLOBALS['patwc_http_requests']), 'Non-auth Login failure should not probe the opposite environment.');
    patwc_connection_assert_same('https://payarcconnectapi.curvpos.com/Login', $GLOBALS['patwc_http_requests'][0]['url'], 'Non-auth failure should only call selected Live Login.');
}

$GLOBALS['patwc_options'][PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS] = array('123' => array('status' => 'created', 'updated_at' => time()));
$GLOBALS['patwc_http_requests'] = array();
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
)));
try {
    $service->connect();
    throw new RuntimeException('Connect should be blocked while a PayArc payment is in progress.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_same('Wait for in-progress PayArc terminal payments to finish before changing the PayArc connection.', $exception->getMessage(), 'In-flight connect block message mismatch.');
    patwc_connection_assert_same(0, count($GLOBALS['patwc_http_requests']), 'In-flight connect block should happen before PayArc Login.');
}
try {
    $service->disconnect();
    throw new RuntimeException('Disconnect should be blocked while a PayArc payment is in progress.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_same('Wait for in-progress PayArc terminal payments to finish before changing the PayArc connection.', $exception->getMessage(), 'In-flight disconnect block message mismatch.');
}
try {
    $service->refresh_terminals();
    throw new RuntimeException('Terminal refresh should be blocked while a PayArc payment is in progress.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_same('Wait for in-progress PayArc terminal payments to finish before changing the PayArc connection.', $exception->getMessage(), 'In-flight refresh block message mismatch.');
    patwc_connection_assert_same(0, count($GLOBALS['patwc_http_requests']), 'In-flight refresh block should happen before PayArc terminal lookup.');
}
unset($GLOBALS['patwc_options'][PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS]);

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'Terminals' => array(array(
                'Terminal' => 'Race Counter A920',
                'Type' => 'pax_A920',
                'Is_enabled' => true,
                'Pos_identifier' => '1850528151',
            )),
            'BearerTokenInfo' => array(
                'AccessToken' => 'race-connect-access-token',
                'ExpiresIn' => 3600,
            ),
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'data' => array(array(
                'terminal' => 'Race Counter A920',
                'type' => 'pax_A920',
                'is_enabled' => true,
                'pos_identifier' => '1850528151',
            )),
        )),
    ),
);
$persistedDuringRace = false;
$GLOBALS['patwc_after_http_request'] = static function (): void {
    if (count($GLOBALS['patwc_http_requests']) === 2) {
        $GLOBALS['patwc_options'][PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS] = array('456' => array('status' => 'created', 'updated_at' => time()));
    }
};
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
)), static function (array $updates) use (&$persistedDuringRace): void {
    $persistedDuringRace = true;
});
try {
    $service->connect();
    throw new RuntimeException('Connect should recheck in-flight payments before persisting connection state.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_same('Wait for in-progress PayArc terminal payments to finish before changing the PayArc connection.', $exception->getMessage(), 'In-flight connect race block message mismatch.');
    patwc_connection_assert_same(false, $persistedDuringRace, 'In-flight connect race should not persist connection updates.');
}
unset($GLOBALS['patwc_after_http_request'], $GLOBALS['patwc_options'][PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS]);

$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 0,
            'Terminals' => array(),
            'BearerTokenInfo' => array(
                'AccessToken' => 'connect-token-without-terminals',
                'ExpiresIn' => 3600,
            ),
        )),
    ),
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array('data' => array())),
    ),
);
$stored = array();
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
    'default_terminal_id' => 'ABC123',
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$noTerminalResult = $service->connect();
patwc_connection_assert_same(0, $noTerminalResult['terminal_count'], 'Connect without valid terminals should report zero terminals.');
patwc_connection_assert_same('ABC123', $stored['default_terminal_id'], 'Connect without terminals should preserve an alphanumeric manually configured terminal serial number.');
patwc_connection_assert_same('ABC123', $noTerminalResult['default_terminal_id'], 'Connect without terminals should return the alphanumeric manually configured terminal serial number.');
patwc_connection_assert_same(true, $noTerminalResult['default_terminal_id_configured'], 'A non-empty alphanumeric terminal serial number should be reported as configured.');
patwc_connection_assert_same(array(), $stored['terminal_registry'], 'Connect without valid terminals should store an empty terminal registry.');

$stored = array();
$service = new PayArcConnectionService(new Settings(array(
    'tenant_id' => 'tenant-alpha',
    'default_terminal_id' => 'ABC123',
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$disconnectResult = $service->disconnect();
patwc_connection_assert_same(true, $disconnectResult['tenant_id_configured'], 'Disconnect should report a non-empty tenant id as configured.');
patwc_connection_assert_same(false, $disconnectResult['default_terminal_id_configured'], 'Disconnect clears the default terminal, so it must not be reported as configured.');


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

$GLOBALS['patwc_captured_logs'] = array();
$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array('data' => array())),
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
$emptyRefresh = $service->refresh_terminals();
patwc_connection_assert_same('1850528139', $stored['default_terminal_id'], 'Refresh without valid terminals should preserve a manually configured terminal serial number.');
patwc_connection_assert_same('1850528139', $emptyRefresh['default_terminal_id'], 'Refresh response should keep the manually configured terminal serial number when no terminals are discovered.');
$emptyRefreshCompleted = array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry): bool {
    return $entry['message'] === 'PayArc terminal refresh completed';
}));
patwc_connection_assert_same(1, count($emptyRefreshCompleted), 'Refresh should log exactly one completion summary.');
patwc_connection_assert_same(false, $emptyRefreshCompleted[0]['context']['default_terminal_in_fetched_list'], 'Refresh log should flag when the saved terminal serial number is not among the fetched terminals.');

// Every silently dropped registry record must leave a masked warning naming
// the drop reason, so support can explain registry_terminal_count vs
// terminal_count mismatches without raw identifiers ever reaching the logs.
$GLOBALS['patwc_captured_logs'] = array();
$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'data' => array(
                array(
                    'terminal' => 'Ghost Terminal',
                    'type' => 'pax_A920',
                    'is_enabled' => true,
                    'pos_identifier' => 'SERIAL-XYZ-987654',
                ),
                array(
                    'terminal' => 'Disabled Terminal',
                    'type' => 'pax_A920',
                    'is_enabled' => false,
                    'pos_identifier' => '1850528142',
                ),
                array(
                    'terminal' => 'Working Terminal',
                    'type' => 'pax_A920',
                    'is_enabled' => true,
                    'pos_identifier' => '1850528139',
                ),
                array(
                    'terminal' => 'Unidentified Terminal',
                    'type' => 'pax_A920',
                    'is_enabled' => true,
                    'pos_identifier' => '',
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
)), static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$droppedRefresh = $service->refresh_terminals();
patwc_connection_assert_same(2, $droppedRefresh['terminal_count'], 'Enabled terminals with any non-empty identifier should survive normalization.');

$dropWarnings = array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry): bool {
    return $entry['message'] === 'PayArc terminal record dropped during normalization';
}));
patwc_connection_assert_same(2, count($dropWarnings), 'Each dropped registry record should log exactly one warning.');
patwc_connection_assert_same('warning', $dropWarnings[0]['level'], 'Dropped-record logs should use the warning level.');
patwc_connection_assert_same('disabled', $dropWarnings[0]['context']['drop_reason'], 'Disabled record drop should state the disabled reason.');
patwc_connection_assert_same('••••••8142', $dropWarnings[0]['context']['terminal_id_masked'], 'Disabled record identifier should be masked in the drop log.');
patwc_connection_assert_same('missing_identifier', $dropWarnings[1]['context']['drop_reason'], 'Record without any identifier should state the missing_identifier reason.');
patwc_connection_assert_same('Not configured', $dropWarnings[1]['context']['terminal_id_masked'], 'Missing identifier should mask to the Not configured placeholder.');
patwc_connection_assert_same(true, array_key_exists('connect_mid_masked', $dropWarnings[0]['context']), 'Drop logs should carry the standard connection log context.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'SERIAL-XYZ-987654', 'Logs must not contain the raw alphanumeric identifier even when it is accepted.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], '1850528142', 'Drop logs must not contain the raw disabled terminal identifier.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'merchant-api-token', 'Drop logs should redact the API bearer token.');
$droppedRefreshCompleted = array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry): bool {
    return $entry['message'] === 'PayArc terminal refresh completed';
}));
patwc_connection_assert_same(1, count($droppedRefreshCompleted), 'Refresh should log exactly one completion summary for the dropped-records scenario.');
patwc_connection_assert_same(true, $droppedRefreshCompleted[0]['context']['default_terminal_in_fetched_list'], 'Refresh log should confirm when the chosen terminal is among the fetched terminals.');


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
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'merchant-api-token', 'Registry failure logs should redact API bearer token.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'client-secret', 'Registry failure logs should redact client secret text.');

$GLOBALS['patwc_captured_logs'] = array();
$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 401),
        'body' => json_encode(array(
            'error' => 'invalid key merchant-api-token',
            'ErrorMessage' => 'ClientSecret client-secret rejected',
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
)));

try {
    $service->connect();
    throw new RuntimeException('Connect should fail when PayArc returns an HTTP error.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'merchant-api-token', 'HTTP error exception should not echo submitted API bearer token.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'client-secret', 'HTTP error exception should not echo submitted client secret.');
    patwc_connection_assert_same('PayArc request failed. HTTP status: 401.', $exception->getMessage(), 'HTTP provider body text should stay private.');
}
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'merchant-api-token', 'HTTP error logs should redact API bearer token.');
patwc_connection_assert_missing_secret($GLOBALS['patwc_captured_logs'], 'client-secret', 'HTTP error logs should redact client secret.');

// A nonzero ErrorCode embeds the PayArc-returned ErrorMessage into the thrown
// exception via safe_text(). Provider text can present secrets with a plain
// whitespace separator ("invalid key <token>"), which must still be redacted.
$GLOBALS['patwc_captured_logs'] = array();
$GLOBALS['patwc_http_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(
    array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'ErrorCode' => 12,
            'ErrorMessage' => 'Rejected: invalid key merchant-api-token and token client-secret-value',
        )),
    ),
);
$service = new PayArcConnectionService(new Settings(array(
    'mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret-value',
    'connect_secret_key' => 'merchant-api-token',
)));

try {
    $service->connect();
    throw new RuntimeException('Connect should fail when PayArc returns a nonzero ErrorCode.');
} catch (RuntimeException $exception) {
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'merchant-api-token', 'Provider ErrorMessage must not leak a whitespace-separated API token into the exception.');
    patwc_connection_assert_missing_secret(array('message' => $exception->getMessage()), 'client-secret-value', 'Provider ErrorMessage must not leak a whitespace-separated client secret into the exception.');
    if (strpos($exception->getMessage(), '[REDACTED]') === false) {
        throw new RuntimeException('Provider ErrorMessage redaction marker missing from the exception.');
    }
}
