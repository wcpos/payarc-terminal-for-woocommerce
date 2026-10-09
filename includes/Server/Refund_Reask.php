<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Logger;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcNotSentException;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;

/**
 * A refund POST PayArc did not answer is asked about again.
 *
 * An error from the adapter's refund() makes WooCommerce delete the refund record, and a later
 * refund is a new record under a new idempotency key: a second refund command to the terminal if
 * the first POST did reach PayArc. So the record keeps an attempt id, saved BEFORE the POST and
 * sent as X-Idempotency-Key; when the POST goes unanswered the record stands as pending, the order
 * says so, and this cron replays the identical request under the same key (PayArc: "reuse the same
 * key only when retrying the same payload") until PayArc answers with the refund's traceId or the
 * tries run out with a note for staff. The refund's outcome itself arrives on the callback.
 */
final class Refund_Reask
{
    public const HOOK = 'patwc_reask_refund';
    /** Seconds between asks. */
    public const DELAY = 120;
    /** Silent tries before staff are told to check the PayArc dashboard (about ten minutes). */
    public const LIMIT = 5;
    /**
     * The attempt id, PayArc's idempotency key for this record's refund of ONE payment row; written before
     * the POST. A refund split across two payments is two commands with two keys, so every key below is
     * suffixed with the row id Pro passes (`:<row id>`).
     */
    public const META_ATTEMPT = '_patwc_refund_attempt';
    /** The request replayed on each ask. */
    public const META_REQUEST = '_patwc_refund_request';
    /** The PayArc traceId once PayArc accepted the refund command. */
    public const META_TRACE = '_patwc_refund_trace_id';

    /** A record's meta key for one payment row. */
    public static function key(string $base, string $row_id): string
    {
        return $base . ':' . $row_id;
    }

    public static function register(): void
    {
        add_action(self::HOOK, array(__CLASS__, 'run'), 10, 4);
    }

    /**
     * The record's attempt id, minted and saved before the first POST so a replay reuses it.
     *
     * @param object $refund
     * @param array<string, mixed> $request
     */
    public static function attempt_key($refund, string $row_id, array $request): string
    {
        $key = (string) $refund->get_meta(self::key(self::META_ATTEMPT, $row_id), true);
        if ($key === '') {
            $key = wp_generate_uuid4();
            $refund->update_meta_data(self::key(self::META_ATTEMPT, $row_id), $key);
            $refund->update_meta_data(self::key(self::META_REQUEST, $row_id), $request);
            $refund->save();
        }

        return $key;
    }

    /**
     * The POST itself went unanswered: note the order and schedule the first ask.
     *
     * @param object $order
     */
    public static function unanswered($order, int $refund_id, string $row_id): void
    {
        $order->add_order_note(sprintf(
            /* translators: %d: refund id. */
            __('PayArc did not confirm refund #%d. The refund is being checked again; do not refund it a second time.', 'payarc-terminal-for-woocommerce'),
            $refund_id
        ));
        $order->save();
        self::schedule($refund_id, 1, (int) $order->get_id(), $row_id);
    }

    private static function schedule(int $refund_id, int $try, int $order_id, string $row_id): void
    {
        wp_schedule_single_event(time() + self::DELAY, self::HOOK, array($refund_id, $try, $order_id, $row_id));
    }

    /**
     * Replay the refund under its saved key and record PayArc's answer.
     *
     * @param PayArc_Server_Provider|null $adapter Adapter override for tests.
     */
    public static function run(int $refund_id, int $try = 1, int $order_id = 0, string $row_id = '', $adapter = null): void
    {
        $refund = wc_get_order($refund_id);
        if (!$refund instanceof \WC_Order_Refund) {
            $order = $order_id ? wc_get_order($order_id) : null;
            if ($order) {
                /* translators: %d: refund id. */
                $order->add_order_note(sprintf(__('Refund #%d was deleted before PayArc confirmed it. Check the PayArc dashboard: the refund may have reached the terminal.', 'payarc-terminal-for-woocommerce'), $refund_id));
                $order->save();
            }
            return;
        }
        $order = wc_get_order((int) $refund->get_parent_id());
        if (!$order || (string) $refund->get_meta(self::key(self::META_TRACE, $row_id), true) !== '') {
            return;
        }
        $key = (string) $refund->get_meta(self::key(self::META_ATTEMPT, $row_id), true);
        $request = (array) $refund->get_meta(self::key(self::META_REQUEST, $row_id), true);
        if ($key === '' || empty($request['originalTransactionId'])) {
            return;
        }
        $adapter = $adapter === null ? new PayArc_Server_Provider() : $adapter;
        try {
            $trace_id = $adapter->refund_once($request, $key);
        } catch (PayArcNotSentException $e) {
            // Nothing left the server (credentials, mode or Connect state): ask again later, when it may.
            self::again($order, $refund_id, $try, $row_id);
            return;
        } catch (PayArcRequestException $e) {
            // Unanswered, or a conflict on the key: a command under this key may exist; the question stays open.
            if (PayArc_Server_Provider::unanswered($e) || PayArc_Server_Provider::idempotency_conflict($e)) {
                self::again($order, $refund_id, $try, $row_id);
                return;
            }
            // Refused: had the first request been accepted, the identical replay would have been answered
            // with its traceId instead. No refund command reached the terminal.
            $order->add_order_note(self::part_not_returned_note($refund_id, $row_id, $refund, sprintf(
                /* translators: %s: PayArc error code. */
                __('PayArc refused it (%s), so no command reached the terminal', 'payarc-terminal-for-woocommerce'),
                $e->payarc_code() !== '' ? $e->payarc_code() : (string) $e->http_status()
            )));
            $order->save();
            return;
        } catch (Throwable $e) {
            self::again($order, $refund_id, $try, $row_id);
            return;
        }
        $refund->update_meta_data(self::key(self::META_TRACE, $row_id), $trace_id);
        $refund->save();
        /* translators: 1: refund id, 2: PayArc traceId. */
        $order->add_order_note(sprintf(__('PayArc accepted refund #%1$d (trace %2$s); the terminal reports its outcome.', 'payarc-terminal-for-woocommerce'), $refund_id, $trace_id));
        $order->save();
    }

    /**
     * @param object $order
     */
    private static function again($order, int $refund_id, int $try, string $row_id): void
    {
        if ($try < self::LIMIT) {
            self::schedule($refund_id, $try + 1, (int) $order->get_id(), $row_id);
            return;
        }
        Logger::log('PayArc never answered a refund; staff asked to check the dashboard', array('refund_id' => $refund_id), null, 'warning');
        $refund = wc_get_order($refund_id);
        $order->add_order_note(sprintf(
            /* translators: 1: the refund part, e.g. "refund #12 (92.95 USD)"; 2: the record's other parts, or empty. */
            __('PayArc has not confirmed %1$s. Check the PayArc dashboard: if that refund is there, nothing more is needed; if not, that part was never returned.%2$s', 'payarc-terminal-for-woocommerce'),
            self::part_label($refund_id, $row_id, $refund),
            self::other_parts_hint($row_id, $refund)
        ));
        $order->save();
    }

    /**
     * The note for one part of a refund the terminal did not return: which part, why, and what the record
     * now overstates. A refund of a split payment is one WooCommerce record with a command per payment row,
     * so the advice never says to delete a record another part of which was returned.
     *
     * @param object|null $refund
     */
    public static function part_not_returned_note(int $refund_id, string $row_id, $refund, string $why): string
    {
        return sprintf(
            /* translators: 1: the refund part, e.g. "refund #12 (92.95 USD)"; 2: why, e.g. "PayArc reports it as DECLINE"; 3: the record's other parts, or empty. */
            __('%1$s was not returned: %2$s. The record still counts it as refunded here.%3$s', 'payarc-terminal-for-woocommerce'),
            self::part_label($refund_id, $row_id, $refund),
            $why,
            self::other_parts_hint($row_id, $refund)
        );
    }

    /**
     * "refund #12 (92.95 USD, trace abc)" from the command saved for the part, or "refund #12" when no command was saved.
     *
     * @param object|null $refund
     */
    private static function part_label(int $refund_id, string $row_id, $refund): string
    {
        $request = is_object($refund) ? (array) $refund->get_meta(self::key(self::META_REQUEST, $row_id), true) : array();
        $amount = isset($request['amount']['total'], $request['amount']['currency']) ? \WCPOS\WooCommercePOSPro\Payments\Server\Money_Units::major((int) $request['amount']['total'], (string) $request['amount']['currency']) . ' ' . $request['amount']['currency'] : '';
        $trace = is_object($refund) ? (string) $refund->get_meta(self::key(self::META_TRACE, $row_id), true) : '';
        $detail = implode(', ', array_filter(array($amount, $trace !== '' ? 'trace ' . $trace : '')));

        if ($detail === '') {
            /* translators: %d: refund id. */
            return sprintf(__('refund #%d', 'payarc-terminal-for-woocommerce'), $refund_id);
        }

        /* translators: 1: refund id, 2: amount and trace, e.g. "92.95 USD, trace abc". */
        return sprintf(__('refund #%1$d (%2$s)', 'payarc-terminal-for-woocommerce'), $refund_id, $detail);
    }

    /**
     * The advice. "Delete the record" only when this part is the whole record: a refund of a split payment is
     * one record whose other parts (another PayArc row, cash, any manual method) may have been returned, and
     * PayArc's own meta cannot see a cash part, so the record's amount decides.
     *
     * @param object|null $refund
     */
    private static function other_parts_hint(string $row_id, $refund): string
    {
        $request = is_object($refund) ? (array) $refund->get_meta(self::key(self::META_REQUEST, $row_id), true) : array();
        $whole = false;
        if (is_object($refund) && method_exists($refund, 'get_amount') && isset($request['amount']['total'], $request['amount']['currency'])) {
            try {
                $whole = \WCPOS\WooCommercePOSPro\Payments\Server\Money_Units::minor((string) $refund->get_amount(), (string) $request['amount']['currency']) === (int) $request['amount']['total'];
            } catch (Throwable $e) {
                $whole = false;
            }
        }
        if ($whole) {
            return ' ' . __('Delete the record, then refund from the PayArc dashboard or the terminal if the money is owed.', 'payarc-terminal-for-woocommerce');
        }

        return ' ' . __('Other parts of this refund went to other payments and may have been returned: keep the record, note the amount not returned, and refund that amount from the PayArc dashboard or the terminal if it is owed.', 'payarc-terminal-for-woocommerce');
    }
}
