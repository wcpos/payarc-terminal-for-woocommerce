<?php

/**
 * Regression tests for PayArc callback webhook authentication and lookup behavior.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcPaymentService;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\WebhookHandler;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/Logger.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/PaymentLock.php',
    $root . '/includes/Services/TerminalService.php',
    $root . '/includes/Services/PayArcPaymentService.php',
    $root . '/includes/WebhookHandler.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

class PatwcWebhookAuthOrder
{
    /** @var int */
    private $id;

    /** @var bool */
    private $paid = false;

    /** @var array<string, mixed> */
    public $meta = array();

    /** @var array<int, string> */
    public $payment_complete_calls = array();

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function get_id(): int
    {
        return $this->id;
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

    public function get_meta($key, $single = true)
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): void
    {
    }
}

class PatwcWebhookAuthFakeClient
{
    /** @var array<int, string> */
    public $get_calls = array();

    /** @var array<string, mixed> */
    public $transaction_response = array(
        'traceId' => 'trace-webhook-1',
        'transactionId' => 'txn-webhook-1',
        'status' => 'SUCCESS',
    );

    /** @var callable|null */
    public $get_transaction_callback = null;

    /**
     * @return array<string, mixed>
     */
    public function get_transaction(string $trace_id): array
    {
        $this->get_calls[] = $trace_id;

        if ($this->get_transaction_callback !== null) {
            call_user_func($this->get_transaction_callback, $trace_id);
        }

        return $this->transaction_response;
    }
}

class PatwcWebhookAuthFakeReconciler
{
    /** @var array<int, array<string, mixed>> */
    public $calls = array();

    /** @var array<string, mixed> */
    public $result = array('status' => 'success', 'continue_polling' => false);

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

function patwc_webhook_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_webhook_handler(PatwcWebhookAuthFakeClient $client, PatwcWebhookAuthFakeReconciler $reconciler, PatwcWebhookAuthOrder $order, string $callbackToken = 'expected-token'): WebhookHandler
{
    $locator = function (array $criteria) use ($order) {
        if (isset($criteria['order_id']) && (string) $criteria['order_id'] === (string) $order->get_id()) {
            return $order;
        }

        if (isset($criteria['trace_id']) && (string) $criteria['trace_id'] !== '' && $order->get_meta(PaymentAttempt::META_CURRENT_TRACE_ID, true) === (string) $criteria['trace_id']) {
            return $order;
        }

        if (isset($criteria['transaction_id']) && (string) $criteria['transaction_id'] !== '' && $order->get_meta(PaymentAttempt::META_CURRENT_TRANSACTION_ID, true) === (string) $criteria['transaction_id']) {
            return $order;
        }

        return null;
    };

    return new WebhookHandler(new Settings(array('callback_bearer_token' => $callbackToken)), $client, $reconciler, $locator);
}

/**
 * @return array<int, array<string, mixed>>
 */
function patwc_webhook_logs(string $message): array
{
    return array_values(array_filter($GLOBALS['patwc_captured_logs'], static function (array $entry) use ($message): bool {
        return $entry['message'] === $message;
    }));
}

function patwc_webhook_assert_logs_hide(string $secret, string $message): void
{
    $encoded = json_encode($GLOBALS['patwc_captured_logs']);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode captured webhook logs.');
    }

    patwc_webhook_assert_same(false, $secret !== '' && strpos($encoded, $secret) !== false, $message);
}

$order = new PatwcWebhookAuthOrder(2001);
PaymentAttempt::record_new($order, array('status' => 'processing', 'trace_id' => 'trace-webhook-1', 'transaction_id' => 'txn-webhook-1'));
$client = new PatwcWebhookAuthFakeClient();
$reconciler = new PatwcWebhookAuthFakeReconciler();
$handler = patwc_webhook_handler($client, $reconciler, $order);
$GLOBALS['patwc_captured_logs'] = array();
$clientAddress = '203.0.113.10';

$unconfiguredHandler = patwc_webhook_handler($client, $reconciler, $order, '');
$unconfigured = $unconfiguredHandler->handle_request('{"traceId":"trace-webhook-1"}', array('HTTP_AUTHORIZATION' => 'Bearer received-token', 'REMOTE_ADDR' => $clientAddress));
patwc_webhook_assert_same(401, $unconfigured['status_code'], 'Unconfigured callback token should return 401.');

$missingAuth = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('REMOTE_ADDR' => $clientAddress));
patwc_webhook_assert_same(401, $missingAuth['status_code'], 'Missing Authorization should return 401.');

$nonBearer = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('HTTP_AUTHORIZATION' => 'Basic expected-token', 'REMOTE_ADDR' => $clientAddress));
patwc_webhook_assert_same(401, $nonBearer['status_code'], 'Non-bearer Authorization should return 401.');

$wrongBearer = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('HTTP_AUTHORIZATION' => 'Bearer wrong-token', 'REMOTE_ADDR' => $clientAddress));
patwc_webhook_assert_same(401, $wrongBearer['status_code'], 'Wrong bearer token should return 401.');
$rejectedLogs = patwc_webhook_logs('PayArc callback rejected');
$rejectionReasons = array_map(static function (array $entry): string {
    return (string) $entry['context']['reason'];
}, $rejectedLogs);
patwc_webhook_assert_same(true, in_array('callback_token_not_configured', $rejectionReasons, true), 'Callback rejection log should identify an unconfigured expected token.');
patwc_webhook_assert_same(true, in_array('missing_authorization_header', $rejectionReasons, true), 'Callback rejection log should identify a missing Authorization header.');
patwc_webhook_assert_same(true, in_array('malformed_authorization_header', $rejectionReasons, true), 'Callback rejection log should identify malformed Authorization.');
patwc_webhook_assert_same(true, in_array('token_mismatch', $rejectionReasons, true), 'Callback rejection log should identify a token mismatch.');
patwc_webhook_assert_logs_hide('received-token', 'Callback rejection logs must not contain a received bearer token.');
patwc_webhook_assert_logs_hide('wrong-token', 'Callback mismatch logs must not contain the mismatched bearer token.');
patwc_webhook_assert_logs_hide('expected-token', 'Callback logs must not contain the configured bearer token.');

$fifthRejection = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('REMOTE_ADDR' => $clientAddress));
$suppressedRejection = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('REMOTE_ADDR' => $clientAddress));
patwc_webhook_assert_same(401, $fifthRejection['status_code'], 'Fifth callback rejection should still return 401.');
patwc_webhook_assert_same(401, $suppressedRejection['status_code'], 'Suppressed callback rejection should still return 401.');
patwc_webhook_assert_same(5, count(patwc_webhook_logs('PayArc callback rejected')), 'Callback rejection warnings should be limited to five per client address per minute.');

$invalidJson = $handler->handle_request('{invalid json', array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(400, $invalidJson['status_code'], 'Valid bearer with invalid JSON should return 400.');

$valid = $handler->handle_request(json_encode(array(
    'traceId' => 'trace-webhook-1',
    'transactionId' => 'txn-webhook-1',
    'status' => 'SUCCESS',
    'metadata' => array('order_id' => '2001'),
)), array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(200, $valid['status_code'], 'Valid final callback with trace id should return 200.');
patwc_webhook_assert_same(array('trace-webhook-1'), $client->get_calls, 'Valid trace callback should fetch authoritative transaction by trace id.');
patwc_webhook_assert_same(array(array('order_id' => 2001, 'payload' => $client->transaction_response, 'source' => 'webhook')), $reconciler->calls, 'Valid trace callback should reconcile fetched transaction payload.');
$acceptedLogs = patwc_webhook_logs('PayArc callback accepted');
patwc_webhook_assert_same(1, count($acceptedLogs), 'Valid callback should emit one accepted log.');
patwc_webhook_assert_same('info', $acceptedLogs[0]['level'], 'Accepted callback log should use info level.');
patwc_webhook_assert_same(Settings::mask_identifier('trace-webhook-1'), $acceptedLogs[0]['context']['trace_id_masked'], 'Accepted callback log should mask trace id.');
patwc_webhook_assert_same('txn-webhook-1', $acceptedLogs[0]['context']['transaction_id'], 'Accepted callback log should include transaction id when provided.');
patwc_webhook_assert_logs_hide('trace-webhook-1', 'Accepted callback logs must not contain raw trace ids.');
patwc_webhook_assert_logs_hide('expected-token', 'Accepted callback logs must not contain the configured bearer token.');

$noTraceOrder = new PatwcWebhookAuthOrder(2002);
PaymentAttempt::record_new($noTraceOrder, array('status' => 'processing', 'transaction_id' => 'txn-no-trace'));
$noTraceClient = new PatwcWebhookAuthFakeClient();
$noTraceReconciler = new PatwcWebhookAuthFakeReconciler();
$noTraceHandler = patwc_webhook_handler($noTraceClient, $noTraceReconciler, $noTraceOrder);
$noTrace = $noTraceHandler->handle_request(json_encode(array(
    'transactionId' => 'txn-no-trace',
    'status' => 'SUCCESS',
    'metadata' => array('order_id' => '2002'),
)), array('Authorization' => 'Bearer expected-token'));
patwc_webhook_assert_same(202, $noTrace['status_code'], 'Valid SUCCESS callback without trace id should return 202.');
patwc_webhook_assert_same(array(), $noTraceClient->get_calls, 'Callback without trace id should not fetch transaction.');
patwc_webhook_assert_same(array(), $noTraceReconciler->calls, 'Callback without trace id should not reconcile raw callback body.');
patwc_webhook_assert_same(array(), $noTraceOrder->payment_complete_calls, 'Callback without trace id should not complete payment from raw body.');


$lockedOrder = new PatwcWebhookAuthOrder(2003);
PaymentAttempt::record_new($lockedOrder, array('status' => 'processing', 'trace_id' => 'trace-lock', 'transaction_id' => 'txn-lock'));
$lockedClient = new PatwcWebhookAuthFakeClient();
$lockedClient->transaction_response = array('traceId' => 'trace-lock', 'transactionId' => 'txn-lock', 'status' => 'SUCCESS');
$lockedReconciler = new PatwcWebhookAuthFakeReconciler();
$lockedHandler = patwc_webhook_handler($lockedClient, $lockedReconciler, $lockedOrder);
$nestedLockResponse = null;
$nestedLockInvoked = false;
$lockedClient->get_transaction_callback = function () use (&$nestedLockResponse, &$nestedLockInvoked, $lockedHandler): void {
    if ($nestedLockInvoked) {
        return;
    }

    $nestedLockInvoked = true;
    $nestedLockResponse = $lockedHandler->handle_request(json_encode(array(
        'traceId' => 'trace-lock',
        'status' => 'SUCCESS',
        'metadata' => array('order_id' => '2003'),
    )), array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
};
$outerLockResponse = $lockedHandler->handle_request(json_encode(array(
    'traceId' => 'trace-lock',
    'status' => 'SUCCESS',
    'metadata' => array('order_id' => '2003'),
)), array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(200, $outerLockResponse['status_code'], 'Outer locked callback should complete normally.');
patwc_webhook_assert_same(202, $nestedLockResponse['status_code'], 'Nested locked callback should return accepted while reconciliation is in progress.');
patwc_webhook_assert_same('in_progress', $nestedLockResponse['body']['status'], 'Nested locked callback should report in_progress.');
patwc_webhook_assert_same(array('trace-lock'), $lockedClient->get_calls, 'Nested locked callback should not fetch a second transaction.');
patwc_webhook_assert_same(1, count($lockedReconciler->calls), 'Nested locked callback should not reconcile a second time.');

$webhookPollOrder = new PatwcWebhookAuthOrder(2004);
PaymentAttempt::record_new($webhookPollOrder, array('status' => 'processing', 'trace_id' => 'trace-webhook-poll-lock', 'transaction_id' => 'txn-webhook-poll-lock'));
$webhookPollClient = new PatwcWebhookAuthFakeClient();
$webhookPollClient->transaction_response = array('traceId' => 'trace-webhook-poll-lock', 'transactionId' => 'txn-webhook-poll-lock', 'status' => 'SUCCESS');
$webhookPollReconciler = new PatwcWebhookAuthFakeReconciler();
$webhookPollHandler = patwc_webhook_handler($webhookPollClient, $webhookPollReconciler, $webhookPollOrder);
$nestedPollClient = new PatwcWebhookAuthFakeClient();
$nestedPollReconciler = new PatwcWebhookAuthFakeReconciler();
$nestedPollService = new PayArcPaymentService(
    new Settings(array('tenant_id' => '123456789012', 'default_terminal_id' => '1234567890')),
    $nestedPollClient,
    new TerminalService(new Settings(array('tenant_id' => '123456789012', 'default_terminal_id' => '1234567890'))),
    $nestedPollReconciler,
    function (): int { return 3000; }
);
$nestedPollResult = null;
$webhookPollClient->get_transaction_callback = function () use (&$nestedPollResult, $nestedPollService, $webhookPollOrder): void {
    $nestedPollResult = $nestedPollService->poll_order($webhookPollOrder);
};
$webhookPollResponse = $webhookPollHandler->handle_request(json_encode(array(
    'traceId' => 'trace-webhook-poll-lock',
    'status' => 'SUCCESS',
    'metadata' => array('order_id' => '2004'),
)), array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(200, $webhookPollResponse['status_code'], 'Webhook should complete normally when nested poll observes reconciliation in progress.');
patwc_webhook_assert_same('conflict', $nestedPollResult['status'], 'Poll overlapping webhook reconciliation should conflict on the shared reconciliation lock.');
patwc_webhook_assert_same(true, $nestedPollResult['continue_polling'], 'Poll overlapping webhook reconciliation should continue polling.');
patwc_webhook_assert_same(array(), $nestedPollClient->get_calls, 'Poll overlapping webhook reconciliation should not fetch transaction.');
patwc_webhook_assert_same(array(), $nestedPollReconciler->calls, 'Poll overlapping webhook reconciliation should not reconcile.');
