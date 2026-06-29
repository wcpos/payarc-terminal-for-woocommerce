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

    /** @var array<string, bool> */
    private static $fallbackLocks = array();

    /**
     * @return array<string, mixed>
     */
    public static function with_lock(int $order_id, string $operation, callable $callback): array
    {
        $key = self::lock_key($order_id, $operation);

        if (self::has_lock($key)) {
            return array(
                'status' => 'conflict',
                'message' => 'Another PayArc operation is already in progress for this order.',
                'continue_polling' => true,
            );
        }

        self::set_lock($key);

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

    private static function has_lock(string $key): bool
    {
        if (function_exists('get_transient')) {
            return get_transient($key) !== false;
        }

        return array_key_exists($key, self::$fallbackLocks) && self::$fallbackLocks[$key] === true;
    }

    private static function set_lock(string $key): void
    {
        if (function_exists('set_transient')) {
            set_transient($key, 1, self::LOCK_TTL_SECONDS);

            return;
        }

        self::$fallbackLocks[$key] = true;
    }

    private static function release_lock(string $key): void
    {
        if (function_exists('delete_transient')) {
            delete_transient($key);

            return;
        }

        unset(self::$fallbackLocks[$key]);
    }
}
