<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcConnectionService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/Logger.php',
    $root . '/includes/Services/PayArcConnectionService.php',
    $root . '/includes/Services/PayArcClient.php',
) as $file) {
    require_once $file;
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $record = array('url' => $url, 'args' => $args);
        $GLOBALS['patwc_client_requests'][] = $record;

        return array_shift($GLOBALS['patwc_http_response_queue']);
    }
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

            private function capture(string $level, $message, array $context): void
            {
                $GLOBALS['patwc_captured_logs'][] = array('level' => $level, 'message' => $message, 'context' => $context);
            }
        };
    }
}

function patwc_v3_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_v3_response(int $status): array
{
    return array(
        'response' => array('code' => $status),
        'body' => json_encode($status === 200
            ? array('traceId' => 'trace_123', 'response' => array('status' => 'SUCCESS'))
            : array('code' => 'UNAUTHORIZED', 'message' => 'Missing or invalid authentication credentials')),
    );
}

class PatwcV3FallbackConnectionService extends PayArcConnectionService
{
    /** @var int */
    public $login_calls = 0;

    /** @var string */
    public $refreshed_token = 'refreshed-access-token';

    public function login(?Settings $settings = null): array
    {
        $this->login_calls++;

        return array('BearerTokenInfo' => array('AccessToken' => $this->refreshed_token, 'ExpiresIn' => 3600));
    }
}

$baseSettings = array(
    'mode' => 'test',
    'connect_secret_key' => 'merchant-secret-key',
    'connect_access_token' => 'stale-access-token',
    'connect_token_expires_at' => (string) (time() + 3600),
);
$idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
$payload = array('tenantId' => '123456789012', 'terminalId' => '1234567890', 'amount' => array('total' => 100));

// Default AccessToken: stale 401, refreshed 401, then SecretKey succeeds.
$stored = array();
$settings = new Settings($baseSettings);
$service = new PatwcV3FallbackConnectionService($settings, static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_captured_logs'] = array();
$GLOBALS['patwc_http_response_queue'] = array(patwc_v3_response(401), patwc_v3_response(401), patwc_v3_response(200));
(new PayArcClient($settings, $service))->sale($payload, $idempotencyKey);

patwc_v3_assert_same(array(
    'Bearer stale-access-token',
    'Bearer refreshed-access-token',
    'Bearer merchant-secret-key',
), array_map(static function (array $request): string {
    return $request['args']['headers']['Authorization'];
}, $GLOBALS['patwc_client_requests']), 'AccessToken fallback Authorization sequence mismatch.');
patwc_v3_assert_same(1, $service->login_calls, 'A request should perform at most one PayArc Login.');
patwc_v3_assert_same(array($idempotencyKey, $idempotencyKey, $idempotencyKey), array_map(static function (array $request): string {
    return $request['args']['headers']['X-Idempotency-Key'];
}, $GLOBALS['patwc_client_requests']), 'Every fallback attempt should reuse the idempotency key.');
patwc_v3_assert_same('secret_key', $stored['v3_auth_credential'] ?? '', 'The working SecretKey preference should be persisted.');
$fallbackLog = end($GLOBALS['patwc_captured_logs']);
patwc_v3_assert_same('warning', $fallbackLog['level'] ?? '', 'Successful fallback should log a warning.');
patwc_v3_assert_same('PayArc V3 credential fallback succeeded', $fallbackLog['message'] ?? '', 'Successful fallback warning mismatch.');
patwc_v3_assert_same(array(
    'request_host' => 'testpayarcconnectapi.payarc.net',
    'worked_with' => 'secret_key',
    'previously_preferred' => 'access_token',
    'source' => 'payarc-terminal-for-woocommerce',
), $fallbackLog['context'] ?? array(), 'Successful fallback warning context mismatch.');

// Every credential attempt rejected.
$stored = array();
$settings = new Settings($baseSettings);
$service = new PatwcV3FallbackConnectionService($settings, static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(patwc_v3_response(401), patwc_v3_response(401), patwc_v3_response(401));
try {
    (new PayArcClient($settings, $service))->sale($payload, $idempotencyKey);
    throw new RuntimeException('Both rejected credentials should throw.');
} catch (RuntimeException $exception) {
    foreach (array('Connect AccessToken', 'SecretKey', 'testpayarcconnectapi.payarc.net') as $part) {
        if (strpos($exception->getMessage(), $part) === false) {
            throw new RuntimeException('Both-credentials exception missing: ' . $part . '.');
        }
    }
}

// Preferred SecretKey falls back directly to one freshly logged-in AccessToken.
$stored = array();
$settings = new Settings(array_merge($baseSettings, array('v3_auth_credential' => 'secret_key')));
$service = new PatwcV3FallbackConnectionService($settings, static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
});
$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(patwc_v3_response(401), patwc_v3_response(200));
(new PayArcClient($settings, $service))->sale($payload, $idempotencyKey);
patwc_v3_assert_same(array('Bearer merchant-secret-key', 'Bearer refreshed-access-token'), array_map(static function (array $request): string {
    return $request['args']['headers']['Authorization'];
}, $GLOBALS['patwc_client_requests']), 'SecretKey fallback Authorization sequence mismatch.');
patwc_v3_assert_same(1, $service->login_calls, 'SecretKey fallback should Login exactly once.');
patwc_v3_assert_same('access_token', $stored['v3_auth_credential'] ?? '', 'The working AccessToken preference should be persisted.');

// A defensively accepted service object without refresh support must still terminate.
$settings = new Settings($baseSettings);
$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(patwc_v3_response(401), patwc_v3_response(401), patwc_v3_response(200));
(new PayArcClient($settings, new stdClass()))->sale($payload, $idempotencyKey);
patwc_v3_assert_same(array(
    'Bearer stale-access-token',
    'Bearer stale-access-token',
    'Bearer merchant-secret-key',
), array_map(static function (array $request): string {
    return $request['args']['headers']['Authorization'];
}, $GLOBALS['patwc_client_requests']), 'Missing refresh support should still advance to the finite SecretKey fallback.');

// Missing Login expiry receives a finite default, and expiry 0 triggers refresh.
$stored = array();
$zeroExpirySettings = new Settings(array_merge($baseSettings, array('connect_token_expires_at' => '0')));
$serviceWithoutExpiry = new class($zeroExpirySettings, static function (array $updates) use (&$stored): void {
    $stored = array_merge($stored, $updates);
}, static function (): int {
    return 1000;
}) extends PatwcV3FallbackConnectionService {
    public function login(?Settings $settings = null): array
    {
        $this->login_calls++;

        return array('BearerTokenInfo' => array('AccessToken' => 'token-without-expiry'));
    }
};
$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_http_response_queue'] = array(patwc_v3_response(200));
(new PayArcClient($zeroExpirySettings, $serviceWithoutExpiry))->sale($payload, $idempotencyKey);
patwc_v3_assert_same('Bearer token-without-expiry', $GLOBALS['patwc_client_requests'][0]['args']['headers']['Authorization'], 'Expiry 0 should refresh before the V3 request.');
patwc_v3_assert_same('2200', $stored['connect_token_expires_at'] ?? '', 'Missing ExpiresIn should store now + 1200.');

patwc_v3_assert_same('access_token', (new Settings(array('v3_auth_credential' => 'invalid')))->v3_auth_credential(), 'Invalid preference should default to AccessToken.');
