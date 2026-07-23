<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;

$root = dirname(__DIR__, 2);
$requiredFiles = array(
    $root . '/includes/Settings.php',
    $root . '/includes/Logger.php',
    $root . '/includes/Services/PayArcConnectionService.php',
    $root . '/includes/Utils/Money.php',
    $root . '/includes/Services/PayArcClient.php',
);

foreach ($requiredFiles as $requiredFile) {
    if (!is_readable($requiredFile)) {
        throw new RuntimeException('Required class file is missing: ' . basename($requiredFile));
    }

    require_once $requiredFile;
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $GLOBALS['patwc_client_requests'][] = array(
            'url' => $url,
            'args' => $args,
        );

        return $GLOBALS['patwc_client_response'];
    }
}

function patwc_client_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_client_response'] = array(
    'response' => array('code' => 200),
    'body' => json_encode(array('traceId' => 'trace_123', 'response' => array('status' => 'SUCCESS'))),
);

$settings = new Settings(array(
    'mode' => 'test',
    'api_bearer_token' => 'merchant-api-token',
    'connect_secret_key' => 'merchant-api-token',
    'connect_access_token' => 'connect-access-token',
    'connect_token_expires_at' => (string) (time() + 3600),
    'callback_bearer_token' => 'callback-secret-token',
));
$client = new PayArcClient($settings);
$idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
$payload = array(
    'tenantId' => '123456789012',
    'terminalId' => '1234567890',
    'transactionId' => 'P000000ABCDEF12',
    'tenderType' => 'CREDIT',
    'amount' => Money::to_payarc_amount_object('10.23', 'USD'),
    'printReceipt' => 0,
    'callbackURL' => 'https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
    'metadata' => array('order_id' => 123),
);

$result = $client->sale($payload, $idempotencyKey);

patwc_client_assert_same(array('traceId' => 'trace_123', 'response' => array('status' => 'SUCCESS')), $result, 'Sale should return decoded response.');
patwc_client_assert_same(1, count($GLOBALS['patwc_client_requests']), 'Sale should make one HTTP request.');

$request = $GLOBALS['patwc_client_requests'][0];
$args = $request['args'];
$headers = $args['headers'];
$body = json_decode($args['body'], true);

patwc_client_assert_same('https://testpayarcconnectapi.payarc.net/v3/transactions/sale', $request['url'], 'Sale URL mismatch.');
patwc_client_assert_same('POST', $args['method'], 'Sale method mismatch.');
patwc_client_assert_same('application/json', $headers['Accept'], 'Accept header mismatch.');
patwc_client_assert_same('application/json', $headers['Content-Type'], 'Content-Type header mismatch.');
patwc_client_assert_same('Bearer connect-access-token', $headers['Authorization'], 'Connect V3 requests should use the Login AccessToken, not the Merchant Dashboard API token.');
patwc_client_assert_same($idempotencyKey, $headers['X-Idempotency-Key'], 'Idempotency header mismatch.');
patwc_client_assert_same(array(
    'tenantId' => '123456789012',
    'terminalId' => '1234567890',
    'transactionId' => 'P000000ABCDEF12',
    'tenderType' => 'CREDIT',
    'amount' => array('total' => 1023, 'subtotal' => 1023, 'currency' => 'USD', 'tip' => 0, 'tax' => 0),
    'printReceipt' => 0,
    'callbackURL' => 'https://merchant.example/wp-admin/admin-ajax.php?action=patwc_payarc_callback',
    'metadata' => array('order_id' => 123),
), $body, 'Sale JSON payload mismatch.');


$encodedRequest = json_encode($request);
if (!is_string($encodedRequest) || strpos($encodedRequest, 'callback-secret-token') !== false) {
    throw new RuntimeException('Sale request must not include callback bearer token.');
}

$GLOBALS['patwc_client_requests'] = array();
$mismatchedModeClient = new PayArcClient(new Settings(array(
    'mode' => 'production',
    'connected_mode' => 'test',
    'connect_access_token' => 'test-connect-access-token',
)));
try {
    $mismatchedModeClient->sale($payload, $idempotencyKey);
    throw new RuntimeException('Client should reject a token created for a different PayArc mode.');
} catch (RuntimeException $exception) {
    patwc_client_assert_same('PayArc Connect AccessToken does not match the selected PayArc mode. Press Connect PayArc after changing mode before taking payments.', $exception->getMessage(), 'Mode mismatch should produce an actionable local error.');
    patwc_client_assert_same(0, count($GLOBALS['patwc_client_requests']), 'Mode mismatch should be blocked before any PayArc transaction request.');
}

$GLOBALS['patwc_client_requests'] = array();
$staleFingerprintSettings = array(
    'mode' => 'test',
    'connected_mode' => 'test',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'new-merchant-api-token',
    'connect_access_token' => 'old-connect-access-token',
);
$oldFingerprintSettings = $staleFingerprintSettings;
$oldFingerprintSettings['connect_secret_key'] = 'old-merchant-api-token';
$staleFingerprintSettings['connected_fingerprint'] = Settings::connection_fingerprint_for($oldFingerprintSettings);
$staleFingerprintClient = new PayArcClient(new Settings($staleFingerprintSettings));
try {
    $staleFingerprintClient->sale($payload, $idempotencyKey);
    throw new RuntimeException('Client should reject a token created for different PayArc credentials.');
} catch (RuntimeException $exception) {
    patwc_client_assert_same('PayArc Connect AccessToken does not match the saved PayArc credentials. Press Connect PayArc after changing credentials before taking payments.', $exception->getMessage(), 'Credential mismatch should produce an actionable local error.');
    patwc_client_assert_same(0, count($GLOBALS['patwc_client_requests']), 'Credential mismatch should be blocked before any PayArc transaction request.');
}

$GLOBALS['patwc_client_requests'] = array();
$liveWithoutLiveConnectClient = new PayArcClient(new Settings(array(
    'mode' => 'production',
    'connect_access_token' => 'legacy-token-without-connected-mode',
)));
try {
    $liveWithoutLiveConnectClient->sale($payload, $idempotencyKey);
    throw new RuntimeException('Client should reject Live mode without a Live connection marker.');
} catch (RuntimeException $exception) {
    patwc_client_assert_same('PayArc Live mode requires a Live Connect AccessToken. Press Connect PayArc in Live mode before taking payments.', $exception->getMessage(), 'Live mode without a Live connection should produce an actionable local error.');
    patwc_client_assert_same(0, count($GLOBALS['patwc_client_requests']), 'Live mode without a Live connection should be blocked before any PayArc transaction request.');
}


$GLOBALS['patwc_client_requests'] = array();
$timeoutPayload = array(
    'traceId' => 'trace_timeout_123',
    'status' => 'TIMEOUT',
    'transactionId' => 'P000000ABCDEF12',
    'error' => array(
        'code' => 'TERMINAL_TIMEOUT',
        'message' => 'Terminal timed out waiting for card presentation.',
        'friendlyMessage' => 'The terminal timed out.',
    ),
);
$GLOBALS['patwc_client_response'] = array(
    'response' => array('code' => 200),
    'body' => json_encode($timeoutPayload),
);

$timeoutResult = $client->get_transaction('trace_timeout_123');
patwc_client_assert_same($timeoutPayload, $timeoutResult, 'Transaction outcome payloads with top-level error should be returned for reconciliation.');
patwc_client_assert_same(1, count($GLOBALS['patwc_client_requests']), 'Get transaction should make one HTTP request.');
patwc_client_assert_same('https://testpayarcconnectapi.payarc.net/v3/transactions/trace_timeout_123', $GLOBALS['patwc_client_requests'][0]['url'], 'Get transaction URL mismatch.');
patwc_client_assert_same('GET', $GLOBALS['patwc_client_requests'][0]['args']['method'], 'Get transaction method mismatch.');

$GLOBALS['patwc_client_requests'] = array();
$GLOBALS['patwc_client_response'] = array(
    'response' => array('code' => 400),
    'body' => json_encode(array(
        'traceId' => 'trace_failure_123',
        'response' => array(
            'status' => 'FAILURE',
            'error' => array(
                'code' => 'INVALID_REQUEST',
                'message' => 'terminalId is required or invalid',
                'friendlyMessage' => 'The terminal identifier is invalid for this mock request.',
            ),
        ),
    )),
);

try {
    $client->sale($payload, $idempotencyKey);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    foreach (array('HTTP status: 400', 'traceId: trace_failure_123', 'code: INVALID_REQUEST', 'message: terminalId is required or invalid', 'friendlyMessage: The terminal identifier is invalid for this mock request.') as $expectedPart) {
        if (strpos($message, $expectedPart) === false) {
            throw new RuntimeException('PayArc failure message missing safe detail: ' . $expectedPart);
        }
    }

    foreach (array('merchant-api-token', 'connect-access-token', 'callback-secret-token', 'Authorization', json_encode($payload)) as $secretPart) {
        if (is_string($secretPart) && $secretPart !== '' && strpos($message, $secretPart) !== false) {
            throw new RuntimeException('PayArc failure message leaked secret or unsafe request detail.');
        }
    }

    return;
}

throw new RuntimeException('PayArc failure response should throw RuntimeException.');
