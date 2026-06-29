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

function patwc_reconciler_assert_array_not_has_key(string $key, array $array, string $message): void
{
    if (array_key_exists($key, $array)) {
        throw new RuntimeException($message . ' Did not expect key ' . $key . ' in ' . var_export($array, true) . '.');
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
patwc_reconciler_assert_array_not_has_key('_patwc_charge_id', $amountMismatchOrder->meta, 'Amount mismatch should not store charge detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_brand', $amountMismatchOrder->meta, 'Amount mismatch should not store card brand detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_entry_mode', $amountMismatchOrder->meta, 'Amount mismatch should not store card entry detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_last4', $amountMismatchOrder->meta, 'Amount mismatch should not store card last4 detail meta.');
patwc_reconciler_assert_array_not_has_key('charge_id', PaymentAttempt::current($amountMismatchOrder), 'Amount mismatch should not store current attempt charge id.');

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
patwc_reconciler_assert_array_not_has_key('_patwc_charge_id', $currencyMismatchOrder->meta, 'Currency mismatch should not store charge detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_brand', $currencyMismatchOrder->meta, 'Currency mismatch should not store card brand detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_entry_mode', $currencyMismatchOrder->meta, 'Currency mismatch should not store card entry detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_last4', $currencyMismatchOrder->meta, 'Currency mismatch should not store card last4 detail meta.');
patwc_reconciler_assert_array_not_has_key('charge_id', PaymentAttempt::current($currencyMismatchOrder), 'Currency mismatch should not store current attempt charge id.');

$decimalMinorUnitOrder = new PatwcReconcilerCallbacksOrder(1011);
PaymentAttempt::record_new($decimalMinorUnitOrder, array('status' => 'processing', 'trace_id' => 'trace-1011', 'transaction_id' => 'txn-1011'));
$decimalMinorUnit = $reconciler->reconcile($decimalMinorUnitOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1011',
    'transactionId' => 'txn-1011',
    'chargeId' => 'charge-1011',
    'amount' => array('approved' => '1023.99', 'total' => 1023, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1011'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $decimalMinorUnit['status'], 'Malformed fractional approved amount should fail verification.');
patwc_reconciler_assert_false($decimalMinorUnitOrder->is_paid(), 'Malformed fractional approved amount should leave order unpaid.');
patwc_reconciler_assert_same(array(), $decimalMinorUnitOrder->payment_complete_calls, 'Malformed fractional approved amount should not complete payment.');
patwc_reconciler_assert_array_not_has_key('_patwc_charge_id', $decimalMinorUnitOrder->meta, 'Malformed fractional approved amount should not store charge detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_last4', $decimalMinorUnitOrder->meta, 'Malformed fractional approved amount should not store card last4 detail meta.');
patwc_reconciler_assert_array_not_has_key('charge_id', PaymentAttempt::current($decimalMinorUnitOrder), 'Malformed fractional approved amount should not store current attempt charge id.');

$oversizedMinorUnitOrder = new PatwcReconcilerCallbacksOrder(1012, '92233720368547758.07', 'USD');
PaymentAttempt::record_new($oversizedMinorUnitOrder, array('status' => 'processing', 'trace_id' => 'trace-1012', 'transaction_id' => 'txn-1012'));
$oversizedMinorUnit = $reconciler->reconcile($oversizedMinorUnitOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1012',
    'transactionId' => 'txn-1012',
    'chargeId' => 'charge-1012',
    'amount' => array('approved' => '9223372036854775808', 'total' => 9223372036854775807, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1012'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $oversizedMinorUnit['status'], 'Oversized approved amount string should fail verification.');
patwc_reconciler_assert_false($oversizedMinorUnitOrder->is_paid(), 'Oversized approved amount string should leave order unpaid.');
patwc_reconciler_assert_same(array(), $oversizedMinorUnitOrder->payment_complete_calls, 'Oversized approved amount string should not complete payment.');
patwc_reconciler_assert_array_not_has_key('_patwc_charge_id', $oversizedMinorUnitOrder->meta, 'Oversized approved amount string should not store charge detail meta.');
patwc_reconciler_assert_array_not_has_key('_patwc_card_last4', $oversizedMinorUnitOrder->meta, 'Oversized approved amount string should not store card last4 detail meta.');
patwc_reconciler_assert_array_not_has_key('charge_id', PaymentAttempt::current($oversizedMinorUnitOrder), 'Oversized approved amount string should not store current attempt charge id.');

$floatMinorUnitOrder = new PatwcReconcilerCallbacksOrder(1013);
PaymentAttempt::record_new($floatMinorUnitOrder, array('status' => 'processing', 'trace_id' => 'trace-1013', 'transaction_id' => 'txn-1013'));
$floatMinorUnit = $reconciler->reconcile($floatMinorUnitOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1013',
    'transactionId' => 'txn-1013',
    'chargeId' => 'charge-1013',
    'amount' => array('approved' => 1023.0, 'total' => 1023, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1013'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $floatMinorUnit['status'], 'Float approved amount should fail verification.');
patwc_reconciler_assert_same(array(), $floatMinorUnitOrder->payment_complete_calls, 'Float approved amount should not complete payment.');

$scientificMinorUnitOrder = new PatwcReconcilerCallbacksOrder(1014);
PaymentAttempt::record_new($scientificMinorUnitOrder, array('status' => 'processing', 'trace_id' => 'trace-1014', 'transaction_id' => 'txn-1014'));
$scientificMinorUnit = $reconciler->reconcile($scientificMinorUnitOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1014',
    'transactionId' => 'txn-1014',
    'chargeId' => 'charge-1014',
    'amount' => array('approved' => '1.023e3', 'total' => 1023, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1014'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $scientificMinorUnit['status'], 'Scientific notation approved amount should fail verification.');
patwc_reconciler_assert_same(array(), $scientificMinorUnitOrder->payment_complete_calls, 'Scientific notation approved amount should not complete payment.');

$negativeMinorUnitOrder = new PatwcReconcilerCallbacksOrder(1015);
PaymentAttempt::record_new($negativeMinorUnitOrder, array('status' => 'processing', 'trace_id' => 'trace-1015', 'transaction_id' => 'txn-1015'));
$negativeMinorUnit = $reconciler->reconcile($negativeMinorUnitOrder, patwc_reconciler_payload(array(
    'traceId' => 'trace-1015',
    'transactionId' => 'txn-1015',
    'chargeId' => 'charge-1015',
    'amount' => array('approved' => '-1023', 'total' => 1023, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1015'),
)), 'webhook');
patwc_reconciler_assert_same('verification_failed', $negativeMinorUnit['status'], 'Negative approved amount should fail verification.');
patwc_reconciler_assert_same(array(), $negativeMinorUnitOrder->payment_complete_calls, 'Negative approved amount should not complete payment.');

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


$paidSameTraceDifferentTransactionOrder = new PatwcReconcilerCallbacksOrder(1009, '10.23', 'USD', true);
PaymentAttempt::record_new($paidSameTraceDifferentTransactionOrder, array(
    'status' => 'success',
    'trace_id' => 'same-trace',
    'transaction_id' => 'txn-original',
    'charge_id' => 'charge-original',
));
$paidSameTraceDifferentTransactionOrder->update_meta_data('_patwc_charge_id', 'charge-original');
$paidSameTraceDifferentTransaction = $reconciler->reconcile($paidSameTraceDifferentTransactionOrder, patwc_reconciler_payload(array(
    'traceId' => 'same-trace',
    'transactionId' => 'txn-different',
    'chargeId' => 'charge-different',
    'metadata' => array('order_id' => '1009'),
)), 'webhook');
$paidSameTraceCurrentAttempt = PaymentAttempt::current($paidSameTraceDifferentTransactionOrder);
patwc_reconciler_assert_same('conflict', $paidSameTraceDifferentTransaction['status'], 'Paid order with matching trace but different transaction id should conflict.');
patwc_reconciler_assert_same('txn-original', $paidSameTraceCurrentAttempt['transaction_id'], 'Paid same-trace conflict should not overwrite current transaction id.');
patwc_reconciler_assert_same('charge-original', $paidSameTraceCurrentAttempt['charge_id'], 'Paid same-trace conflict should not overwrite current charge id.');
patwc_reconciler_assert_same('charge-original', $paidSameTraceDifferentTransactionOrder->meta['_patwc_charge_id'], 'Paid same-trace conflict should not overwrite charge detail meta.');
patwc_reconciler_assert_same(array(), $paidSameTraceDifferentTransactionOrder->payment_complete_calls, 'Paid same-trace conflict should not complete another payment.');


$paidProcessedSameTraceDifferentTransactionOrder = new PatwcReconcilerCallbacksOrder(1010, '10.23', 'USD', true);
PaymentAttempt::record_new($paidProcessedSameTraceDifferentTransactionOrder, array(
    'status' => 'success',
    'trace_id' => 'same-trace',
    'transaction_id' => 'txn-original',
    'charge_id' => 'charge-original',
));
$paidProcessedSameTraceDifferentTransactionOrder->update_meta_data('_patwc_charge_id', 'charge-original');
$paidProcessedSameTraceDifferentTransactionOrder->update_meta_data(PaymentAttempt::META_PROCESSED_CALLBACKS, array('same-trace|success'));
$paidProcessedSameTraceDifferentTransaction = $reconciler->reconcile($paidProcessedSameTraceDifferentTransactionOrder, patwc_reconciler_payload(array(
    'traceId' => 'same-trace',
    'transactionId' => 'txn-different',
    'chargeId' => 'charge-different',
    'metadata' => array('order_id' => '1010'),
)), 'webhook');
$paidProcessedSameTraceCurrentAttempt = PaymentAttempt::current($paidProcessedSameTraceDifferentTransactionOrder);
patwc_reconciler_assert_same('conflict', $paidProcessedSameTraceDifferentTransaction['status'], 'Paid processed callback with matching trace but different transaction id should conflict.');
patwc_reconciler_assert_same('txn-original', $paidProcessedSameTraceCurrentAttempt['transaction_id'], 'Paid processed same-trace conflict should not overwrite current transaction id.');
patwc_reconciler_assert_same('charge-original', $paidProcessedSameTraceCurrentAttempt['charge_id'], 'Paid processed same-trace conflict should not overwrite current charge id.');
patwc_reconciler_assert_same('charge-original', $paidProcessedSameTraceDifferentTransactionOrder->meta['_patwc_charge_id'], 'Paid processed same-trace conflict should not overwrite charge detail meta.');
patwc_reconciler_assert_same(array(), $paidProcessedSameTraceDifferentTransactionOrder->payment_complete_calls, 'Paid processed same-trace conflict should not complete another payment.');
