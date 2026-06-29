<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

/**
 * Provides a short-lived advisory lock for PayArc order operations.
 *
 * This lock is advisory: it reduces duplicate terminal commands from concurrent
 * requests, but it is not a database-atomic mutex and must not be treated as a
 * guarantee that only one process can ever enter the critical section.
 */
class PaymentLock
{
    private const LOCK_TTL_SECONDS = 30;

    /** @var array<string, array<string, int>> */
    private static $fallbackLocks = array();

    /**
     * @return array<string, mixed>
     */
    public static function with_lock(int $order_id, string $operation, callable $callback): array
    {
        $key = self::lock_key($order_id, $operation);

        if (!self::acquire_lock($key)) {
            return array(
                'status' => 'conflict',
                'message' => 'Another PayArc operation is already in progress for this order.',
                'continue_polling' => true,
            );
        }

        try {
            $result = $callback();

            return is_array($result) ? $result : array('status' => 'error');
        } finally {
            self::release_lock($key);
        }
    }

    private static function lock_key(int $order_id, string $operation): string
    {
        $sanitizedOperation = preg_replace('/[^A-Za-z0-9_-]+/', '_', $operation);
        if (!is_string($sanitizedOperation)) {
            $sanitizedOperation = '';
        }
        $sanitizedOperation = trim($sanitizedOperation, '_-');

        if ($sanitizedOperation === '') {
            $sanitizedOperation = 'operation';
        }

        return 'patwc_lock_' . $order_id . '_' . $sanitizedOperation;
    }

    private static function acquire_lock(string $key): bool
    {
        $payload = array('expires_at' => time() + self::LOCK_TTL_SECONDS);

        if (function_exists('add_option')) {
            if (add_option($key, $payload, '', 'no')) {
                return true;
            }

            if (function_exists('get_option') && function_exists('delete_option')) {
                $existing = get_option($key, false);
                if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] < time()) {
                    delete_option($key);

                    return add_option($key, $payload, '', 'no');
                }
            }

            return false;
        }

        if (array_key_exists($key, self::$fallbackLocks)) {
            if ((int) self::$fallbackLocks[$key]['expires_at'] < time()) {
                unset(self::$fallbackLocks[$key]);
            } else {
                return false;
            }
        }

        self::$fallbackLocks[$key] = $payload;

        return true;
    }

    private static function release_lock(string $key): void
    {
        if (function_exists('delete_option')) {
            delete_option($key);

            return;
        }

        unset(self::$fallbackLocks[$key]);
    }
}
