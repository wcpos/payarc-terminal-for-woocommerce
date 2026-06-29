<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

class Logger
{
    private const SOURCE = 'payarc-terminal-for-woocommerce';
    private const REDACTED = '[REDACTED]';

    /**
     * Write a redacted message to the WooCommerce logger or PHP error log.
     *
     * @param mixed $message Log message.
     * @param array<string, mixed> $context Log context.
     * @param mixed $order Optional WooCommerce order object.
     */
    public static function log($message, array $context = array(), $order = null): void
    {
        $safeMessage = self::redactValue($message);
        $safeContext = self::redactValue($context);

        if ($order !== null && is_object($order) && method_exists($order, 'get_id')) {
            $safeContext['order_id'] = $order->get_id();
        }

        $safeContext['source'] = self::SOURCE;

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            if (is_object($logger) && method_exists($logger, 'info')) {
                $logger->info(self::stringify($safeMessage), $safeContext);
                return;
            }
        }

        error_log(self::stringify($safeMessage) . ' ' . self::stringify($safeContext));
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
                    $redacted[$key] = self::REDACTED;
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
        $normalized = strtolower(str_replace(array('-', ' '), '_', $key));

        if ($normalized === 'authorization') {
            return true;
        }

        if (in_array($normalized, array('api_bearer_token', 'callback_bearer_token'), true)) {
            return true;
        }

        if (strpos($normalized, 'bearer_token') !== false) {
            return true;
        }

        if (strpos($normalized, 'payarc') !== false && strpos($normalized, 'token') !== false) {
            return true;
        }

        return $normalized === 'token' || substr($normalized, -6) === '_token';
    }

    private static function redactString(string $value): string
    {
        return preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer ' . self::REDACTED, $value) ?? self::REDACTED;
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
}
