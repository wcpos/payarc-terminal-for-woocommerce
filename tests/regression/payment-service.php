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
    $root . '/includes/Logger.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/PaymentLock.php',
    $root . '/includes/Utils/Money.php',
    $root . '/includes/Utils/PayArcIds.php',
    $root . '/includes/Services/PayArcRequestException.php',
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

        if (isset($GLOBALS['patwc_options']) && is_array($GLOBALS['patwc_options']) && array_key_exists($option, $GLOBALS['patwc_options'])) {
            return $GLOBALS['patwc_options'][$option];
        }

        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        $GLOBALS['patwc_options'][$option] = $value;

        return true;
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

    /** @var Throwable|null */
    public $sale_exception = null;

    /** @var array<string, mixed> */
    public $transaction_response = array('traceId' => 'trace-sync-001', 'status' => 'APPROVED');

    /** @var array<string, mixed>|Throwable */
    public $cancel_response = array('status' => 'ACCEPTED');

    /** @var callable|null */
    public $sale_callback = null;

    /** @var callable|null */
    public $get_transaction_callback = null;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sale(array $payload, string $idempotency_key): array
    {
        $this->sale_calls[] = array('payload' => $payload, 'idempotency_key' => $idempotency_key);

        if ($this->sale_callback !== null) {
            call_user_func($this->sale_callback, $payload, $idempotency_key);
        }

        if ($this->sale_exception instanceof Throwable) {
            throw $this->sale_exception;
        }

        return $this->sale_response;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_transaction(string $trace_id): array
    {
        $this->get_calls[] = array('trace_id' => $trace_id);

        if ($this->get_transaction_callback !== null) {
            call_user_func($this->get_transaction_callback, $trace_id);
        }

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

/**
 * @return array<int, array<string, mixed>>
 */
function patwc_payment_service_logs(string $message): array
{
    return array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry) use ($message): bool {
        return $entry['message'] === $message;
    }));
}

function patwc_payment_service_assert_logs_hide(string $secret, string $message): void
{
    $encoded = json_encode($GLOBALS['patwc_captured_logs']);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode captured payment logs.');
    }

    patwc_payment_service_assert_false($secret !== '' && strpos($encoded, $secret) !== false, $message);
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
    $GLOBALS['patwc_options'] = array();
}

patwc_payment_service_reset_uuids(array('attempt-invalid', 'idem-invalid'));
$invalidClient = new PatwcPaymentServiceFakeClient();
$invalidService = patwc_payment_service_make_service(patwc_payment_service_settings(array('tenant_id' => '')), $invalidClient);
try {
    $invalidService->start_payment_for_order(new PatwcPaymentServiceOrder(9001));
    throw new RuntimeException('Missing tenant id should throw InvalidArgumentException.');
} catch (InvalidArgumentException $exception) {
    patwc_payment_service_assert_same('PayArc tenant id is missing. Connect PayArc with the merchant MID first.', $exception->getMessage(), 'Missing tenant id message mismatch.');
    patwc_payment_service_assert_same(0, count($invalidClient->sale_calls), 'Invalid terminal settings should be rejected before sale.');
}
if (count($invalidClient->sale_calls) !== 0) {
    throw new RuntimeException('Invalid terminal settings should not call sale.');
}

$missingTerminalClient = new PatwcPaymentServiceFakeClient();
$missingTerminalService = patwc_payment_service_make_service(patwc_payment_service_settings(array('default_terminal_id' => '')), $missingTerminalClient);
try {
    $missingTerminalService->start_payment_for_order(new PatwcPaymentServiceOrder(9001));
    throw new RuntimeException('Missing terminal serial number should throw InvalidArgumentException.');
} catch (InvalidArgumentException $exception) {
    patwc_payment_service_assert_same('No PayArc terminal serial number is configured. Enter the terminal serial number in the gateway settings.', $exception->getMessage(), 'Missing terminal serial number message mismatch.');
    patwc_payment_service_assert_same(0, count($missingTerminalClient->sale_calls), 'Missing terminal serial number should be rejected before sale.');
}

$flexibleTerminalService = new TerminalService(new Settings(array(
    'tenant_id' => 'tenant-alpha',
    'default_terminal_id' => 'ABC123',
)));
patwc_payment_service_assert_same(array(
    'tenantId' => 'tenant-alpha',
    'terminalId' => 'ABC123',
), $flexibleTerminalService->validate_default_terminal(), 'Terminal validation should accept any non-empty tenant and terminal identifiers.');

patwc_payment_service_reset_uuids(array('attempt-empty-registry', 'idem-empty-registry'));
$emptyRegistryClient = new PatwcPaymentServiceFakeClient();
$emptyRegistryService = patwc_payment_service_make_service(patwc_payment_service_settings(array(
    'connected_mode' => 'test',
    'connect_access_token' => 'connect-access-token',
    'terminal_registry' => array(),
)), $emptyRegistryClient);
$emptyRegistryService->start_payment_for_order(new PatwcPaymentServiceOrder(9002));
patwc_payment_service_assert_same(1, count($emptyRegistryClient->sale_calls), 'A configured terminal serial number should allow sale even when Terminal Registry is empty.');
patwc_payment_service_assert_same('1234567890', $emptyRegistryClient->sale_calls[0]['payload']['terminalId'], 'Sale should use the configured terminal serial number as V3 terminalId.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440001'));
$GLOBALS['patwc_captured_logs'] = array();
$client = new PatwcPaymentServiceFakeClient();
$client->sale_callback = function () use (&$client): void {
    patwc_payment_service_assert_true(PaymentAttempt::has_in_flight_attempts(), 'Payment attempt should be marked in-flight before the external PayArc sale call starts.');
};
$service = patwc_payment_service_make_service(patwc_payment_service_settings(), $client);
$order = new PatwcPaymentServiceOrder(123);
$started = $service->start_payment_for_order($order);
$expectedTransactionId = PayArcIds::transaction_id(123, '550e8400-e29b-41d4-a716-446655440000');
$expectedCallbackUrl = function_exists('admin_url') ? admin_url('admin-ajax.php?action=patwc_payarc_callback') : 'admin-ajax.php?action=patwc_payarc_callback';
patwc_payment_service_assert_same(1, count($client->sale_calls), 'start_payment_for_order should call sale once.');
patwc_payment_service_assert_same(array(
    'tenantId' => '123456789012',
    'terminalId' => '1234567890',
    'transactionId' => $expectedTransactionId,
    'tenderType' => 'DEBIT',
    'amount' => Money::to_payarc_amount_object('10.23', 'USD'),
    'printReceipt' => 2,
    'callbackURL' => $expectedCallbackUrl,
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
$saleStartedLogs = patwc_payment_service_logs('PayArc sale started');
$saleAcceptedLogs = patwc_payment_service_logs('PayArc sale accepted');
patwc_payment_service_assert_same(1, count($saleStartedLogs), 'Sale should emit one started log.');
patwc_payment_service_assert_same('info', $saleStartedLogs[0]['level'], 'Sale started log should use info level.');
patwc_payment_service_assert_same(123, $saleStartedLogs[0]['context']['order_id'], 'Sale started log should include order id.');
patwc_payment_service_assert_same('test', $saleStartedLogs[0]['context']['mode'], 'Sale started log should include mode.');
patwc_payment_service_assert_same(Settings::mask_identifier('1234567890'), $saleStartedLogs[0]['context']['terminal_id_masked'], 'Sale started log should mask terminal id.');
patwc_payment_service_assert_same('DEBIT', $saleStartedLogs[0]['context']['tender_type'], 'Sale started log should include tender type.');
patwc_payment_service_assert_same(1023, $saleStartedLogs[0]['context']['amount_total'], 'Sale started log should include minor-unit amount total.');
patwc_payment_service_assert_same('USD', $saleStartedLogs[0]['context']['currency'], 'Sale started log should include currency.');
patwc_payment_service_assert_same($expectedTransactionId, $saleStartedLogs[0]['context']['transaction_id'], 'Sale started log should include client transaction id.');
patwc_payment_service_assert_same(true, $saleStartedLogs[0]['context']['has_callback_url'], 'Sale started log should report callback URL presence.');
patwc_payment_service_assert_same(1, count($saleAcceptedLogs), 'Sale should emit one accepted log.');
patwc_payment_service_assert_same(true, $saleAcceptedLogs[0]['context']['trace_id_returned'], 'Sale accepted log should report a returned trace id.');
patwc_payment_service_assert_same(Settings::mask_identifier('trace-sync-001'), $saleAcceptedLogs[0]['context']['trace_id_masked'], 'Sale accepted log should mask trace id.');
patwc_payment_service_assert_logs_hide('1234567890', 'Sale logs must not contain the raw terminal id.');
patwc_payment_service_assert_logs_hide('trace-sync-001', 'Sale logs must not contain the raw trace id.');

patwc_payment_service_reset_uuids(array('attempt-sale-failure', 'idem-sale-failure'));
$GLOBALS['patwc_captured_logs'] = array();
$saleFailureClient = new PatwcPaymentServiceFakeClient();
$saleFailureClient->sale_exception = new RuntimeException('PayArc rejected access_token fixture-access-token.');
$saleFailureService = patwc_payment_service_make_service(patwc_payment_service_settings(array('connect_access_token' => 'fixture-access-token')), $saleFailureClient);
try {
    $saleFailureService->start_payment_for_order(new PatwcPaymentServiceOrder(136));
    throw new RuntimeException('Sale failure should be rethrown.');
} catch (RuntimeException $exception) {
    patwc_payment_service_assert_same('PayArc rejected access_token fixture-access-token.', $exception->getMessage(), 'Sale failure should be rethrown unchanged.');
}
$saleFailureLogs = patwc_payment_service_logs('PayArc sale request failed');
patwc_payment_service_assert_same(1, count($saleFailureLogs), 'Sale failure should emit one error log.');
patwc_payment_service_assert_same('error', $saleFailureLogs[0]['level'], 'Sale failure log should use error level.');
patwc_payment_service_assert_same(RuntimeException::class, $saleFailureLogs[0]['context']['exception_class'], 'Sale failure log should include exception class.');
patwc_payment_service_assert_same('PayArc rejected access_token=[REDACTED]', $saleFailureLogs[0]['context']['message'], 'Sale failure log should redact untrusted exception text.');
patwc_payment_service_assert_logs_hide('fixture-access-token', 'Sale failure logs must not contain access tokens.');
patwc_payment_service_assert_logs_hide('1234567890', 'Sale failure logs must not contain the raw terminal id.');

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
$resolvedLogs = patwc_payment_service_logs('PayArc payment attempt resolved');
patwc_payment_service_assert_same(1, count($resolvedLogs), 'A poll resolving an attempt should emit one resolution log.');
patwc_payment_service_assert_same('success', $resolvedLogs[0]['context']['status'], 'Resolution log should include final status.');
patwc_payment_service_assert_same(Settings::mask_identifier('trace-poll-001'), $resolvedLogs[0]['context']['trace_id_masked'], 'Resolution log should mask trace id.');
patwc_payment_service_assert_logs_hide('trace-poll-001', 'Resolution logs must not contain the raw trace id.');
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
$GLOBALS['patwc_captured_logs'] = array();
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

patwc_payment_service_assert_true(isset($cancelResult['continue_polling']) && $cancelResult['continue_polling'] === true, 'Accepted cancel should tell UI to continue polling.');
$cancelRequestedLogs = patwc_payment_service_logs('PayArc cancel requested');
patwc_payment_service_assert_same(1, count($cancelRequestedLogs), 'Cancel should emit one requested log.');
patwc_payment_service_assert_same(129, $cancelRequestedLogs[0]['context']['order_id'], 'Cancel requested log should include order id.');
patwc_payment_service_assert_same(Settings::mask_identifier('trace-cancel-001'), $cancelRequestedLogs[0]['context']['trace_id_masked'], 'Cancel requested log should mask trace id.');
patwc_payment_service_assert_logs_hide('trace-cancel-001', 'Cancel requested logs must not contain raw trace ids.');

patwc_payment_service_reset_uuids(array('idem-cancel-failure'));
$GLOBALS['patwc_captured_logs'] = array();
$cancelFailureClient = new PatwcPaymentServiceFakeClient();
$cancelFailureClient->cancel_response = new RuntimeException('Cancel rejected token fixture-cancel-token.');
$cancelFailureService = patwc_payment_service_make_service(patwc_payment_service_settings(), $cancelFailureClient);
$cancelFailureOrder = new PatwcPaymentServiceOrder(137);
PaymentAttempt::record_new($cancelFailureOrder, array('status' => 'processing', 'trace_id' => 'trace-cancel-failure', 'transaction_id' => 'txn-cancel-failure', 'terminal_id' => '1234567890'));
try {
    $cancelFailureService->cancel_order_payment($cancelFailureOrder);
    throw new RuntimeException('Cancel failure should be rethrown.');
} catch (RuntimeException $exception) {
    patwc_payment_service_assert_same('Cancel rejected token fixture-cancel-token.', $exception->getMessage(), 'Cancel failure should be rethrown unchanged.');
}
$cancelFailureLogs = patwc_payment_service_logs('PayArc cancel request failed');
patwc_payment_service_assert_same(1, count($cancelFailureLogs), 'Cancel failure should emit one error log.');
patwc_payment_service_assert_same(RuntimeException::class, $cancelFailureLogs[0]['context']['exception_class'], 'Cancel failure log should include exception class.');
patwc_payment_service_assert_same('Cancel rejected token=[REDACTED]', $cancelFailureLogs[0]['context']['message'], 'Cancel failure log should redact untrusted exception text.');
patwc_payment_service_assert_logs_hide('fixture-cancel-token', 'Cancel failure logs must not contain access tokens.');
patwc_payment_service_assert_logs_hide('trace-cancel-failure', 'Cancel failure logs must not contain raw trace ids.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440101', '550e8400-e29b-41d4-a716-446655440102'));
$restartAfterCancelClient = new PatwcPaymentServiceFakeClient();
$restartAfterCancelService = patwc_payment_service_make_service(patwc_payment_service_settings(), $restartAfterCancelClient);
$restartAfterCancelOrder = new PatwcPaymentServiceOrder(131);
$cancelRequestedAttempt = PaymentAttempt::record_new($restartAfterCancelOrder, array('status' => 'cancel_requested', 'trace_id' => 'trace-cancel-pending', 'transaction_id' => 'txn-cancel-pending', 'terminal_id' => '1234567890'));
$restartAfterCancelResult = $restartAfterCancelService->start_payment_for_order($restartAfterCancelOrder);
patwc_payment_service_assert_same('existing_attempt', $restartAfterCancelResult['status'], 'Start after cancel_requested should reuse in-flight attempt.');
patwc_payment_service_assert_same($cancelRequestedAttempt, $restartAfterCancelResult['attempt'], 'Start after cancel_requested should return the current attempt.');
patwc_payment_service_assert_same(0, count($restartAfterCancelClient->sale_calls), 'Start after cancel_requested should not call sale.');

$pollCancelRequestedClient = new PatwcPaymentServiceFakeClient();
$pollCancelRequestedClient->transaction_response = array('traceId' => 'trace-cancel-pending', 'status' => 'CANCELLED');
$pollCancelRequestedReconciler = new PatwcPaymentServiceFakeReconciler();
$pollCancelRequestedService = patwc_payment_service_make_service(patwc_payment_service_settings(), $pollCancelRequestedClient, $pollCancelRequestedReconciler);
$pollCancelRequestedOrder = new PatwcPaymentServiceOrder(132);
PaymentAttempt::record_new($pollCancelRequestedOrder, array('status' => 'cancel_requested', 'trace_id' => 'trace-cancel-pending', 'transaction_id' => 'txn-cancel-pending', 'terminal_id' => '1234567890'));
$pollCancelRequestedResult = $pollCancelRequestedService->poll_order($pollCancelRequestedOrder);
patwc_payment_service_assert_same($pollCancelRequestedReconciler->result, $pollCancelRequestedResult, 'Poll after cancel_requested should reconcile lookup payload.');
patwc_payment_service_assert_same(array(array('trace_id' => 'trace-cancel-pending')), $pollCancelRequestedClient->get_calls, 'Poll after cancel_requested should fetch transaction.');
patwc_payment_service_assert_same(array(array('order_id' => 132, 'payload' => $pollCancelRequestedClient->transaction_response, 'source' => 'poll')), $pollCancelRequestedReconciler->calls, 'Poll after cancel_requested should pass lookup payload to reconciler.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440103', '550e8400-e29b-41d4-a716-446655440104', '550e8400-e29b-41d4-a716-446655440105'));
$overlapClient = new PatwcPaymentServiceFakeClient();
$overlapOrder = new PatwcPaymentServiceOrder(133);
$overlapService = patwc_payment_service_make_service(patwc_payment_service_settings(), $overlapClient);
$nestedCancelResult = null;
$overlapClient->sale_callback = function () use (&$nestedCancelResult, $overlapService, $overlapOrder): void {
    PaymentAttempt::record_new($overlapOrder, array('status' => 'processing', 'trace_id' => 'trace-overlap', 'transaction_id' => 'txn-overlap', 'terminal_id' => '1234567890'));
    $nestedCancelResult = $overlapService->cancel_order_payment($overlapOrder);
};
$overlapService->start_payment_for_order($overlapOrder);
patwc_payment_service_assert_same('conflict', $nestedCancelResult['status'], 'Cancel overlapping a start command should conflict on the shared terminal lock.');
patwc_payment_service_assert_same(0, count($overlapClient->cancel_calls), 'Cancel overlapping a start command should not call PayArc cancel.');

$source = file_get_contents($root . '/includes/Services/PayArcPaymentService.php');
if (!is_string($source) || strpos($source, 'WCPOS\\\\WooCommercePOS\\\\PayArcTerminal\\\\PaymentReconciler') === false) {
    throw new RuntimeException('Default reconciler fallback should reference the root PayArcTerminal PaymentReconciler namespace.');
}

$pollOverlapClient = new PatwcPaymentServiceFakeClient();
$pollOverlapReconciler = new PatwcPaymentServiceFakeReconciler();
$pollOverlapService = patwc_payment_service_make_service(patwc_payment_service_settings(), $pollOverlapClient, $pollOverlapReconciler, function (): int { return 2000; });
$pollOverlapOrder = new PatwcPaymentServiceOrder(134);
PaymentAttempt::record_new($pollOverlapOrder, array('status' => 'processing', 'trace_id' => 'trace-poll-overlap', 'transaction_id' => 'txn-poll-overlap', 'terminal_id' => '1234567890'));
$nestedPollResult = null;
$pollOverlapClient->get_transaction_callback = function () use (&$nestedPollResult, $pollOverlapService, $pollOverlapOrder): void {
    $nestedPollResult = $pollOverlapService->poll_order($pollOverlapOrder);
};
$pollOverlapService->poll_order($pollOverlapOrder);
patwc_payment_service_assert_same('conflict', $nestedPollResult['status'], 'Poll overlapping another poll should conflict on the poll lock.');
patwc_payment_service_assert_true($nestedPollResult['continue_polling'], 'Overlapping poll conflict should tell UI to keep polling.');
patwc_payment_service_assert_same(1, count($pollOverlapClient->get_calls), 'Overlapping poll should not call get_transaction twice.');

patwc_payment_service_reset_uuids(array('550e8400-e29b-41d4-a716-446655440106'));
$codeCancelClient = new PatwcPaymentServiceFakeClient();
$codeCancelClient->cancel_response = new RuntimeException('PayArc request failed; code: TRANSACTION_CANNOT_BE_CANCELLED; friendlyMessage: Transaction cannot be cancelled now.');
$codeCancelClient->transaction_response = array('traceId' => 'trace-code-cancel', 'status' => 'APPROVED');
$codeCancelReconciler = new PatwcPaymentServiceFakeReconciler();
$codeCancelService = patwc_payment_service_make_service(patwc_payment_service_settings(), $codeCancelClient, $codeCancelReconciler);
$codeCancelOrder = new PatwcPaymentServiceOrder(135);
PaymentAttempt::record_new($codeCancelOrder, array('status' => 'processing', 'trace_id' => 'trace-code-cancel', 'transaction_id' => 'txn-code-cancel', 'terminal_id' => '1234567890'));
$codeCancelResult = $codeCancelService->cancel_order_payment($codeCancelOrder);
patwc_payment_service_assert_same($codeCancelReconciler->result, $codeCancelResult, 'Structured cannot-cancel code should fetch and reconcile.');
patwc_payment_service_assert_same(array(array('trace_id' => 'trace-code-cancel')), $codeCancelClient->get_calls, 'Structured cannot-cancel code should fetch transaction.');
patwc_payment_service_assert_same(array(array('order_id' => 135, 'payload' => $codeCancelClient->transaction_response, 'source' => 'cancel_lookup')), $codeCancelReconciler->calls, 'Structured cannot-cancel code should reconcile fetched transaction.');

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

// Verified live 2026-07-23: PayArc accepts a sale (200 + traceId) but GET
// /v3/transactions/{traceId} returns TRANSACTION_NOT_FOUND for several seconds
// until the transaction becomes visible. Polling must treat that window as
// "keep waiting", not as a fatal error.
$notFoundClient = new PatwcPaymentServiceFakeClient();
$notFoundClient->get_transaction_callback = static function (): void {
    throw new WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException('PayArc request failed; HTTP status: 400; code: TRANSACTION_NOT_FOUND.', 'TRANSACTION_NOT_FOUND', 400);
};
$notFoundReconciler = new PatwcPaymentServiceFakeReconciler();
$notFoundService = patwc_payment_service_make_service(patwc_payment_service_settings(), $notFoundClient, $notFoundReconciler);
$notFoundOrder = new PatwcPaymentServiceOrder(220);
PaymentAttempt::record_new($notFoundOrder, array('status' => 'processing', 'trace_id' => 'trace-not-found', 'transaction_id' => 'txn-nf', 'terminal_id' => '1234567890'));
$notFoundResult = $notFoundService->poll_order($notFoundOrder);
patwc_payment_service_assert_same('processing', $notFoundResult['status'], 'TRANSACTION_NOT_FOUND during poll should keep the local in-flight status.');
patwc_payment_service_assert_true($notFoundResult['continue_polling'], 'TRANSACTION_NOT_FOUND during poll should continue polling.');
patwc_payment_service_assert_same(array(), $notFoundReconciler->calls, 'TRANSACTION_NOT_FOUND should not reach the reconciler.');
$notVisibleLogs = patwc_payment_service_logs('PayArc transaction not visible yet; continuing to poll');
patwc_payment_service_assert_same(1, count($notVisibleLogs), 'The not-visible poll window should emit one log entry.');
patwc_payment_service_assert_same(Settings::mask_identifier('trace-not-found'), $notVisibleLogs[0]['context']['trace_id_masked'], 'Not-visible log should mask the trace id.');
patwc_payment_service_assert_logs_hide('trace-not-found', 'Not-visible logs must not contain the raw trace id.');

// Any other PayArc failure during polling must still surface.
$otherErrorClient = new PatwcPaymentServiceFakeClient();
$otherErrorClient->get_transaction_callback = static function (): void {
    throw new WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException('PayArc request failed; HTTP status: 400; code: INVALID_REQUEST.', 'INVALID_REQUEST', 400);
};
$otherErrorService = patwc_payment_service_make_service(patwc_payment_service_settings(), $otherErrorClient);
$otherErrorOrder = new PatwcPaymentServiceOrder(221);
PaymentAttempt::record_new($otherErrorOrder, array('status' => 'processing', 'trace_id' => 'trace-other-error', 'transaction_id' => 'txn-oe', 'terminal_id' => '1234567890'));
try {
    $otherErrorService->poll_order($otherErrorOrder);
    throw new RuntimeException('Non-TRANSACTION_NOT_FOUND poll failures should be rethrown.');
} catch (WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException $exception) {
    patwc_payment_service_assert_same('INVALID_REQUEST', $exception->payarc_code(), 'Other poll failures should surface unchanged.');
}
