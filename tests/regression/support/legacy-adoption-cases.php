<?php

declare(strict_types=1);

require_once __DIR__ . '/pro-stubs.php';

use WCPOS\WooCommercePOS\PayArcTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

/** An order with the old panel's attempt on it; fresh = the in-flight guard still counts it. */
function order_with_attempt(int $id, array $attempt, bool $fresh = true): PatwcOrder
{
    $order = new PatwcOrder($id);
    $GLOBALS['orders'][$id] = $order;
    if ($attempt !== array()) {
        PaymentAttempt::record_new($order, $attempt);
        if (!$fresh) {
            $index = get_option(PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS, array());
            $index[(string) $id]['updated_at'] = time() - 1801;
            update_option(PaymentAttempt::OPTION_IN_FLIGHT_ATTEMPTS, $index);
        }
    }

    return $order;
}
function reader_returning($transaction): callable { return static function (string $trace) use ($transaction): array { $GLOBALS['reads'][] = $trace; if ($transaction instanceof Throwable) { throw $transaction; } return $transaction; }; }
$live = array('attempt_uuid' => 'u1', 'transaction_id' => 'P1ABCDEF12345678', 'terminal_id' => '1234567890', 'status' => 'processing', 'trace_id' => 'trace-1');

// Nothing to adopt answers without the locks: no attempt, a final attempt, a paid order, an adopted sale.
patwc_reset_world(); $GLOBALS['free_lock_held'] = true;
order_with_attempt(1, array());
check(Legacy_Adoption::adopt_order(1) === null && $GLOBALS['adoptions'] === array(), 'no attempt: nothing to adopt, no lock taken');
order_with_attempt(2, array('status' => 'decline', 'trace_id' => 'trace-old') + $live);
check(Legacy_Adoption::adopt_order(2) === null && Legacy_Adoption::action_ref($GLOBALS['orders'][2]) === '', 'a final attempt is not live');
$paid = order_with_attempt(3, $live); $paid->paid = true;
check(Legacy_Adoption::adopt_order(3) === null, 'a paid order has nothing to adopt');
$GLOBALS['adopted']['trace-1'] = 'row-elsewhere';
order_with_attempt(4, $live);
check(Legacy_Adoption::adopt_order(4) === null, 'an adopted sale is not adopted twice');
check(Legacy_Adoption::adopt_order(99) === null, 'a missing order is nothing');

// A fresh live sale becomes Pro's: the traceId is the action, the reference is kept, Pro owns the order.
patwc_reset_world();
$order = order_with_attempt(5, $live);
$row = Legacy_Adoption::adopt_order(5);
check(is_array($row) && $row['status'] === 'pending' && $row['provider_refs']['action'] === 'trace-1' && $row['amount'] === '92.95' && $row['currency'] === 'USD', 'a fresh live sale is adopted as a pending row on its traceId');
check($order->get_meta(Legacy_Adoption::META_ADOPTED) === 'trace-1' && ($GLOBALS['reads'] ?? array()) === array(), 'the adopted reference is kept on the order; a fresh pointer is not read from PayArc first');
check(Legacy_Adoption::is_adopted('trace-1') && Legacy_Adoption::owns_order($order) && Legacy_Adoption::pro_has_live_row($order) && !Legacy_Adoption::captured_by_pro($order, 'trace-1'), 'Pro owns the sale while its row is live');
check(Legacy_Adoption::adopt_order(5) === null && count($GLOBALS['adoptions']) === 1, 'a second pass adopts nothing more');
patwc_set_ledger_status(5, 'row-trace-1', 'captured');
check(Legacy_Adoption::owns_order($order) && Legacy_Adoption::captured_by_pro($order, 'trace-1'), 'a captured row is owned and captured');
patwc_set_ledger_status(5, 'row-trace-1', 'cancelled');
check(!Legacy_Adoption::owns_order($order) && !Legacy_Adoption::pro_has_live_row($order), 'once Pro\'s leg ended without money the old paths own the sale again');
$GLOBALS['ledger'][5] = array();
check(Legacy_Adoption::owns_order($order), 'an adoption record whose row cannot be read counts as owned');

// Locks: Free's held, or the old panel's start/cancel or reconciliation lock held, defer (never a final refusal).
patwc_reset_world(); $order = order_with_attempt(6, $live); $GLOBALS['free_lock_held'] = true;
$r = Legacy_Adoption::adopt_order(6);
check($r instanceof WP_Error && $r->get_error_code() === 'wcpos_payment_locked' && Legacy_Adoption::is_deferral($r) && $GLOBALS['adoptions'] === array(), 'Free\'s lock held: deferred');
$GLOBALS['free_lock_held'] = false;
check(PaymentLock::acquire(6, 'terminal'), 'the old start/cancel lock can be taken for the case');
$r = Legacy_Adoption::adopt_order(6);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_adoption_completing' && Legacy_Adoption::is_deferral($r), 'the old start/cancel lock held: deferred');
PaymentLock::release(6, 'terminal');
check(PaymentLock::acquire(6, 'reconcile'), 'the old reconciliation lock can be taken for the case');
$r = Legacy_Adoption::adopt_order(6);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_adoption_completing', 'the old reconciliation lock held: deferred');
PaymentLock::release(6, 'reconcile');
check(is_array(Legacy_Adoption::adopt_order(6)) && PaymentLock::acquire(6, 'terminal') && PaymentLock::acquire(6, 'reconcile'), 'with the locks free the sale is adopted and both old locks are released after');
PaymentLock::release(6, 'terminal'); PaymentLock::release(6, 'reconcile');
check(in_array(6, $GLOBALS['cache_cleared'], true), 'the order is judged on a fresh read under the locks');

// Pro refusing is final for the order: no record, no retry on every request.
patwc_reset_world(); $order = order_with_attempt(7, $live); $GLOBALS['pro_refuses_adoption'] = true;
$r = Legacy_Adoption::adopt_order(7);
check($r instanceof WP_Error && !Legacy_Adoption::is_deferral($r) && $order->get_meta(Legacy_Adoption::META_ADOPTED) === '', 'Pro\'s refusal is final and leaves no adoption record');

// A start PayArc never answered: waited out while the guard counts it, closed with a note after.
patwc_reset_world(); $order = order_with_attempt(8, array('status' => 'created', 'transaction_id' => 'P1ABCDEF12345678', 'attempt_uuid' => 'u8'));
$r = Legacy_Adoption::adopt_order(8);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_adoption_awaiting_trace' && Legacy_Adoption::is_deferral($r), 'a fresh trace-less start is waited out');
$order = order_with_attempt(9, array('status' => 'created', 'transaction_id' => 'P1ABCDEF12345679', 'attempt_uuid' => 'u9'), false);
check(Legacy_Adoption::adopt_order(9) === null && PaymentAttempt::current($order)['status'] === 'failure' && strpos($order->notes[0], 'never confirmed') !== false && !PaymentAttempt::is_in_flight($order), 'a stale trace-less start is closed with a note and the guard lets go');

// A stale pointer is read from PayArc once: final settles through the old reconciler, live is adopted, not found holds.
$approved = array('traceId' => 'trace-1', 'transactionId' => 'P1ABCDEF12345678', 'status' => 'APPROVED', 'chargeId' => 'ch_1', 'amount' => array('total' => 9295, 'approved' => 9295, 'currency' => 'USD'));
patwc_reset_world(); $order = order_with_attempt(10, $live, false); Legacy_Adoption::$reader = reader_returning(array('traceId' => 'trace-1', 'transactionId' => 'P1ABCDEF12345678', 'status' => 'DECLINE', 'processor' => array('responseText' => 'Do not honor')));
check(Legacy_Adoption::adopt_order(10) === null && $GLOBALS['reads'] === array('trace-1') && PaymentAttempt::current($order)['status'] === 'decline' && $GLOBALS['adoptions'] === array() && !$order->paid, 'a stale sale PayArc declined is settled by the old reconciler, not adopted');
patwc_reset_world(); $GLOBALS['reads'] = array(); $order = order_with_attempt(11, $live, false); Legacy_Adoption::$reader = reader_returning($approved);
check(Legacy_Adoption::adopt_order(11) === null && $order->paid && $order->completed_with === 'ch_1' && $GLOBALS['adoptions'] === array(), 'a stale sale PayArc approved pays the order through the old reconciler, not adopted');
patwc_reset_world(); $GLOBALS['reads'] = array(); $order = order_with_attempt(12, $live, false); Legacy_Adoption::$reader = reader_returning(array('traceId' => 'trace-1', 'status' => 'processing'));
check(is_array(Legacy_Adoption::adopt_order(12)) && $GLOBALS['reads'] === array('trace-1'), 'a stale sale still on the terminal is adopted');
patwc_reset_world(); $GLOBALS['reads'] = array(); $order = order_with_attempt(13, $live, false); Legacy_Adoption::$reader = reader_returning(new PayArcRequestException('nf', 'TRANSACTION_NOT_FOUND', 400));
$r = Legacy_Adoption::adopt_order(13);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_adoption_stale_attempt' && !Legacy_Adoption::is_deferral($r) && $GLOBALS['adoptions'] === array() && PaymentAttempt::current($order)['status'] === 'processing', 'a stale sale PayArc cannot see is held, never adopted, and left as it was');
patwc_reset_world(); $order = order_with_attempt(14, $live, false); Legacy_Adoption::$reader = reader_returning(new PayArcRequestException('down', 'SERVER_ERROR', 503));
$r = Legacy_Adoption::adopt_order(14);
check($r instanceof WP_Error && $r->get_error_code() === 'patwc_adoption_unanswered' && Legacy_Adoption::is_deferral($r), 'a read PayArc did not answer defers');

// The upgrade pass: a snapshot of unpaid orders carrying an attempt, pages of 25, deferrals stay queued, done once.
patwc_reset_world(); $GLOBALS['filters']['patwc_uses_pro_panel'] = false; $GLOBALS['order_ids'] = array(1);
Legacy_Adoption::upgrade();
check($GLOBALS['order_queries'] === array() && !isset($GLOBALS['options']['patwc_adoption_version']), 'without Pro\'s panel the pass does not run');
patwc_reset_world();
$ids = array();
for ($i = 101; $i <= 127; $i++) { order_with_attempt($i, array_merge($live, array('trace_id' => 'trace-' . $i))); $ids[] = $i; } // array_merge: a union would keep $live's trace
$GLOBALS['order_ids'] = $ids;
Legacy_Adoption::upgrade();
$q = $GLOBALS['order_queries'][0];
check($q['status'] === Legacy_Adoption::UNPAID_STATUSES && $q['return'] === 'ids' && $q['meta_key'] === PaymentAttempt::META_CURRENT_ATTEMPT && $q['meta_compare'] === 'EXISTS', 'the snapshot asks for unpaid orders carrying an attempt, as ids');
check(count($GLOBALS['adoptions']) === 25 && count($GLOBALS['options']['patwc_adoption_queue']) === 2, 'the first request adopts a page of 25 and queues the rest');
$GLOBALS['free_lock_held'] = true; Legacy_Adoption::upgrade();
check(count($GLOBALS['adoptions']) === 25 && count($GLOBALS['options']['patwc_adoption_queue']) === 2 && count($GLOBALS['order_queries']) === 1, 'a deferral keeps the order queued and the snapshot is not retaken');
$GLOBALS['free_lock_held'] = false; Legacy_Adoption::upgrade();
check(count($GLOBALS['adoptions']) === 27 && !isset($GLOBALS['options']['patwc_adoption_queue']) && $GLOBALS['options']['patwc_adoption_version'] === Legacy_Adoption::VERSION, 'the pass finishes and marks itself done');
Legacy_Adoption::upgrade();
check(count($GLOBALS['order_queries']) === 1, 'a finished pass does not run again');
echo "legacy adoption cases passed\n";
