<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

class Logger
{
    private const SOURCE = 'payarc-terminal-for-woocommerce';
    private const REDACTED = '[REDACTED]';

    /**
     * Write a redacted message to the WooCommerce logger or PHP error log.
     *
     * Logging can be disabled with the `patwc_logging` filter (matching the
     * Stripe/SumUp terminal gateways' `stwc_logging`/`sutwc_logging` toggles):
     *
     *     add_filter( 'patwc_logging', '__return_false' );
     *
     * @param mixed $message Log message.
     * @param array<string, mixed> $context Log context.
     * @param mixed $order Optional WooCommerce order object.
     */
    public static function log($message, array $context = array(), $order = null, string $level = 'info'): void
    {
        // Toggle only — do not pass $message, which is still raw here, so the
        // filter cannot become a way to read unredacted secrets.
        if (function_exists('apply_filters') && !apply_filters('patwc_logging', true)) {
            return;
        }

        $safeMessage = self::redactValue($message);
        $safeContext = self::redactValue($context);
        $safeLevel = self::normalizeLevel($level);

        if ($order !== null && is_object($order) && method_exists($order, 'get_id')) {
            $safeContext['order_id'] = $order->get_id();
        }

        $safeContext['source'] = self::SOURCE;

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            if (is_object($logger) && method_exists($logger, $safeLevel)) {
                $logger->{$safeLevel}(self::stringify($safeMessage), $safeContext);
                return;
            }

            if (is_object($logger) && method_exists($logger, 'info')) {
                $safeContext['requested_level'] = $safeLevel;
                $logger->info(self::stringify($safeMessage), $safeContext);
                return;
            }
        }

        error_log(strtoupper($safeLevel) . ' ' . self::stringify($safeMessage) . ' ' . self::stringify($safeContext));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function redactValue($value)
    {
        if (is_array($value)) {
            $redacted = array();

            foreach ($value as $key => $item) {
                if (self::isSecretKey((string) $key)) {
                    $redacted[$key] = is_bool($item) && self::isSecretDiagnosticKey((string) $key) ? $item : self::REDACTED;
                    continue;
                }

                $redacted[$key] = self::redactValue($item);
            }

            return $redacted;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = self::normalizedKey($key);
        $compact = str_replace('_', '', $normalized);

        if ($normalized === 'authorization') {
            return true;
        }

        if (in_array($normalized, array('api_bearer_token', 'connect_secret_key', 'connect_access_token', 'connect_client_secret', 'callback_bearer_token'), true)) {
            return true;
        }

        if (strpos($normalized, 'bearer_token') !== false) {
            return true;
        }

        if (strpos($normalized, 'payarc') !== false && strpos($normalized, 'token') !== false) {
            return true;
        }

        if (strpos($normalized, 'api_key') !== false || strpos($compact, 'apikey') !== false) {
            return true;
        }

        foreach (array('authorization', 'token', 'bearer', 'secret', 'password', 'credential') as $indicator) {
            if (strpos($normalized, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isSecretDiagnosticKey(string $key): bool
    {
        $normalized = self::normalizedKey($key);

        return preg_match('/_(configured|submitted|returned)$/', $normalized) === 1;
    }

    private static function normalizedKey(string $key): string
    {
        $wordSeparated = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        if (!is_string($wordSeparated)) {
            $wordSeparated = $key;
        }

        $normalized = strtolower($wordSeparated);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        if (!is_string($normalized)) {
            $normalized = strtolower($wordSeparated);
        }

        $normalized = preg_replace('/_+/', '_', $normalized);
        if (!is_string($normalized)) {
            $normalized = strtolower($wordSeparated);
        }

        return trim($normalized, '_');
    }

    private static function redactString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer ' . self::REDACTED, $value) ?? self::REDACTED;
        // Logger messages are the plugin's own (trusted) strings and context is
        // already key-redacted, so require an explicit ":"/"=" delimiter here to
        // avoid mangling ordinary prose like "the key rotated". Untrusted
        // provider/exception text is handled by the aggressive safe_text()/
        // safe_public_error_text() sanitizers instead.
        $value = preg_replace('/\b(token|secret|key|password|client_secret|secret_key|access_token|api_key)\s*[:=]\s*[A-Za-z0-9._~+\/=:-]{4,}/i', '$1=' . self::REDACTED, $value);

        return is_string($value) ? $value : self::REDACTED;
    }

    /**
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value);

        if (is_string($encoded)) {
            return $encoded;
        }

        return '[unloggable]';
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return in_array($level, array('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'), true) ? $level : 'info';
    }
}
