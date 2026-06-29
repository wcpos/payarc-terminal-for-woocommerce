<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Logger.php';

use WCPOS\WooCommercePOS\PayArcTerminal\Logger;

$captured = array();

if (!function_exists('wc_get_logger')) {
    function wc_get_logger()
    {
        return new class {
            public function info($message, array $context = array())
            {
                $GLOBALS['captured'][] = array('message' => $message, 'context' => $context);
            }
        };
    }
}

$GLOBALS['captured'] = array();

Logger::log('Bearer live_secret_token', array(
    'headers' => array(
        'Authorization' => 'Bearer live_authorization_token',
        'X-Api-Key' => 'header-api-key-secret',
    ),
    'api_bearer_token' => 'api-secret',
    'callback_bearer_token' => 'callback-secret',
    'token' => 'payarc-token',
    'accessToken' => 'access-token-secret',
    'bearerToken' => 'bearer-token-secret',
    'apiKey' => 'api-key-secret',
    'nested' => array(
        'payarc_token' => 'payarc-nested-secret',
    ),
));

$encoded = json_encode($GLOBALS['captured']);

if (!is_string($encoded)) {
    throw new RuntimeException('Unable to encode captured log output.');
}

foreach (array('live_secret_token', 'live_authorization_token', 'api-secret', 'callback-secret', 'payarc-token', 'access-token-secret', 'bearer-token-secret', 'api-key-secret', 'payarc-nested-secret', 'header-api-key-secret') as $secret) {
    if (strpos($encoded, $secret) !== false) {
        throw new RuntimeException('Secret was not redacted: ' . $secret);
    }
}

if (strpos($encoded, '[REDACTED]') === false) {
    throw new RuntimeException('Redaction marker missing from log output.');
}
