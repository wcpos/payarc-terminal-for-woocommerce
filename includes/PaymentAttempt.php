<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

class PaymentAttempt
{
    public const META_CURRENT_TRACE_ID = '_patwc_current_trace_id';
    public const META_CURRENT_TRANSACTION_ID = '_patwc_current_transaction_id';
    public const META_CURRENT_STATUS = '_patwc_current_status';
    public const META_CURRENT_CHARGE_ID = '_patwc_current_charge_id';
    public const META_CURRENT_TERMINAL_ID = '_patwc_current_terminal_id';
    public const META_CURRENT_ATTEMPT = '_patwc_current_attempt';
    public const META_ATTEMPT_HISTORY = '_patwc_attempt_history';
    public const META_PROCESSED_CALLBACKS = '_patwc_processed_callbacks';

    /**
     * @param object $order WooCommerce order-like object.
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    public static function record_new($order, array $attempt): array
    {
        $attempt['status'] = self::normalize_status(isset($attempt['status']) ? (string) $attempt['status'] : 'created');

        self::store_attempt_meta($order, $attempt, true);

        $history = self::get_meta($order, self::META_ATTEMPT_HISTORY);
        if (!is_array($history)) {
            $history = array();
        }
        $history[] = $attempt;

        self::update_meta($order, self::META_CURRENT_ATTEMPT, $attempt);
        self::update_meta($order, self::META_ATTEMPT_HISTORY, $history);
        self::save($order);

        return $attempt;
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return array<string, mixed>
     */
    public static function current($order): array
    {
        $current = self::get_meta($order, self::META_CURRENT_ATTEMPT);
        if (is_array($current)) {
            return $current;
        }

        return array(
            'trace_id' => self::get_meta($order, self::META_CURRENT_TRACE_ID),
            'transaction_id' => self::get_meta($order, self::META_CURRENT_TRANSACTION_ID),
            'status' => self::normalize_status((string) self::get_meta($order, self::META_CURRENT_STATUS)),
            'charge_id' => self::get_meta($order, self::META_CURRENT_CHARGE_ID),
            'terminal_id' => self::get_meta($order, self::META_CURRENT_TERMINAL_ID),
        );
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function update_status($order, string $status, array $fields = array()): array
    {
        $attempt = self::current($order);
        foreach ($fields as $key => $value) {
            if (self::is_identifier_field($key) && self::is_empty_identifier($value) && !self::is_empty_identifier(isset($attempt[$key]) ? $attempt[$key] : null)) {
                continue;
            }

            $attempt[$key] = $value;
        }
        $attempt['status'] = self::normalize_status($status);

        self::store_attempt_meta($order, $attempt, false);
        self::update_meta($order, self::META_CURRENT_ATTEMPT, $attempt);
        self::save($order);

        return $attempt;
    }

    public static function normalize_status(string $status): string
    {
        $normalized = strtolower(trim($status));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        if (!is_string($normalized)) {
            $normalized = strtolower(trim($status));
        }

        if (in_array($normalized, array('success', 'successful', 'approved', 'approval', 'completed', 'complete'), true)) {
            return 'success';
        }

        if ($normalized === 'declined') {
            return 'decline';
        }

        if ($normalized === 'canceled') {
            return 'cancelled';
        }

        if ($normalized === 'failed') {
            return 'failure';
        }

        return $normalized === '' ? 'created' : $normalized;
    }

    public static function is_non_final(string $status): bool
    {
        return in_array(self::normalize_status($status), array('created', 'pending', 'sent', 'processing'), true);
    }

    public static function is_final_unpaid(string $status): bool
    {
        return in_array(self::normalize_status($status), array('decline', 'timeout', 'cancelled', 'failure', 'dup transaction'), true);
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @param array<string, mixed> $attempt
     */
    private static function store_attempt_meta($order, array $attempt, bool $replaceCurrentSnapshot): void
    {
        $metaMap = array(
            'trace_id' => self::META_CURRENT_TRACE_ID,
            'transaction_id' => self::META_CURRENT_TRANSACTION_ID,
            'status' => self::META_CURRENT_STATUS,
            'charge_id' => self::META_CURRENT_CHARGE_ID,
            'terminal_id' => self::META_CURRENT_TERMINAL_ID,
        );

        foreach ($metaMap as $field => $metaKey) {
            if (array_key_exists($field, $attempt)) {
                self::update_meta($order, $metaKey, $attempt[$field]);
            } elseif ($replaceCurrentSnapshot) {
                self::delete_meta($order, $metaKey);
            }
        }
    }

    private static function is_identifier_field(string $field): bool
    {
        return in_array($field, array('trace_id', 'transaction_id', 'charge_id', 'terminal_id'), true);
    }

    /**
     * @param mixed $value
     */
    private static function is_empty_identifier($value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return mixed
     */
    private static function get_meta($order, string $key)
    {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            return $order->get_meta($key, true);
        }

        return '';
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @param mixed $value
     */
    private static function update_meta($order, string $key, $value): void
    {
        if (is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($key, $value);
        }
    }

    /**
     * @param object $order WooCommerce order-like object.
     */
    private static function delete_meta($order, string $key): void
    {
        if (is_object($order) && method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data($key);
        }
    }

    /**
     * @param object $order WooCommerce order-like object.
     */
    private static function save($order): void
    {
        if (is_object($order) && method_exists($order, 'save')) {
            $order->save();
        }
    }
}
