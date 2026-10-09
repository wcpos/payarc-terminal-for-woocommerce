<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Logger;
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
    /** The attempt id, PayArc's idempotency key for this record's refund; written before the POST. */
    public const META_ATTEMPT = '_patwc_refund_attempt';
    /** The request replayed on each ask. */
    public const META_REQUEST = '_patwc_refund_request';
    /** The PayArc traceId once PayArc accepted the refund command. */
    public const META_TRACE = '_patwc_refund_trace_id';

    public static function register(): void
    {
        add_action(self::HOOK, array(__CLASS__, 'run'), 10, 3);
    }

    /**
     * The record's attempt id, minted and saved before the first POST so a replay reuses it.
     *
     * @param object $refund
     * @param array<string, mixed> $request
     */
    public static function attempt_key($refund, array $request): string
    {
        $key = (string) $refund->get_meta(self::META_ATTEMPT, true);
        if ($key === '') {
            $key = wp_generate_uuid4();
            $refund->update_meta_data(self::META_ATTEMPT, $key);
            $refund->update_meta_data(self::META_REQUEST, $request);
            $refund->save();
        }

        return $key;
    }

    /**
     * The POST itself went unanswered: note the order and schedule the first ask.
     *
     * @param object $order
     */
    public static function unanswered($order, int $refund_id): void
    {
        $order->add_order_note(sprintf(
            /* translators: %d: refund id. */
            __('PayArc did not confirm refund #%d. The refund is being checked again; do not refund it a second time.', 'payarc-terminal-for-woocommerce'),
            $refund_id
        ));
        $order->save();
        self::schedule($refund_id, 1, (int) $order->get_id());
    }

    private static function schedule(int $refund_id, int $try, int $order_id): void
    {
        wp_schedule_single_event(time() + self::DELAY, self::HOOK, array($refund_id, $try, $order_id));
    }

    /**
     * Replay the refund under its saved key and record PayArc's answer.
     *
     * @param PayArc_Server_Provider|null $adapter Adapter override for tests.
     */
    public static function run(int $refund_id, int $try = 1, int $order_id = 0, $adapter = null): void
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
        if (!$order || (string) $refund->get_meta(self::META_TRACE, true) !== '') {
            return;
        }
        $key = (string) $refund->get_meta(self::META_ATTEMPT, true);
        $request = (array) $refund->get_meta(self::META_REQUEST, true);
        if ($key === '' || empty($request['originalTransactionId'])) {
            return;
        }
        $adapter = $adapter === null ? new PayArc_Server_Provider() : $adapter;
        try {
            $trace_id = $adapter->refund_once($request, $key);
        } catch (PayArcRequestException $e) {
            if (PayArc_Server_Provider::unanswered($e)) {
                self::again($order, $refund_id, $try);
                return;
            }
            // Refused: had the first request been accepted, the identical replay would have been answered
            // with its traceId instead. No refund command reached the terminal.
            $order->add_order_note(sprintf(
                /* translators: 1: refund id, 2: PayArc error code. */
                __('PayArc refused refund #%1$d: %2$s. No refund was sent to the terminal. The record still counts as refunded here: delete it, then refund from the PayArc dashboard or the terminal if the money is owed.', 'payarc-terminal-for-woocommerce'),
                $refund_id,
                $e->payarc_code() !== '' ? $e->payarc_code() : (string) $e->http_status()
            ));
            $order->save();
            return;
        } catch (Throwable $e) {
            self::again($order, $refund_id, $try);
            return;
        }
        $refund->update_meta_data(self::META_TRACE, $trace_id);
        $refund->save();
        /* translators: 1: refund id, 2: PayArc traceId. */
        $order->add_order_note(sprintf(__('PayArc accepted refund #%1$d (trace %2$s); the terminal reports its outcome.', 'payarc-terminal-for-woocommerce'), $refund_id, $trace_id));
        $order->save();
    }

    /**
     * @param object $order
     */
    private static function again($order, int $refund_id, int $try): void
    {
        if ($try < self::LIMIT) {
            self::schedule($refund_id, $try + 1, (int) $order->get_id());
            return;
        }
        Logger::log('PayArc never answered a refund; staff asked to check the dashboard', array('refund_id' => $refund_id), null, 'warning');
        /* translators: %d: refund id. */
        $order->add_order_note(sprintf(__('PayArc has not confirmed refund #%d. Check the PayArc dashboard: if the refund is there, nothing more is needed; if not, delete this refund record and refund from the dashboard or the terminal.', 'payarc-terminal-for-woocommerce'), $refund_id));
        $order->save();
    }
}
