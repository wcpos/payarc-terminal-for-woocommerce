<?php

/**
 * Regression tests for PayArc payment attempt storage and advisory locking.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;

$paymentAttemptFile = dirname(__DIR__, 2) . '/includes/PaymentAttempt.php';
$paymentLockFile = dirname(__DIR__, 2) . '/includes/PaymentLock.php';

if (!is_readable($paymentAttemptFile)) {
    throw new RuntimeException('PaymentAttempt class file is missing.');
}

if (!is_readable($paymentLockFile)) {
    throw new RuntimeException('PaymentLock class file is missing.');
}

require_once $paymentAttemptFile;
require_once $paymentLockFile;

if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        return array_key_exists($transient, $GLOBALS['patwc_payment_attempt_transients'])
            ? $GLOBALS['patwc_payment_attempt_transients'][$transient]
            : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        $GLOBALS['patwc_payment_attempt_transients'][$transient] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient)
    {
        unset($GLOBALS['patwc_payment_attempt_transients'][$transient]);

        return true;
    }
}

class PatwcPaymentAttemptRegressionOrder
{
    /** @var int */
    private $id;

    /** @var array<string, mixed> */
    public $meta = array();

    /** @var int */
    public $save_count = 0;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function get_id(): int
    {
        return $this->id;
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

function patwc_payment_attempt_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_payment_attempt_assert_true($actual, string $message): void
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true) . '.');
    }
}

function patwc_payment_attempt_assert_false($actual, string $message): void
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true) . '.');
    }
}

function patwc_payment_attempt_reset_transients(): void
{
    $GLOBALS['patwc_payment_attempt_transients'] = array();
}

$order = new PatwcPaymentAttemptRegressionOrder(5001);
$recorded = PaymentAttempt::record_new($order, array(
    'trace_id' => 'trace-001',
    'transaction_id' => 'txn-001',
    'status' => 'approval',
    'charge_id' => 'charge-001',
    'terminal_id' => 'term-001',
    'extra' => 'kept',
));

patwc_payment_attempt_assert_same('success', $recorded['status'], 'record_new should normalize successful statuses.');
patwc_payment_attempt_assert_same('trace-001', $order->meta[PaymentAttempt::META_CURRENT_TRACE_ID], 'record_new should store current trace id.');
patwc_payment_attempt_assert_same('txn-001', $order->meta[PaymentAttempt::META_CURRENT_TRANSACTION_ID], 'record_new should store current transaction id.');
patwc_payment_attempt_assert_same('success', $order->meta[PaymentAttempt::META_CURRENT_STATUS], 'record_new should store current status.');
patwc_payment_attempt_assert_same('charge-001', $order->meta[PaymentAttempt::META_CURRENT_CHARGE_ID], 'record_new should store current charge id.');
patwc_payment_attempt_assert_same('term-001', $order->meta[PaymentAttempt::META_CURRENT_TERMINAL_ID], 'record_new should store current terminal id.');
patwc_payment_attempt_assert_same($recorded, $order->meta[PaymentAttempt::META_CURRENT_ATTEMPT], 'record_new should store full current attempt.');
patwc_payment_attempt_assert_same(array($recorded), $order->meta[PaymentAttempt::META_ATTEMPT_HISTORY], 'record_new should append history.');
patwc_payment_attempt_assert_same(1, $order->save_count, 'record_new should save the order.');
patwc_payment_attempt_assert_same($recorded, PaymentAttempt::current($order), 'current should return stored current attempt.');

$updated = PaymentAttempt::update_status($order, 'processing');
patwc_payment_attempt_assert_same('processing', $updated['status'], 'update_status should update status.');
patwc_payment_attempt_assert_same('trace-001', $updated['trace_id'], 'update_status should not erase trace id when omitted.');
patwc_payment_attempt_assert_same('txn-001', $updated['transaction_id'], 'update_status should not erase transaction id when omitted.');
patwc_payment_attempt_assert_same('charge-001', $updated['charge_id'], 'update_status should not erase charge id when omitted.');
patwc_payment_attempt_assert_same('term-001', $updated['terminal_id'], 'update_status should not erase terminal id when omitted.');
patwc_payment_attempt_assert_same('processing', $order->meta[PaymentAttempt::META_CURRENT_STATUS], 'update_status should store normalized status meta.');

$updatedWithFields = PaymentAttempt::update_status($order, 'failed', array(
    'trace_id' => 'trace-002',
    'transaction_id' => 'txn-002',
    'charge_id' => 'charge-002',
    'terminal_id' => 'term-002',
));
patwc_payment_attempt_assert_same('failure', $updatedWithFields['status'], 'update_status should normalize failed to failure.');
patwc_payment_attempt_assert_same('trace-002', $updatedWithFields['trace_id'], 'update_status should update trace id when provided.');
patwc_payment_attempt_assert_same('txn-002', $updatedWithFields['transaction_id'], 'update_status should update transaction id when provided.');
patwc_payment_attempt_assert_same('charge-002', $order->meta[PaymentAttempt::META_CURRENT_CHARGE_ID], 'update_status should update charge id meta when provided.');
patwc_payment_attempt_assert_same('term-002', $order->meta[PaymentAttempt::META_CURRENT_TERMINAL_ID], 'update_status should update terminal id meta when provided.');

$legacyOrder = new PatwcPaymentAttemptRegressionOrder(5002);
$legacyOrder->update_meta_data(PaymentAttempt::META_CURRENT_TRACE_ID, 'legacy-trace');
$legacyOrder->update_meta_data(PaymentAttempt::META_CURRENT_TRANSACTION_ID, 'legacy-txn');
$legacyOrder->update_meta_data(PaymentAttempt::META_CURRENT_STATUS, 'pending');
$legacyOrder->update_meta_data(PaymentAttempt::META_CURRENT_CHARGE_ID, 'legacy-charge');
$legacyOrder->update_meta_data(PaymentAttempt::META_CURRENT_TERMINAL_ID, 'legacy-terminal');
patwc_payment_attempt_assert_same(array(
    'trace_id' => 'legacy-trace',
    'transaction_id' => 'legacy-txn',
    'status' => 'pending',
    'charge_id' => 'legacy-charge',
    'terminal_id' => 'legacy-terminal',
), PaymentAttempt::current($legacyOrder), 'current should reconstruct from individual meta keys.');

foreach (array('created', 'pending', 'sent', 'processing') as $status) {
    patwc_payment_attempt_assert_true(PaymentAttempt::is_non_final($status), $status . ' should be non-final.');
}

foreach (array('success', 'decline', 'timeout', 'cancelled', 'failure') as $status) {
    patwc_payment_attempt_assert_false(PaymentAttempt::is_non_final($status), $status . ' should not be non-final.');
}

foreach (array('decline', 'declined', 'timeout', 'cancelled', 'canceled', 'failure', 'failed', 'dup transaction') as $status) {
    patwc_payment_attempt_assert_true(PaymentAttempt::is_final_unpaid($status), $status . ' should be final unpaid.');
}

foreach (array('success', 'successful', 'approved', 'approval', 'completed', 'complete') as $status) {
    patwc_payment_attempt_assert_same('success', PaymentAttempt::normalize_status($status), $status . ' should normalize to success.');
}

patwc_payment_attempt_assert_same('decline', PaymentAttempt::normalize_status('declined'), 'declined should normalize to decline.');
patwc_payment_attempt_assert_same('cancelled', PaymentAttempt::normalize_status('canceled'), 'canceled should normalize to cancelled.');
patwc_payment_attempt_assert_same('failure', PaymentAttempt::normalize_status('failed'), 'failed should normalize to failure.');
patwc_payment_attempt_assert_same('dup transaction', PaymentAttempt::normalize_status('dup transaction'), 'dup transaction should remain a final unpaid status.');

patwc_payment_attempt_reset_transients();
$firstLockRan = false;
$firstLockResult = PaymentLock::with_lock(6001, 'sale', function () use (&$firstLockRan): array {
    $firstLockRan = true;

    return array('status' => 'ok');
});
patwc_payment_attempt_assert_true($firstLockRan, 'First lock should run callback.');
patwc_payment_attempt_assert_same(array('status' => 'ok'), $firstLockResult, 'First lock should return callback result.');

patwc_payment_attempt_reset_transients();
$nestedCallbackRan = false;
$nestedConflict = PaymentLock::with_lock(6002, 'sale', function () use (&$nestedCallbackRan): array {
    return PaymentLock::with_lock(6002, 'sale', function () use (&$nestedCallbackRan): array {
        $nestedCallbackRan = true;

        return array('status' => 'unexpected');
    });
});
patwc_payment_attempt_assert_same(array(
    'status' => 'conflict',
    'message' => 'Another PayArc operation is already in progress for this order.',
    'continue_polling' => true,
), $nestedConflict, 'Active lock should return conflict.');
patwc_payment_attempt_assert_false($nestedCallbackRan, 'Active lock should not run nested callback.');

patwc_payment_attempt_reset_transients();
$exceptionWasThrown = false;
try {
    PaymentLock::with_lock(6003, 'void sale', function (): array {
        throw new RuntimeException('callback failed');
    });
} catch (RuntimeException $exception) {
    $exceptionWasThrown = $exception->getMessage() === 'callback failed';
}
patwc_payment_attempt_assert_true($exceptionWasThrown, 'with_lock should rethrow callback exceptions.');
$afterExceptionRan = false;
$afterExceptionResult = PaymentLock::with_lock(6003, 'void sale', function () use (&$afterExceptionRan): array {
    $afterExceptionRan = true;

    return array('status' => 'after-exception');
});
patwc_payment_attempt_assert_true($afterExceptionRan, 'Lock should be released after callback throws.');
patwc_payment_attempt_assert_same(array('status' => 'after-exception'), $afterExceptionResult, 'Released lock should run callback after exception.');
