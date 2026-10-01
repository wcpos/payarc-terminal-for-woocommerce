<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

/**
 * INSERT IGNORE claims the options table's unique option_name atomically;
 * add_option() checks and then upserts. Compare-and-delete keeps a stale
 * holder from deleting a replacement claim.
 */
class PaymentLock
{
    private const LOCK_TTL_SECONDS = 30;

    /** @var array<string, array<string, int>> */
    private static $fallbackLocks = array();

    /** @var array<string, string> */
    private static $held = array();

    /**
     * @return array<string, mixed>
     */
    public static function with_lock(int $order_id, string $operation, callable $callback): array
    {
        if (!self::acquire($order_id, $operation)) {
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
            self::release($order_id, $operation);
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

    public static function acquire(int $order_id, string $operation, int $ttl = self::LOCK_TTL_SECONDS): bool
    {
        $key = self::lock_key($order_id, $operation);

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
            $value = json_encode(array('token' => bin2hex(random_bytes(16)), 'expires_at' => time() + $ttl));
            $claim = $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value);
            if (1 === $wpdb->query($claim)) {
                self::$held[$key] = $value;

                return true;
            }

            $existing = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
            $lock = json_decode((string) $existing, true);
            if (!is_array($lock) || !isset($lock['expires_at']) || !is_numeric($lock['expires_at']) || $lock['expires_at'] < time()) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $existing));
                if (1 === $wpdb->query($claim)) {
                    self::$held[$key] = $value;

                    return true;
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

        self::$fallbackLocks[$key] = array('expires_at' => time() + $ttl);

        return true;
    }

    public static function release(int $order_id, string $operation): void
    {
        $key = self::lock_key($order_id, $operation);

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
            if (isset(self::$held[$key])) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, self::$held[$key]));
                unset(self::$held[$key]);
            }

            return;
        }

        unset(self::$fallbackLocks[$key]);
    }
}
