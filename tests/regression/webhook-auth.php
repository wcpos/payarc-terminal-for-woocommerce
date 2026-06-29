<?php

/**
 * Regression tests for PayArc callback webhook authentication and lookup behavior.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\WebhookHandler;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/PaymentAttempt.php',
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

    /**
     * @return array<string, mixed>
     */
    public function get_transaction(string $trace_id): array
    {
        $this->get_calls[] = $trace_id;

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

function patwc_webhook_handler(PatwcWebhookAuthFakeClient $client, PatwcWebhookAuthFakeReconciler $reconciler, PatwcWebhookAuthOrder $order): WebhookHandler
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

    return new WebhookHandler(new Settings(array('callback_bearer_token' => 'expected-token')), $client, $reconciler, $locator);
}

$order = new PatwcWebhookAuthOrder(2001);
PaymentAttempt::record_new($order, array('status' => 'processing', 'trace_id' => 'trace-webhook-1', 'transaction_id' => 'txn-webhook-1'));
$client = new PatwcWebhookAuthFakeClient();
$reconciler = new PatwcWebhookAuthFakeReconciler();
$handler = patwc_webhook_handler($client, $reconciler, $order);

$missingAuth = $handler->handle_request('{"traceId":"trace-webhook-1"}', array());
patwc_webhook_assert_same(401, $missingAuth['status_code'], 'Missing Authorization should return 401.');

$nonBearer = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('HTTP_AUTHORIZATION' => 'Basic expected-token'));
patwc_webhook_assert_same(401, $nonBearer['status_code'], 'Non-bearer Authorization should return 401.');

$wrongBearer = $handler->handle_request('{"traceId":"trace-webhook-1"}', array('HTTP_AUTHORIZATION' => 'Bearer wrong-token'));
patwc_webhook_assert_same(401, $wrongBearer['status_code'], 'Wrong bearer token should return 401.');

$invalidJson = $handler->handle_request('{invalid json', array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(400, $invalidJson['status_code'], 'Valid bearer with invalid JSON should return 400.');

$valid = $handler->handle_request(json_encode(array(
    'traceId' => 'trace-webhook-1',
    'status' => 'SUCCESS',
    'metadata' => array('order_id' => '2001'),
)), array('HTTP_AUTHORIZATION' => 'Bearer expected-token'));
patwc_webhook_assert_same(200, $valid['status_code'], 'Valid final callback with trace id should return 200.');
patwc_webhook_assert_same(array('trace-webhook-1'), $client->get_calls, 'Valid trace callback should fetch authoritative transaction by trace id.');
patwc_webhook_assert_same(array(array('order_id' => 2001, 'payload' => $client->transaction_response, 'source' => 'webhook')), $reconciler->calls, 'Valid trace callback should reconcile fetched transaction payload.');

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
