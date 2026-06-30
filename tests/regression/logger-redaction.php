<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Logger.php';

use WCPOS\WooCommercePOS\PayArcTerminal\Logger;

$captured = array();

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
                $GLOBALS['captured'][] = array('level' => $level, 'message' => $message, 'context' => $context);
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

$GLOBALS['captured'] = array();
Logger::log('Connection failed', array('action' => 'connect'), null, 'error');
$last = end($GLOBALS['captured']);
if (!is_array($last) || ($last['level'] ?? '') !== 'error') {
    throw new RuntimeException('Logger should write to the requested WooCommerce log level.');
}

if (($last['context']['source'] ?? '') !== 'payarc-terminal-for-woocommerce') {
    throw new RuntimeException('Logger should always set the WooCommerce log source.');
}

$GLOBALS['captured'] = array();
Logger::log('Configuration summary', array(
    'connect_secret_key_configured' => true,
    'connect_access_token_returned' => false,
    'connect_secret_key' => 'configured-secret-value',
));
$summary = end($GLOBALS['captured']);
if (!is_array($summary)) {
    throw new RuntimeException('Configuration summary log was not captured.');
}

if (($summary['context']['connect_secret_key_configured'] ?? null) !== true || ($summary['context']['connect_access_token_returned'] ?? null) !== false) {
    throw new RuntimeException('Logger should preserve boolean diagnostic flags for secret-related configuration keys.');
}

if (($summary['context']['connect_secret_key'] ?? '') !== '[REDACTED]') {
    throw new RuntimeException('Logger should still redact actual secret values.');
}
