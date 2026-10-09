<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

/**
 * One marker per Pro sale that may be on a terminal: what the sale sent (so Free's replay of a lost
 * answer is the byte-identical command under the same key), and a hold on the gateway's mode,
 * credentials and Connect state until the sale ends (PaymentAttempt::has_in_flight_attempts() reads
 * any_live()). Each marker is its own option and nothing indexes them: two tills never overwrite
 * each other's, and any_live() finds every marker by its option-name prefix in the table itself. A marker goes stale
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
        global $wpdb;
        if (!function_exists('get_option')) {
            return null;
        }
        // From the table when there is one: a persistent object cache can list a marker another process
        // just wrote as missing, and a replay must find its own command.
        if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option($row_id)));
            if ($raw !== null) {
                return self::fresh(is_serialized((string) $raw) ? @unserialize((string) $raw) : $raw);
            }
        }

        return self::fresh(get_option(self::option($row_id), null));
    }

    public static function release(string $row_id): void
    {
        delete_option(self::option($row_id));
    }

    /**
     * Whether any Pro sale may still be on a terminal.
     *
     * Judged from the options table itself, never through get_option(): a persistent object cache
     * can list a marker as missing for a moment after it was written. A row is deleted only when
     * the value read from the table proved stale, and only if it is still that value. When the
     * table cannot be read (wpdb answers with an empty array and last_error), a sale is assumed
     * live: the guard fails closed.
     */
    public static function any_live(): bool
    {
        global $wpdb;
        if (!function_exists('get_option') || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return false; // Plain PHP, no WordPress: nothing can be on a terminal.
        }
        $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like(self::OPTION_PREFIX) : self::OPTION_PREFIX;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like . '%'), 'ARRAY_A');
        // wpdb answers a failed SELECT with an empty array and sets last_error: unreadable counts as live.
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            return true;
        }
        $live = false;
        foreach ($rows as $row) {
            $raw = (string) ($row['option_value'] ?? '');
            $value = is_serialized($raw) ? @unserialize($raw) : $raw;
            if (self::fresh($value) !== null) {
                $live = true;
                continue;
            }
            // Stale: housekeeping on the way past, and only if the row is still what was read.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", (string) $row['option_name'], $raw));
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete((string) $row['option_name'], 'options');
                wp_cache_delete('notoptions', 'options');
            }
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
}
