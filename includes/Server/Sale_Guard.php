<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

/**
 * One marker per Pro sale that may be on a terminal: what the sale sent (so Free's replay of a lost
 * answer is the byte-identical command under the same key), and a hold on the gateway's mode,
 * credentials and Connect state until the sale ends (PaymentAttempt::has_in_flight_attempts() reads
 * any_live()). Each marker is its own option and nothing indexes them: two tills never overwrite
 * each other's, and any_live() finds every marker by its option-name prefix. A marker goes stale
 * after thirty minutes, well past Free's deadline, so none holds for ever; stale ones are deleted
 * as any_live() passes them.
 */
final class Sale_Guard
{
    public const OPTION_PREFIX = 'patwc_pro_sale_';
    public const STALE_AFTER_SECONDS = 1800;

    /**
     * @param array<string, mixed> $payload The sale as sent.
     */
    public static function hold(string $row_id, int $order_id, array $payload, string $trace_id = ''): void
    {
        update_option(self::option($row_id), array('order_id' => $order_id, 'trace_id' => $trace_id, 'payload' => $payload, 'updated_at' => time()), false);
    }

    /**
     * The marker for a row, or null when none is held (or it went stale).
     *
     * @return array<string, mixed>|null
     */
    public static function held(string $row_id): ?array
    {
        if (!function_exists('get_option')) {
            return null;
        }

        return self::fresh(get_option(self::option($row_id), null));
    }

    public static function release(string $row_id): void
    {
        delete_option(self::option($row_id));
    }

    /** Whether any Pro sale may still be on a terminal. */
    public static function any_live(): bool
    {
        $live = false;
        foreach (self::option_names() as $name) {
            if (self::fresh(get_option($name, null)) !== null) {
                $live = true;
                continue;
            }
            delete_option($name); // Stale, or not a marker at all: housekeeping on the way past.
        }

        return $live;
    }

    private static function option($row_id): string
    {
        return self::OPTION_PREFIX . md5((string) $row_id);
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>|null
     */
    private static function fresh($entry): ?array
    {
        if (!is_array($entry) || (int) ($entry['updated_at'] ?? 0) < time() - self::STALE_AFTER_SECONDS) {
            return null;
        }

        return $entry;
    }

    /**
     * Every marker's option name, straight from the options table (option_name is indexed).
     *
     * @return string[]
     */
    private static function option_names(): array
    {
        global $wpdb;
        if (!function_exists('get_option') || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return array();
        }
        $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like(self::OPTION_PREFIX) : self::OPTION_PREFIX;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like . '%'));

        return is_array($names) ? array_map('strval', $names) : array();
    }
}
