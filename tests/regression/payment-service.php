<?php

/**
 * Regression tests for PayArc payment start, poll, and cancel services.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcPaymentService;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\PayArcIds;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/PaymentLock.php',
    $root . '/includes/Utils/Money.php',
    $root . '/includes/Utils/PayArcIds.php',
    $root . '/includes/Services/PayArcClient.php',
    $root . '/includes/Services/TerminalService.php',
    $root . '/includes/Services/PayArcPaymentService.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        if (empty($GLOBALS['patwc_payment_service_uuids'])) {
            throw new RuntimeException('No deterministic UUID queued for test.');
        }

        return array_shift($GLOBALS['patwc_payment_service_uuids']);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        if (array_key_exists($option, $GLOBALS['patwc_payment_service_options'])) {
            return $GLOBALS['patwc_payment_service_options'][$option];
        }

        return $default;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($option, $GLOBALS['patwc_payment_service_options'])) {
            return false;
        }

        $GLOBALS['patwc_payment_service_options'][$option] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        unset($GLOBALS['patwc_payment_service_options'][$option]);

        return true;
    }
}

class PatwcPaymentServiceOrder
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

class PatwcPaymentServiceFakeClient
{
    /** @var array<int, array<string, mixed>> */
    public $sale_calls = array();

    /** @var array<int, array<string, mixed>> */
    public $get_calls = array();

    /** @var array<int, array<string, mixed>> */
    public $cancel_calls = array();

    /** @var array<string, mixed> */
    public $sale_response = array('traceId' => 'trace-sync-001', 'response' => array('status' => 'ACCEPTED'));

    /** @var array<string, mixed> */
    public $transaction_response = array('traceId' => 'trace-sync-001', 'status' => 'APPROVED');

    /** @var array<string, mixed>|Throwable */
    public $cancel_response = array('status' => 'ACCEPTED');

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sale(array $payload, string $idempotency_key): array
    {
        $this->sale_calls[] = array('payload' => $payload, 'idempotency_key' => $idempotency_key);

        return $this->sale_response;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_transaction(string $trace_id): array
    {
        $this->get_calls[] = array('trace_id' => $trace_id);

        return $this->transaction_response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(string $trace_id, array $payload, string $idempotency_key): array
    {
        $this->cancel_calls[] = array('trace_id' => $trace_id, 'payload' => $payload, 'idempotency_key' => $idempotency_key);

        if ($this->cancel_response instanceof Throwable) {
            throw $this->cancel_response;
        }

        return $this->cancel_response;
    }
}

class PatwcPaymentServiceFakeReconciler
{
    /** @var array<int, array<string, mixed>> */
    public $calls = array();

    /** @var array<string, mixed> */
    public $result = array('status' => 'success', 'continue_polling' => false, 'reconciled' => true);

    /**
     * @param object $order
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reconcile($order, array $payload, string $source): array
    {
        $this->calls[] = array('order_id' => $order->get_id(), 'payload' => $payload, 'source' => $source);

        return $this->result;
    }
}

function patwc_payment_service_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_payment_service_assert_true($actual, string $message): void
{
    patwc_payment_service_assert_same(true, $actual, $message);
}

function patwc_payment_service_assert_false($actual, string $message): void
{
    patwc_payment_service_assert_same(false, $actual, $message);
}

function patwc_payment_service_settings(array $overrides = array()): Settings
{
    return new Settings(array_merge(array(
        'mode' => 'test',
        'tenant_id' => '123456789012',
        'default_terminal_id' => '1234567890',
        'tender_type' => 'DEBIT',
        'print_receipt' => '2',
    ), $overrides));
}

function patwc_payment_service_make_service(Settings $settings, PatwcPaymentServiceFakeClient $client, ?PatwcPaymentServiceFakeReconciler $reconciler = null, ?callable $clock = null): PayArcPaymentService
{
    return new PayArcPaymentService($settings, $client, new TerminalService($settings), $reconciler, $clock);
}

function patwc_payment_service_reset_uuids(array $uuids = array()): void
{
    $GLOBALS['patwc_payment_service_uuids'] = $uuids;
    $GLOBALS['patwc_payment_service_options'] = array();
}

patwc_payment_service_reset_uuids(array('attempt-invalid', 'idem-invalid'));
$invalidClient = new PatwcPaymentServiceFakeClient();
$invalidService = patwc_payment_service_make_service(patwc_payment_service_settings(array('tenant_id' => 'tenant-bad')), $invalidClient);
try {
    $invalidService->start_payment_for_order(new PatwcPaymentServiceOrder(9001));
} catch (InvalidArgumentException $exception) {
    patwc_payment_service_assert_same(0, count($invalidClient->sale_calls), 'Invalid terminal settings should be rejected before sale.');
} catch (Throwable $exception) {
    throw new RuntimeException('Invalid terminal settings should throw InvalidArgumentException, got ' . get_class($exception) . '.');
}
if (count($invalidClient->sale_calls) !== 0) {
    throw new RuntimeException('Invalid terminal settings should not call sale.');
}

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440001'));
$client = new PatwcPaymentServiceFakeClient();
$service = patwc_payment_service_make_service(patwc_payment_service_settings(), $client);
$order = new PatwcPaymentServiceOrder(123);
$started = $service->start_payment_for_order($order);
$expectedTransactionId = PayArcIds::transaction_id(123, '550e8400-e29b-41d4-a716-446655440000');
patwc_payment_service_assert_same(1, count($client->sale_calls), 'start_payment_for_order should call sale once.');
patwc_payment_service_assert_same(array(
    'tenantId' => '123456789012',
    'terminalId' => '1234567890',
    'transactionId' => $expectedTransactionId,
    'tenderType' => 'DEBIT',
    'amount' => Money::to_payarc_amount_object('10.23', 'USD'),
    'printReceipt' => 2,
    'callbackURL' => 'admin-ajax.php?action=patwc_payarc_callback',
    'metadata' => array('order_id' => 123, 'terminal_id' => '1234567890', 'mode' => 'test'),
), $client->sale_calls[0]['payload'], 'Sale payload mismatch.');
patwc_payment_service_assert_same('550e8400-e29b-41d4-a716-446655440001', $client->sale_calls[0]['idempotency_key'], 'Sale idempotency key mismatch.');
patwc_payment_service_assert_same('created', $started['status'], 'Started attempt should be created.');
patwc_payment_service_assert_same('trace-sync-001', $started['trace_id'], 'Started attempt should include synchronous trace id.');
patwc_payment_service_assert_same($expectedTransactionId, $started['transaction_id'], 'Started attempt should include client transaction id.');
patwc_payment_service_assert_same('1234567890', $started['terminal_id'], 'Started attempt should store terminal id.');
patwc_payment_service_assert_same($client->sale_response, $started['sale_response'], 'Started attempt should store sale response snapshot.');
patwc_payment_service_assert_same($expectedTransactionId, $order->meta[PaymentAttempt::META_CURRENT_TRANSACTION_ID], 'Current transaction id meta should be stored.');
patwc_payment_service_assert_same('trace-sync-001', $order->meta[PaymentAttempt::META_CURRENT_TRACE_ID], 'Current trace id meta should be stored.');
patwc_payment_service_assert_same($started, PaymentAttempt::current($order), 'Current attempt should be stored.');

$paidClient = new PatwcPaymentServiceFakeClient();
$paidService = patwc_payment_service_make_service(patwc_payment_service_settings(), $paidClient);
$paidResult = $paidService->start_payment_for_order(new PatwcPaymentServiceOrder(124, '10.23', 'USD', true));
patwc_payment_service_assert_same('already_paid', $paidResult['status'], 'Paid orders should not start payment.');
patwc_payment_service_assert_same(0, count($paidClient->sale_calls), 'Paid orders should not call sale.');

$reuseClient = new PatwcPaymentServiceFakeClient();
$reuseService = patwc_payment_service_make_service(patwc_payment_service_settings(), $reuseClient);
$reuseOrder = new PatwcPaymentServiceOrder(125);
$existingAttempt = PaymentAttempt::record_new($reuseOrder, array('status' => 'processing', 'trace_id' => 'trace-existing', 'transaction_id' => 'txn-existing', 'terminal_id' => '1234567890'));
$reuseResult = $reuseService->start_payment_for_order($reuseOrder);
patwc_payment_service_assert_same($existingAttempt, $reuseResult['attempt'], 'Existing non-final attempt should be returned.');
patwc_payment_service_assert_same('existing_attempt', $reuseResult['status'], 'Existing non-final attempt should be identified.');
patwc_payment_service_assert_same(0, count($reuseClient->sale_calls), 'Existing non-final attempt should not call sale.');

$pollNoTraceClient = new PatwcPaymentServiceFakeClient();
$pollNoTraceService = patwc_payment_service_make_service(patwc_payment_service_settings(), $pollNoTraceClient);
$pollNoTraceOrder = new PatwcPaymentServiceOrder(126);
PaymentAttempt::record_new($pollNoTraceOrder, array('status' => 'created', 'transaction_id' => 'txn-no-trace', 'terminal_id' => '1234567890'));
$pollNoTrace = $pollNoTraceService->poll_order($pollNoTraceOrder);
patwc_payment_service_assert_same('pending_callback', $pollNoTrace['status'], 'Poll without trace should return pending_callback.');
patwc_payment_service_assert_true($pollNoTrace['continue_polling'], 'Poll without trace should continue polling.');
patwc_payment_service_assert_same(0, count($pollNoTraceClient->get_calls), 'Poll without trace should not fetch transaction.');

$now = 1000;
$pollClient = new PatwcPaymentServiceFakeClient();
$pollClient->transaction_response = array('traceId' => 'trace-poll-001', 'status' => 'APPROVED', 'amount' => 1023);
$reconciler = new PatwcPaymentServiceFakeReconciler();
$pollService = patwc_payment_service_make_service(patwc_payment_service_settings(), $pollClient, $reconciler, function () use (&$now): int { return $now; });
$pollOrder = new PatwcPaymentServiceOrder(127);
PaymentAttempt::record_new($pollOrder, array('status' => 'processing', 'trace_id' => 'trace-poll-001', 'transaction_id' => 'txn-poll', 'terminal_id' => '1234567890'));
$pollResult = $pollService->poll_order($pollOrder);
patwc_payment_service_assert_same($reconciler->result, $pollResult, 'Poll should return reconciler result.');
patwc_payment_service_assert_same(array(array('trace_id' => 'trace-poll-001')), $pollClient->get_calls, 'Poll should fetch transaction by trace id.');
patwc_payment_service_assert_same(array(array('order_id' => 127, 'payload' => $pollClient->transaction_response, 'source' => 'poll')), $reconciler->calls, 'Poll should pass lookup payload to reconciler.');
$throttled = $pollService->poll_order($pollOrder);
patwc_payment_service_assert_same('processing', $throttled['status'], 'Immediate second poll should return local non-final status.');
patwc_payment_service_assert_true($throttled['continue_polling'], 'Immediate second poll should continue polling.');
patwc_payment_service_assert_same(1, count($pollClient->get_calls), 'Immediate second poll should be throttled.');
$now = 1002;
$pollService->poll_order($pollOrder);
patwc_payment_service_assert_same(2, count($pollClient->get_calls), 'Poll after throttle window should fetch again.');

$cancelNoTraceClient = new PatwcPaymentServiceFakeClient();
$cancelNoTraceService = patwc_payment_service_make_service(patwc_payment_service_settings(), $cancelNoTraceClient);
$cancelNoTraceOrder = new PatwcPaymentServiceOrder(128);
PaymentAttempt::record_new($cancelNoTraceOrder, array('status' => 'created', 'transaction_id' => 'txn-cancel-no-trace', 'terminal_id' => '1234567890'));
$cancelNoTrace = $cancelNoTraceService->cancel_order_payment($cancelNoTraceOrder);
patwc_payment_service_assert_same('not_cancelable_without_trace', $cancelNoTrace['status'], 'Cancel without trace should be rejected locally.');
patwc_payment_service_assert_true(strpos($cancelNoTrace['message'], 'PayArc did not provide a trace id yet') !== false, 'Cancel without trace should include operator-facing trace id message.');
patwc_payment_service_assert_same(0, count($cancelNoTraceClient->cancel_calls), 'Cancel without trace should not call PayArc cancel.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440099'));
$cancelClient = new PatwcPaymentServiceFakeClient();
$cancelService = patwc_payment_service_make_service(patwc_payment_service_settings(), $cancelClient);
$cancelOrder = new PatwcPaymentServiceOrder(129);
PaymentAttempt::record_new($cancelOrder, array('status' => 'processing', 'trace_id' => 'trace-cancel-001', 'transaction_id' => 'txn-cancel', 'terminal_id' => '1234567890'));
$cancelResult = $cancelService->cancel_order_payment($cancelOrder);
patwc_payment_service_assert_same('cancel_requested', $cancelResult['status'], 'Accepted cancel should store cancel_requested.');
patwc_payment_service_assert_same(array(array(
    'trace_id' => 'trace-cancel-001',
    'payload' => array('tenantId' => '123456789012', 'terminalId' => '1234567890'),
    'idempotency_key' => '550e8400-e29b-41d4-a716-446655440099',
)), $cancelClient->cancel_calls, 'Cancel call mismatch.');
patwc_payment_service_assert_same('cancel_requested', PaymentAttempt::current($cancelOrder)['status'], 'Cancel should update current attempt status.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440100'));
$processedClient = new PatwcPaymentServiceFakeClient();
$processedClient->cancel_response = new RuntimeException('transaction already processed by terminal');
$processedClient->transaction_response = array('traceId' => 'trace-processed-001', 'status' => 'APPROVED');
$processedReconciler = new PatwcPaymentServiceFakeReconciler();
$processedService = patwc_payment_service_make_service(patwc_payment_service_settings(), $processedClient, $processedReconciler);
$processedOrder = new PatwcPaymentServiceOrder(130);
PaymentAttempt::record_new($processedOrder, array('status' => 'processing', 'trace_id' => 'trace-processed-001', 'transaction_id' => 'txn-processed', 'terminal_id' => '1234567890'));
$processedResult = $processedService->cancel_order_payment($processedOrder);
patwc_payment_service_assert_same($processedReconciler->result, $processedResult, 'Already-processed cancel should return reconciler result.');
patwc_payment_service_assert_same(array(array('trace_id' => 'trace-processed-001')), $processedClient->get_calls, 'Already-processed cancel should fetch transaction.');
patwc_payment_service_assert_same(array(array('order_id' => 130, 'payload' => $processedClient->transaction_response, 'source' => 'cancel_lookup')), $processedReconciler->calls, 'Already-processed cancel should reconcile fetched transaction.');

