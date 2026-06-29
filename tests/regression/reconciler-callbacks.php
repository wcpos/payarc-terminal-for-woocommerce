<?php

/**
 * Regression tests for PayArc fetched transaction reconciliation.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/Utils/Money.php',
    $root . '/includes/PaymentReconciler.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

class PatwcReconcilerCallbacksOrder
{
    /** @var int */
    private $id;

    /** @var string */
    private $total;

    /** @var string */
    private $currency;

    /** @var bool */
    private $paid;

    /** @var array<string, mixed> */
    public $meta = array();

    /** @var array<int, string> */
    public $notes = array();

    /** @var array<int, string> */
    public $payment_complete_calls = array();

    /** @var int */
    public $save_count = 0;

    public function __construct(int $id, string $total = '10.23', string $currency = 'USD', bool $paid = false)
    {
        $this->id = $id;
        $this->total = $total;
        $this->currency = $currency;
        $this->paid = $paid;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_currency(): string
    {
        return $this->currency;
    }

    public function is_paid(): bool
    {
        return $this->paid;
    }

    public function payment_complete($transaction_id = ''): void
    {
        $this->payment_complete_calls[] = (string) $transaction_id;
        $this->paid = true;
    }

    public function add_order_note($note): void
    {
        $this->notes[] = (string) $note;
    }

    public function get_meta($key, $single = true)
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data($key): void
    {
        unset($this->meta[$key]);
    }

    public function save(): void
    {
        $this->save_count++;
    }
}

function patwc_reconciler_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_reconciler_assert_true($actual, string $message): void
{
    patwc_reconciler_assert_same(true, $actual, $message);
}

function patwc_reconciler_assert_false($actual, string $message): void
{
    patwc_reconciler_assert_same(false, $actual, $message);
}

function patwc_reconciler_assert_contains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true) . '.');
    }
}

function patwc_reconciler_payload(array $overrides = array()): array
{
    return array_replace_recursive(array(
        'traceId' => 'trace-1001',
        'transactionId' => 'txn-1001',
        'chargeId' => 'charge-1001',
        'status' => 'SUCCESS',
        'amount' => array(
            'approved' => 1023,
            'total' => 1023,
            'currency' => 'USD',
        ),
        'metadata' => array('order_id' => '1001'),
        'card' => array(
            'brand' => 'VISA',
            'entryMode' => 'CONTACTLESS',
            'last4' => '4242',
        ),
        'processor' => array(
            'responseCode' => '00',
            'responseText' => 'Approved',
        ),
    ), $overrides);
}

$reconciler = new PaymentReconciler(new Settings(array()));

$successOrder = new PatwcReconcilerCallbacksOrder(1001);
PaymentAttempt::record_new($successOrder, array('status' => 'processing', 'trace_id' => 'trace-1001', 'transaction_id' => 'txn-1001'));
$success = $reconciler->reconcile($successOrder, patwc_reconciler_payload(), 'webhook');
patwc_reconciler_assert_same('success', $success['status'], 'Fetched SUCCESS should reconcile as success.');
patwc_reconciler_assert_false($success['continue_polling'], 'Successful reconciliation should stop polling.');
patwc_reconciler_assert_true($successOrder->is_paid(), 'Fetched SUCCESS should mark the order paid.');
patwc_reconciler_assert_same(array('charge-1001'), $successOrder->payment_complete_calls, 'Fetched SUCCESS should complete payment with charge id.');
patwc_reconciler_assert_same('charge-1001', $successOrder->meta['_patwc_charge_id'], 'Fetched SUCCESS should store charge id detail meta.');
patwc_reconciler_assert_same('trace-1001', $successOrder->meta[PaymentAttempt::META_CURRENT_TRACE_ID], 'Fetched SUCCESS should store trace id.');
patwc_reconciler_assert_same('VISA', $successOrder->meta['_patwc_card_brand'], 'Fetched SUCCESS should store card brand.');
patwc_reconciler_assert_same('CONTACTLESS', $successOrder->meta['_patwc_card_entry_mode'], 'Fetched SUCCESS should store card entry mode.');
patwc_reconciler_assert_same('4242', $successOrder->meta['_patwc_card_last4'], 'Fetched SUCCESS should store card last4.');
patwc_reconciler_assert_same('00', $successOrder->meta['_patwc_processor_response_code'], 'Fetched SUCCESS should store processor code.');
patwc_reconciler_assert_same('Approved', $successOrder->meta['_patwc_processor_response_text'], 'Fetched SUCCESS should store processor text.');
patwc_reconciler_assert_same('success', PaymentAttempt::current($successOrder)['status'], 'Fetched SUCCESS should update current attempt status.');
patwc_reconciler_assert_same(array('trace-1001|success'), $successOrder->meta[PaymentAttempt::META_PROCESSED_CALLBACKS], 'Fetched SUCCESS should record processed callback key.');
patwc_reconciler_assert_same(1, count($successOrder->notes), 'Fetched SUCCESS should add one final-status order note.');

$approvedOrder = new PatwcReconcilerCallbacksOrder(1002);
PaymentAttempt::record_new($approvedOrder, array('status' => 'processing', 'trace_id' => 'trace-1002', 'transaction_id' => 'txn-1002'));
$approved = $reconciler->reconcile($approvedOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1002',
    'transactionId' => 'txn-1002',
    'chargeId' => 'charge-1002',
    'status' => 'APPROVED',
    'metadata' => array('order_id' => '1002'),
)), 'poll');
patwc_reconciler_assert_same('success', $approved['status'], 'Fetched APPROVED should normalize to success.');
patwc_reconciler_assert_same(array('charge-1002'), $approvedOrder->payment_complete_calls, 'Fetched APPROVED should mark payment complete.');

$staleCallbackOrder = new PatwcReconcilerCallbacksOrder(1003);
PaymentAttempt::record_new($staleCallbackOrder, array('status' => 'processing', 'trace_id' => 'trace-1003', 'transaction_id' => 'txn-1003'));
$declinedFetched = $reconciler->reconcile($staleCallbackOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1003',
    'transactionId' => 'txn-1003',
    'chargeId' => 'charge-1003',
    'status' => 'DECLINE',
    'metadata' => array('order_id' => '1003'),
    'processorResponse' => array('code' => '05', 'text' => 'Do not honor'),
)), 'webhook');
patwc_reconciler_assert_same('decline', $declinedFetched['status'], 'Fetched DECLINE should override any successful callback body.');
patwc_reconciler_assert_false($staleCallbackOrder->is_paid(), 'Fetched DECLINE should leave order unpaid.');
patwc_reconciler_assert_same(array(), $staleCallbackOrder->payment_complete_calls, 'Fetched DECLINE should not complete payment.');

$declineOrder = new PatwcReconcilerCallbacksOrder(1004);
PaymentAttempt::record_new($declineOrder, array('status' => 'processing', 'trace_id' => 'trace-1004', 'transaction_id' => 'txn-1004'));
$decline = $reconciler->reconcile($declineOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1004',
    'transactionId' => 'txn-1004',
    'status' => 'DECLINED',
    'metadata' => array('order_id' => '1004'),
    'processorResponse' => array('code' => '51', 'text' => 'Insufficient funds'),
)), 'webhook');
patwc_reconciler_assert_same('decline', $decline['status'], 'Fetched DECLINED should return decline.');
patwc_reconciler_assert_same('decline', PaymentAttempt::current($declineOrder)['status'], 'Fetched DECLINED should store failure attempt status.');
patwc_reconciler_assert_same('51', $declineOrder->meta['_patwc_processor_response_code'], 'Fetched DECLINED should store failure processor code.');
patwc_reconciler_assert_same('Insufficient funds', $declineOrder->meta['_patwc_processor_response_text'], 'Fetched DECLINED should store failure processor text.');
patwc_reconciler_assert_false($declineOrder->is_paid(), 'Fetched DECLINED should leave order unpaid.');

$amountMismatchOrder = new PatwcReconcilerCallbacksOrder(1005);
PaymentAttempt::record_new($amountMismatchOrder, array('status' => 'processing', 'trace_id' => 'trace-1005', 'transaction_id' => 'txn-1005'));
$amountMismatch = $reconciler->reconcile($amountMismatchOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1005',
    'transactionId' => 'txn-1005',
    'status' => 'SUCCESS',
    'amount' => array('approved' => 999, 'total' => 999, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1005'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $amountMismatch['status'], 'Amount mismatch should return verification_failed.');
patwc_reconciler_assert_same('failure', PaymentAttempt::current($amountMismatchOrder)['status'], 'Amount mismatch should store failure-like attempt status.');
patwc_reconciler_assert_contains('amount', $amountMismatchOrder->meta['_patwc_verification_failure'], 'Amount mismatch should store verification failure meta.');
patwc_reconciler_assert_false($amountMismatchOrder->is_paid(), 'Amount mismatch should leave order unpaid.');

$currencyMismatchOrder = new PatwcReconcilerCallbacksOrder(1006, '10.23', 'USD');
PaymentAttempt::record_new($currencyMismatchOrder, array('status' => 'processing', 'trace_id' => 'trace-1006', 'transaction_id' => 'txn-1006'));
$currencyMismatch = $reconciler->reconcile($currencyMismatchOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1006',
    'transactionId' => 'txn-1006',
    'status' => 'SUCCESS',
    'amount' => array('approved' => 1023, 'total' => 1023, 'currency' => 'EUR'),
    'metadata' => array('order_id' => '1006'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $currencyMismatch['status'], 'Currency mismatch should return verification_failed.');
patwc_reconciler_assert_contains('currency', $currencyMismatchOrder->meta['_patwc_verification_failure'], 'Currency mismatch should store verification failure meta.');
patwc_reconciler_assert_false($currencyMismatchOrder->is_paid(), 'Currency mismatch should leave order unpaid.');

$duplicateOrder = new PatwcReconcilerCallbacksOrder(1007);
PaymentAttempt::record_new($duplicateOrder, array('status' => 'processing', 'trace_id' => 'trace-1007', 'transaction_id' => 'txn-1007'));
$duplicatePayload = patwc_reconciler_payload(array(
    'traceId' => 'trace-1007',
    'transactionId' => 'txn-1007',
    'chargeId' => 'charge-1007',
    'metadata' => array('order_id' => '1007'),
));
$firstDuplicate = $reconciler->reconcile($duplicateOrder, $duplicatePayload, 'webhook');
$secondDuplicate = $reconciler->reconcile($duplicateOrder, $duplicatePayload, 'webhook');
patwc_reconciler_assert_same('success', $firstDuplicate['status'], 'First duplicate fixture callback should succeed.');
patwc_reconciler_assert_same('idempotent', $secondDuplicate['status'], 'Second identical callback should be idempotent.');
patwc_reconciler_assert_same(array('charge-1007'), $duplicateOrder->payment_complete_calls, 'Duplicate callback should not complete payment twice.');

$paidConflictOrder = new PatwcReconcilerCallbacksOrder(1008, '10.23', 'USD', true);
PaymentAttempt::record_new($paidConflictOrder, array('status' => 'success', 'trace_id' => 'trace-paid-original', 'transaction_id' => 'txn-paid-original', 'charge_id' => 'charge-paid-original'));
$paidConflictOrder->update_meta_data('_patwc_charge_id', 'charge-paid-original');
$paidConflict = $reconciler->reconcile($paidConflictOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-paid-conflict',
    'transactionId' => 'txn-paid-conflict',
    'chargeId' => 'charge-paid-conflict',
    'metadata' => array('order_id' => '1008'),
)), 'webhook');
patwc_reconciler_assert_same('conflict', $paidConflict['status'], 'Paid order with different transaction should conflict.');
patwc_reconciler_assert_same('charge-paid-original', $paidConflictOrder->meta['_patwc_charge_id'], 'Paid conflict should not overwrite existing charge detail.');
patwc_reconciler_assert_same('trace-paid-original', PaymentAttempt::current($paidConflictOrder)['trace_id'], 'Paid conflict should not overwrite current trace id.');
patwc_reconciler_assert_same(array(), $paidConflictOrder->payment_complete_calls, 'Paid conflict should not complete another payment.');
