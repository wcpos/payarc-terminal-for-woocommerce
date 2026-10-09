<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;

/**
 * Fold sales the old order-pay panel left mid-flight into WCPOS Pro's ledger.
 *
 * One pass on upgrade, 25 orders per request, from a snapshot of order ids taken when the pass
 * begins, and again for one order whenever Pro's panel renders it. The action reference Pro's
 * provider polls and cancels is PayArc's traceId, so an adopted attempt needs nothing beyond the
 * ledger row: Pro reads the sale from PayArc (APPROVED settles it, TIMEOUT expires it, ABORTED
 * cancels it). Pro owns the sale while its row is live; once Pro's leg has ended without money the
 * old callback route acts on the sale again, as before.
 *
 * The old plugin has no sweeper: nothing reads an abandoned attempt except a poll or the callback.
 * So a pointer the in-flight guard no longer believes (older than its thirty-minute window, which
 * is also the window within which the mode and credentials cannot change) is read from PayArc once
 * here: a final answer settles it through the old reconciler, a sale still on the terminal is
 * adopted, and a sale PayArc cannot see under the current credentials holds the panel back. It is
 * never adopted: Pro's adopted row carries a five-minute deadline, and the adapter's cancel of a sale
 * PayArc cannot find answers "requested" for ever.
 */
final class Legacy_Adoption
{
    /** The version that introduced adoption; its pass runs once, and this marks it done. */
    public const VERSION = '1.0.0';

    /** Candidates per `init` request; keeps the request that triggers it short. */
    public const PAGE_SIZE = 25;

    /**
     * Order statuses that still need payment: WooCommerce's own two plus the two Free adds (POS
     * open and partially paid orders). `needs_payment()` on the rows read stays the truth.
     */
    public const UNPAID_STATUSES = array('pending', 'failed', 'pos-open', 'pos-partial');

    /** The traceId Pro adopted, kept on the order for good. */
    public const META_ADOPTED = '_patwc_adopted_ref';

    /** Pro's name for this adapter (PayArc_Server_Provider::PROVIDER), without loading the adapter here. */
    private const PROVIDER = 'payarc';

    private const QUEUE_OPTION = 'patwc_adoption_queue';
    private const VERSION_OPTION = 'patwc_adoption_version';
    private const LEDGER = '\\WCPOS\\WooCommercePOS\\Payments\\Contract\\Ledger';
    private const ORDER_LOCK = '\\WCPOS\\WooCommercePOS\\Payments\\Contract\\Order_Lock';

    /** @var callable|null Reads a transaction from PayArc for a stale pointer; tests inject one. */
    public static $reader = null;

    /**
     * The action reference Pro's provider uses for the attempt the old panel started: the order's
     * current traceId while the attempt is still live. '' for a final attempt, none, or one PayArc
     * never answered (no traceId: nothing to poll).
     *
     * @param object $order
     */
    public static function action_ref($order): string
    {
        $attempt = PaymentAttempt::current($order);
        $trace = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';

        return $trace !== '' && self::is_live_status((string) ($attempt['status'] ?? '')) ? $trace : '';
    }

    /**
     * Whether the old panel still thinks an attempt live on this order, with or without a traceId.
     *
     * @param object $order
     */
    public static function has_live_pointer($order): bool
    {
        $attempt = PaymentAttempt::current($order);
        foreach (array('trace_id', 'transaction_id') as $key) {
            if (isset($attempt[$key]) && is_scalar($attempt[$key]) && trim((string) $attempt[$key]) !== '') {
                return self::is_live_status((string) ($attempt['status'] ?? ''));
            }
        }

        return false;
    }

    /**
     * Whether Pro adopted this sale from the old panel (its record exists, whatever became of the leg).
     */
    public static function is_adopted(string $ref): bool
    {
        return $ref !== '' && function_exists('wcpos_pro_payment_id_for_action') && wcpos_pro_payment_id_for_action(self::PROVIDER, $ref) !== null;
    }

    /**
     * Whether Pro owns this sale now: adopted, and its ledger row still live (pending, authorized or
     * captured). Once Pro's leg has ended without money the old callback route acts on the sale
     * again, as before adoption. The adoption record itself is never cleared; the row's status is
     * the truth. A record whose row cannot be read counts as owned.
     *
     * @param object $order
     */
    public static function owned_by_pro($order, string $ref): bool
    {
        if (!self::is_adopted($ref)) {
            return false;
        }
        if (!class_exists(self::LEDGER)) {
            return true;
        }
        $ledger = self::LEDGER;
        $row = $ledger::instance()->find($order, (string) wcpos_pro_payment_id_for_action(self::PROVIDER, $ref));

        return $row === null || in_array($row['status'] ?? '', $ledger::LIVE_STATUSES, true);
    }

    /**
     * Whether Pro captured an adopted sale of this order: its row is `captured`, so Pro settled the
     * money and the old attempt is finished too.
     *
     * @param object $order
     */
    public static function captured_by_pro($order, string $ref): bool
    {
        if ($ref === '' || !function_exists('wcpos_pro_payment_id_for_action') || !class_exists(self::LEDGER)) {
            return false;
        }
        $payment_id = wcpos_pro_payment_id_for_action(self::PROVIDER, $ref);
        if ($payment_id === null) {
            return false;
        }
        $ledger = self::LEDGER;
        $row = $ledger::instance()->find($order, (string) $payment_id);

        return ($row['status'] ?? '') === 'captured';
    }

    /**
     * Whether Pro's ledger holds a live row (pending, authorized or captured) for this gateway on
     * the order: a leg Pro is driving now, adopted or its own. While one exists the old panel must
     * not start a sale beside it.
     *
     * @param object $order
     */
    public static function pro_has_live_row($order): bool
    {
        if (!class_exists(self::LEDGER)) {
            return false;
        }
        $ledger = self::LEDGER;
        foreach ($ledger::instance()->read($order) as $row) {
            if (($row['method_id'] ?? null) === Settings::GATEWAY_ID && in_array($row['status'] ?? '', $ledger::LIVE_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Pro owns the attempt on this order: by its current traceId, or by the reference kept
     * at adoption, which outlives the current pointer.
     *
     * @param object $order
     */
    public static function owns_order($order): bool
    {
        if (!function_exists('wcpos_pro_payment_id_for_action')) {
            return false;
        }
        $attempt = PaymentAttempt::current($order);
        $trace = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';

        return self::owned_by_pro($order, $trace) || self::owned_by_pro($order, (string) $order->get_meta(self::META_ADOPTED, true));
    }

    /**
     * Run the next page of adoption, until every candidate snapshotted at the start has been seen.
     */
    public static function upgrade(): void
    {
        if (!function_exists('get_option') || !function_exists('wc_get_orders') || version_compare((string) get_option(self::VERSION_OPTION, '0'), self::VERSION, '>=') || !Settings::uses_pro_panel()) {
            return;
        }
        // The candidates are snapshotted once, as order ids, when the pass begins: every order still
        // waiting for payment that carries an attempt. Ids only, so the one request that takes the
        // snapshot loads no order objects; each order is read, and judged, on its own page below.
        // Paging a live filter by offset would skip rows as callbacks move orders out of it, and an
        // attempt the old panel starts later is never a candidate (under Pro's panel it can start none).
        $queue = get_option(self::QUEUE_OPTION, null);
        if (!is_array($queue)) {
            $ids = wc_get_orders(array(
                'type' => 'shop_order',
                'status' => self::UNPAID_STATUSES,
                'limit' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'return' => 'ids',
                // The shortcut both order stores honour; `meta_query` is dropped by the posts store.
                'meta_key' => PaymentAttempt::META_CURRENT_ATTEMPT,
                'meta_compare' => 'EXISTS',
            ));
            $queue = array_fill_keys(array_map('intval', is_array($ids) ? $ids : array()), 1);
            update_option(self::QUEUE_OPTION, $queue, false);
        }
        foreach (array_slice($queue, 0, self::PAGE_SIZE, true) as $order_id => $unused) {
            $result = self::adopt_order((int) $order_id);
            if (is_wp_error($result) && self::is_deferral($result)) {
                // A held lock or an unanswered read is a passing state: the order stays in the queue.
                Logger::log('Legacy PayArc adoption deferred', array('order_id' => (int) $order_id, 'code' => $result->get_error_code()));
                continue;
            }
            if (is_wp_error($result)) {
                Logger::log('Legacy PayArc adoption refused', array('order_id' => (int) $order_id, 'code' => $result->get_error_code()), null, 'warning');
            }
            unset($queue[$order_id]);
        }
        update_option(self::QUEUE_OPTION, $queue, false);
        if ($queue === array()) {
            delete_option(self::QUEUE_OPTION);
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }

    /**
     * Whether a refusal is a passing one (a till at work, a start PayArc has yet to answer, a read
     * PayArc did not answer), to be retried, rather than final.
     */
    public static function is_deferral(\WP_Error $error): bool
    {
        return in_array($error->get_error_code(), array('wcpos_payment_locked', 'patwc_adoption_no_lock', 'patwc_adoption_completing', 'patwc_adoption_awaiting_trace', 'patwc_adoption_unanswered'), true);
    }

    /**
     * Adopt the order's live sale, if it has one Pro does not own yet: under Free's per-order lock,
     * on a fresh read, and under the old paths' own per-order locks (the start/cancel lock and the
     * reconciliation lock, so no old request completes or cancels the sale while it changes hands).
     * Run by the upgrade pass for each snapshotted order, and by Pro's panel before it renders, so
     * an attempt the pass has not reached yet is Pro's before the page can offer a second charge.
     *
     * @return array|null|\WP_Error The row, null when nothing applied, or a refusal.
     */
    public static function adopt_order(int $order_id)
    {
        // Nothing to adopt (no live attempt, or one Pro already has) is the common page load: answer
        // without taking the order lock, which a till may hold for a moment.
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order) {
            return null;
        }
        if (!self::has_live_pointer($order) || self::is_adopted(self::action_ref($order)) || $order->is_paid() || !$order->needs_payment()) {
            return null;
        }
        if (!class_exists(self::ORDER_LOCK)) {
            return new \WP_Error('patwc_adoption_no_lock', 'The POS order lock is unavailable.');
        }
        $lock = self::ORDER_LOCK;

        return $lock::instance()->with_lock($order_id, static function () use ($order_id) {
            if (!PaymentLock::acquire($order_id, 'terminal')) {
                return new \WP_Error('patwc_adoption_completing', 'A start or cancel of this payment is in progress.');
            }
            if (!PaymentLock::acquire($order_id, 'reconcile')) {
                PaymentLock::release($order_id, 'terminal');

                return new \WP_Error('patwc_adoption_completing', 'A completion of this payment is in progress.');
            }
            try {
                // Read the order under every lock, caches cleared, and judge that copy: a completion
                // that held the old locks a moment ago has written its result by now.
                $fresh = PaymentReconciler::reload_order(wc_get_order($order_id));

                return is_object($fresh) ? self::judge($fresh) : null;
            } catch (Throwable $e) {
                return new \WP_Error('patwc_adoption_failed', $e->getMessage());
            } finally {
                PaymentLock::release($order_id, 'reconcile');
                PaymentLock::release($order_id, 'terminal');
            }
        });
    }

    /**
     * The decision for one order, read fresh under the locks.
     *
     * @param object $fresh
     * @return array|null|\WP_Error
     */
    private static function judge($fresh)
    {
        if ($fresh->is_paid() || !$fresh->needs_payment() || !self::has_live_pointer($fresh)) {
            return null;
        }
        $trace = self::action_ref($fresh);
        $believed = PaymentAttempt::is_in_flight($fresh); // The in-flight guard still counts it: under thirty minutes old.
        if ($trace === '') {
            if ($believed) {
                // PayArc has not answered the start: the old panel's own poll says "pending callback". A
                // sale may be on the terminal under a key nothing here can replay; wait it out.
                return new \WP_Error('patwc_adoption_awaiting_trace', 'PayArc has not confirmed the start of this payment yet.');
            }
            // Never answered, and older than any terminal sale lives: nothing is known to have been sent.
            PaymentAttempt::update_status($fresh, 'failure', array('message' => 'PayArc never confirmed the start of this payment.'));
            $fresh->add_order_note('An earlier PayArc Terminal attempt on this order was never confirmed by PayArc and is closed; nothing is known to have been charged. Check the PayArc dashboard before taking payment again.');
            $fresh->save();

            return null;
        }
        if (self::is_adopted($trace)) {
            return null;
        }
        if (!$believed) {
            // Older than the guard's window: the mode or credentials may have changed since. One read
            // decides; a sale PayArc cannot see is never adopted (see the class comment).
            try {
                $transaction = self::read($trace);
            } catch (PayArcRequestException $e) {
                if ($e->payarc_code() === 'TRANSACTION_NOT_FOUND') {
                    return new \WP_Error('patwc_adoption_stale_attempt', 'An earlier PayArc sale on this order is not visible under the current PayArc credentials.');
                }

                return new \WP_Error('patwc_adoption_unanswered', $e->getMessage());
            } catch (Throwable $e) {
                return new \WP_Error('patwc_adoption_unanswered', $e->getMessage());
            }
            $status = PaymentAttempt::normalize_status(isset($transaction['status']) && is_scalar($transaction['status']) ? (string) $transaction['status'] : '');
            if ($status === 'success' || PaymentAttempt::is_final_unpaid($status)) {
                // Decided on the terminal long ago: the old reconciler settles it (paid, or the failure
                // recorded), under its own completion claim. Nothing is adopted.
                (new PaymentReconciler())->reconcile($fresh, $transaction, 'poll');

                return null;
            }
        }
        $row = wcpos_pro_adopt_legacy_attempt($fresh, Settings::GATEWAY_ID, $trace, (string) $fresh->get_total(), (string) $fresh->get_currency());
        if (is_array($row)) {
            $fresh->update_meta_data(self::META_ADOPTED, $trace);
            $fresh->save();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $trace): array
    {
        $reader = self::$reader;
        if ($reader === null) {
            $reader = static function (string $trace): array {
                return (new PayArcClient(new Settings()))->get_transaction($trace);
            };
        }
        $transaction = $reader($trace);

        return is_array($transaction) ? $transaction : array();
    }

    private static function is_live_status(string $status): bool
    {
        return PaymentAttempt::is_non_final($status) || PaymentAttempt::normalize_status($status) === 'cancel_requested';
    }
}
