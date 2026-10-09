<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

/**
 * One marker per Pro sale that may be on a terminal: what the sale sent (so Free's replay of a lost
 * answer is the byte-identical command under the same key), and a hold on the gateway's mode,
 * credentials and Connect state until the sale ends (PaymentAttempt::has_in_flight_attempts() reads
 * any_live()). Each marker is its own option, so two tills never overwrite each other's; a small
 * index of row ids serves any_live() and is only ever a hint. A marker goes stale after thirty
 * minutes, well past Free's deadline, so none holds for ever.
 */
final class Sale_Guard
{
    public const OPTION_PREFIX = 'patwc_pro_sale_';
    public const INDEX_OPTION = 'patwc_pro_sales_in_flight';
    public const STALE_AFTER_SECONDS = 1800;

    /**
     * @param array<string, mixed> $payload The sale as sent.
     */
    public static function hold(string $row_id, int $order_id, array $payload, string $trace_id = ''): void
    {
        update_option(self::option($row_id), array('order_id' => $order_id, 'trace_id' => $trace_id, 'payload' => $payload, 'updated_at' => time()), false);
        $index = self::index();
        $index[$row_id] = time();
        update_option(self::INDEX_OPTION, $index, false);
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
        $entry = get_option(self::option($row_id), null);
        if (!is_array($entry) || (int) ($entry['updated_at'] ?? 0) < time() - self::STALE_AFTER_SECONDS) {
            return null;
        }

        return $entry;
    }

    public static function release(string $row_id): void
    {
        delete_option(self::option($row_id));
        $index = self::index();
        if (isset($index[$row_id])) {
            unset($index[$row_id]);
            update_option(self::INDEX_OPTION, $index, false);
        }
    }

    /** Whether any Pro sale may still be on a terminal. */
    public static function any_live(): bool
    {
        foreach (array_keys(self::index()) as $row_id) {
            if (self::held((string) $row_id) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function option(string $row_id): string
    {
        return self::OPTION_PREFIX . md5($row_id);
    }

    /**
     * @return array<string, int>
     */
    private static function index(): array
    {
        if (!function_exists('get_option')) {
            return array();
        }
        $index = get_option(self::INDEX_OPTION, array());
        $index = is_array($index) ? $index : array();
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach ($index as $row_id => $at) {
            if ((int) $at < $cutoff) {
                unset($index[$row_id]);
            }
        }

        return $index;
    }
}
