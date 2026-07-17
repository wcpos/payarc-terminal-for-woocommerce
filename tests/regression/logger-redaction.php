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
    'connect_secret_key_submitted' => true,
    'connect_access_token_returned' => false,
    'connect_secret_key' => 'configured-secret-value',
    'api_key' => 123456789,
    'numericSecret' => 987654321,
));
$summary = end($GLOBALS['captured']);
if (!is_array($summary)) {
    throw new RuntimeException('Configuration summary log was not captured.');
}

if (
    ($summary['context']['connect_secret_key_configured'] ?? null) !== true
    || ($summary['context']['connect_secret_key_submitted'] ?? null) !== true
    || ($summary['context']['connect_access_token_returned'] ?? null) !== false
) {
    throw new RuntimeException('Logger should preserve boolean diagnostic flags for secret-related configuration keys.');
}

if (($summary['context']['connect_secret_key'] ?? '') !== '[REDACTED]') {
    throw new RuntimeException('Logger should still redact actual secret values.');
}

if (($summary['context']['api_key'] ?? '') !== '[REDACTED]' || ($summary['context']['numericSecret'] ?? '') !== '[REDACTED]') {
    throw new RuntimeException('Logger should redact numeric secret values.');
}

// Redaction must not mangle ordinary prose that merely mentions a keyword
// (a value is only treated as a secret when it follows a ":" or "=" delimiter).
$GLOBALS['captured'] = array();
Logger::log('the key rotated and the token refreshed and the password changed');
$prose = end($GLOBALS['captured']);
if (!is_array($prose) || strpos((string) $prose['message'], '[REDACTED]') !== false) {
    throw new RuntimeException('Logger should not over-redact ordinary prose that mentions secret-related words.');
}

// A genuine "keyword=value" / "keyword: value" secret is still redacted.
$GLOBALS['captured'] = array();
Logger::log('api_key=sk_live_abcd1234 and token: bearer_value_9876');
$leak = end($GLOBALS['captured']);
if (
    !is_array($leak)
    || strpos((string) $leak['message'], 'sk_live_abcd1234') !== false
    || strpos((string) $leak['message'], 'bearer_value_9876') !== false
    || strpos((string) $leak['message'], '[REDACTED]') === false
) {
    throw new RuntimeException('Logger should still redact delimited keyword secrets.');
}

$traceFailure = Logger::redact_untrusted_text('PayArc request failed; traceId: trace_failure_123.');
if (strpos($traceFailure, 'trace_failure_123') !== false || strpos($traceFailure, 'traceId=[REDACTED]') === false) {
    throw new RuntimeException('Untrusted exception text should redact embedded PayArc trace ids.');
}

// The patwc_logging filter can disable logging entirely.
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        if ($hook === 'patwc_logging' && !empty($GLOBALS['patwc_disable_logging'])) {
            return false;
        }

        return $value;
    }
}

$GLOBALS['patwc_disable_logging'] = true;

// The regression runner shares one PHP process, so a stub from another file
// could shadow ours. Assert this test's stub is the authoritative one before
// trusting the toggle, so the gate is always genuinely exercised.
if (apply_filters('patwc_logging', true) !== false) {
    throw new RuntimeException('Test setup: the patwc_logging stub in effect does not honor the toggle; cannot exercise the logging gate.');
}

$GLOBALS['captured'] = array();
Logger::log('this must not be logged', array('foo' => 'bar'));
if ($GLOBALS['captured'] !== array()) {
    throw new RuntimeException('patwc_logging=false should suppress all logging.');
}

$GLOBALS['patwc_disable_logging'] = false;
$GLOBALS['captured'] = array();
Logger::log('this should be logged');
if ($GLOBALS['captured'] === array()) {
    throw new RuntimeException('Logging should resume when patwc_logging is true.');
}
unset($GLOBALS['patwc_disable_logging']);
