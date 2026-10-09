<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

/**
 * One marker per Pro sale that may be on a terminal: what the sale sent (so Free's replay of a lost
 * answer is the byte-identical command under the same key), and a hold on the gateway's mode,
 * credentials and Connect state until the sale ends (PaymentAttempt::has_in_flight_attempts() reads
 * any_live()). A marker goes stale after thirty minutes, well past Free's deadline, so none holds
 * for ever.
 */
final class Sale_Guard
{
    public const OPTION = 'patwc_pro_sales_in_flight';
    public const STALE_AFTER_SECONDS = 1800;

    /**
     * @param array<string, mixed> $payload The sale as sent.
     */
    public static function hold(string $row_id, int $order_id, array $payload, string $trace_id = ''): void
    {
        $index = self::index();
        $index[$row_id] = array('order_id' => $order_id, 'trace_id' => $trace_id, 'payload' => $payload, 'updated_at' => time());
        update_option(self::OPTION, $index, false);
    }

    /**
     * The marker for a row, or null when none is held (or it went stale).
     *
     * @return array<string, mixed>|null
     */
    public static function held(string $row_id): ?array
    {
        $index = self::index();

        return isset($index[$row_id]) ? $index[$row_id] : null;
    }

    public static function release(string $row_id): void
    {
        $index = self::index();
        if (!isset($index[$row_id])) {
            return;
        }
        unset($index[$row_id]);
        update_option(self::OPTION, $index, false);
    }

    /** Whether any Pro sale may still be on a terminal. */
    public static function any_live(): bool
    {
        return count(self::index()) > 0;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function index(): array
    {
        if (!function_exists('get_option')) {
            return array();
        }
        $index = get_option(self::OPTION, array());
        $index = is_array($index) ? $index : array();
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach ($index as $row_id => $entry) {
            if (!is_array($entry) || (int) ($entry['updated_at'] ?? 0) < $cutoff) {
                unset($index[$row_id]);
            }
        }

        return $index;
    }
}
