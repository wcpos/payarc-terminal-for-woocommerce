<?php

/**
 * Regression tests for PayArc AJAX order access and response contracts.
 */

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

$root = dirname(__DIR__, 2);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/AjaxHandler.php',
) as $file) {
    if (!is_readable($file)) {
        throw new RuntimeException('Required class file is missing: ' . basename($file));
    }

    require_once $file;
}

if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        $action = array(
            'hook' => (string) $hook_name,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
        $GLOBALS['patwc_ajax_actions'][] = $action;
        $GLOBALS['patwc_actions'][] = array(
            'hook' => (string) $hook_name,
            'callback' => $callback,
        );

        return true;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        $key = (string) $capability;
        if (!empty($args)) {
            $key .= ':' . implode(':', array_map('strval', $args));
        }

        return !empty($GLOBALS['patwc_ajax_caps'][$key]) || !empty($GLOBALS['patwc_ajax_caps'][(string) $capability]);
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth')
    {
        return 'patwc-test-salt-' . (string) $scheme;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return !empty($GLOBALS['patwc_ajax_valid_nonces'][(string) $action])
            && $GLOBALS['patwc_ajax_valid_nonces'][(string) $action] === (string) $nonce
            ? 1
            : false;
    }
}


class PatwcAjaxOrder
{
    /** @var int */
    private $id;

    /** @var string */
    private $key;

    public function __construct(int $id, string $key)
    {
        $this->id = $id;
        $this->key = $key;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_order_key(): string
    {
        return $this->key;
    }
}

class PatwcAjaxFakePaymentService
{
    /** @var array<int, array<string, mixed>> */
    public $start_calls = array();

    /** @var array<int, int> */
    public $poll_calls = array();

    /** @var array<int, int> */
    public $cancel_calls = array();

    /** @var array<string, mixed> */
    public $start_response = array('status' => 'created', 'trace_id' => 'trace-created');

    /** @var array<string, mixed> */
    public $poll_response = array('status' => 'success');

    /** @var array<string, mixed> */
    public $cancel_response = array('status' => 'cancel_requested');

    public function start_payment_for_order($order, string $terminal_id = ''): array
    {
        $this->start_calls[] = array('order_id' => $order->get_id(), 'terminal_id' => $terminal_id);

        return $this->start_response;
    }

    public function poll_order($order): array
    {
        $this->poll_calls[] = $order->get_id();

        return $this->poll_response;
    }

    public function cancel_order_payment($order): array
    {
        $this->cancel_calls[] = $order->get_id();

        return $this->cancel_response;
    }
}


class PatwcAjaxFakeConnectionService
{
    /** @var array<int, array<string, mixed>> */
    public $connect_calls = array();

    /** @var int */
    public $refresh_calls = 0;

    /** @var int */
    public $disconnect_calls = 0;

    /** @var array<string, mixed> */
    public $connect_response = array(
        'status' => 'connected',
        'message' => 'Connected to PayArc.',
        'tenant_id_configured' => true,
        'default_terminal_id_configured' => true,
        'terminal_count' => 1,
        'terminals' => array(array('terminal_id' => '1850528139', 'label' => 'Front Counter ••••••8139')),
    );

    public function connect(array $overrides = array()): array
    {
        $this->connect_calls[] = $overrides;

        return $this->connect_response;
    }

    public function refresh_terminals(): array
    {
        $this->refresh_calls++;

        return $this->connect_response;
    }

    public function disconnect(): array
    {
        $this->disconnect_calls++;

        return array('status' => 'disconnected', 'message' => 'Disconnected from PayArc.');
    }
}

function patwc_ajax_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_ajax_assert_true($actual, string $message): void
{
    patwc_ajax_assert_same(true, (bool) $actual, $message);
}

function patwc_ajax_assert_missing_keys(array $body, array $keys, string $message): void
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $body)) {
            throw new RuntimeException($message . ' Unexpected key present: ' . $key . '. Body: ' . var_export($body, true));
        }
    }
}

function patwc_ajax_reset_env(): void
{
    $GLOBALS['patwc_ajax_actions'] = array();
    $GLOBALS['patwc_ajax_caps'] = array();
    $GLOBALS['patwc_ajax_valid_nonces'] = array(
        'patwc_payment' => 'valid-payment-nonce',
        'patwc_validate_settings' => 'valid-settings-nonce',
    );
}

/**
 * @param array<int, PatwcAjaxOrder> $orders
 */
function patwc_ajax_handler(PatwcAjaxFakePaymentService $service, array $orders): AjaxHandler
{
    $locator = function (int $order_id) use ($orders) {
        return $orders[$order_id] ?? null;
    };

    return new AjaxHandler($service, $locator);
}

$order = new PatwcAjaxOrder(1001, 'wc_order_alpha');
$otherOrder = new PatwcAjaxOrder(1002, 'wc_order_bravo');
$orders = array(1001 => $order, 1002 => $otherOrder);

patwc_ajax_reset_env();
$service = new PatwcAjaxFakePaymentService();
$handler = patwc_ajax_handler($service, $orders);
$handler->init();
$registeredHooks = array_map(static function (array $action): string {
    return $action['hook'];
}, $GLOBALS['patwc_ajax_actions']);
sort($registeredHooks);
patwc_ajax_assert_same(array(
    'wp_ajax_nopriv_patwc_cancel_payment',
    'wp_ajax_nopriv_patwc_poll_payment',
    'wp_ajax_nopriv_patwc_start_payment',
    'wp_ajax_patwc_cancel_payment',
    'wp_ajax_patwc_connect_payarc',
    'wp_ajax_patwc_disconnect_payarc',
    'wp_ajax_patwc_poll_payment',
    'wp_ajax_patwc_refresh_payarc_terminals',
    'wp_ajax_patwc_start_payment',
    'wp_ajax_patwc_validate_settings',
), $registeredHooks, 'init should register lifecycle, privileged validation, and privileged PayArc connection routes.');

$unauthorizedValidate = $handler->handle_validate_settings(array());
patwc_ajax_assert_same(403, $unauthorizedValidate['status_code'], 'Validate settings should require WooCommerce manager capability.');
patwc_ajax_assert_true(!isset($unauthorizedValidate['body']['diagnostics']), 'Unauthorized validate settings should not include diagnostics.');

$GLOBALS['patwc_ajax_caps'] = array('manage_woocommerce' => true);
$missingNonceStart = $handler->handle_start(array('order_id' => '1001'));
patwc_ajax_assert_same(403, $missingNonceStart['status_code'], 'Manager lifecycle without nonce should be rejected.');
patwc_ajax_assert_same(array(), $service->start_calls, 'Missing lifecycle nonce should not call payment service.');

$invalidNonceStart = $handler->handle_start(array('order_id' => '1001', '_ajax_nonce' => 'invalid-payment-nonce'));
patwc_ajax_assert_same(403, $invalidNonceStart['status_code'], 'Manager lifecycle with invalid nonce should be rejected.');
patwc_ajax_assert_same(array(), $service->start_calls, 'Invalid lifecycle nonce should not call payment service.');

$service->start_response = array(
    'status' => 'created',
    'trace_id' => 'trace-created',
    'attempt_uuid' => 'attempt-secret',
    'transaction_id' => 'txn-secret',
    'terminal_id' => 'terminal-secret',
    'sale_response' => array('raw' => 'provider payload'),
    'attempt' => array('nested' => 'attempt payload'),
    'provider_response' => array('raw' => 'provider response'),
    'card' => array('last4' => '4242'),
    'processor' => array('code' => '00'),
    'unexpected_internal' => 'do-not-emit',
);
$start = $handler->handle_start(array('order_id' => '1001', 'terminal_id' => 'term-1', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $start['status_code'], 'Manager should start payment.');
patwc_ajax_assert_same(array(array('order_id' => 1001, 'terminal_id' => 'term-1')), $service->start_calls, 'Start should call payment service with terminal id.');
patwc_ajax_assert_same('trace-created', $start['body']['trace_id'], 'Created response with a real trace should include non-empty trace id.');
patwc_ajax_assert_true(isset($start['body']['trace_id']) && is_string($start['body']['trace_id']) && trim($start['body']['trace_id']) !== '', 'Created response should not have an empty trace id.');
patwc_ajax_assert_same('Payment sent to terminal.', $start['body']['message'], 'Created response should include default user message.');
patwc_ajax_assert_same(true, $start['body']['continue_polling'], 'Created response should continue polling.');
patwc_ajax_assert_missing_keys($start['body'], array('attempt_uuid', 'transaction_id', 'terminal_id', 'sale_response', 'attempt', 'provider_response', 'card', 'processor', 'unexpected_internal'), 'Start response should omit internal service fields.');

$service->poll_response = array('status' => 'success', 'transaction_id' => 'txn-secret', 'processor' => array('code' => '00'), 'unexpected_internal' => 'do-not-emit');
$poll = $handler->handle_poll(array('order_id' => '1001', 'nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $poll['status_code'], 'Manager should poll payment.');
patwc_ajax_assert_same(array(1001), $service->poll_calls, 'Poll should call payment service.');
patwc_ajax_assert_same(true, $poll['body']['submit_form'], 'Success response should submit form.');
patwc_ajax_assert_same(false, $poll['body']['continue_polling'], 'Success response should stop polling.');
patwc_ajax_assert_missing_keys($poll['body'], array('transaction_id', 'processor', 'unexpected_internal'), 'Poll response should omit internal service fields.');

$service->cancel_response = array('status' => 'cancel_requested', 'terminal_id' => 'terminal-secret', 'attempt' => array('nested' => 'attempt payload'), 'unexpected_internal' => 'do-not-emit');
$cancel = $handler->handle_cancel(array('order_id' => '1001', 'security' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $cancel['status_code'], 'Manager should cancel payment.');
patwc_ajax_assert_same(array(1001), $service->cancel_calls, 'Cancel should call payment service.');
patwc_ajax_assert_same(true, $cancel['body']['continue_polling'], 'Cancel requested should continue polling.');
patwc_ajax_assert_missing_keys($cancel['body'], array('terminal_id', 'attempt', 'unexpected_internal'), 'Cancel response should omit internal service fields.');

patwc_ajax_reset_env();
$service = new PatwcAjaxFakePaymentService();
$handler = patwc_ajax_handler($service, $orders);
$GLOBALS['patwc_ajax_caps'] = array('edit_shop_order:1001' => true);
$editAccess = $handler->handle_poll(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $editAccess['status_code'], 'User who can edit the shop order should access lifecycle actions.');
patwc_ajax_assert_same(array(1001), $service->poll_calls, 'Edit access should call poll service.');

patwc_ajax_reset_env();
$service = new PatwcAjaxFakePaymentService();
$handler = patwc_ajax_handler($service, $orders);
$token = AjaxHandler::order_token_for($order);
$tokenWithoutNonce = $handler->handle_start(array('order_id' => '1001', 'order_token' => $token));
patwc_ajax_assert_same(403, $tokenWithoutNonce['status_code'], 'Order token lifecycle without nonce should be rejected.');
patwc_ajax_assert_same(array(), $service->start_calls, 'Order token without nonce should not call payment service.');

$tokenStart = $handler->handle_start(array('order_id' => '1001', 'order_token' => $token, '_ajax_nonce' => 'valid-payment-nonce'));
$tokenPoll = $handler->handle_poll(array('order_id' => '1001', 'order_token' => $token, 'nonce' => 'valid-payment-nonce'));
$tokenCancel = $handler->handle_cancel(array('order_id' => '1001', 'order_token' => $token, 'security' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $tokenStart['status_code'], 'Valid order token should start matching order.');
patwc_ajax_assert_same(200, $tokenPoll['status_code'], 'Valid order token should poll matching order.');
patwc_ajax_assert_same(200, $tokenCancel['status_code'], 'Valid order token should cancel matching order.');
patwc_ajax_assert_same(array(array('order_id' => 1001, 'terminal_id' => '')), $service->start_calls, 'Token start should call service.');
patwc_ajax_assert_same(array(1001), $service->poll_calls, 'Token poll should call service.');
patwc_ajax_assert_same(array(1001), $service->cancel_calls, 'Token cancel should call service.');

$wrongOrderToken = AjaxHandler::order_token_for($otherOrder);
$wrongOrder = $handler->handle_start(array('order_id' => '1001', 'order_token' => $wrongOrderToken, '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(403, $wrongOrder['status_code'], 'Token for a different order should be rejected.');

$invalidToken = $handler->handle_start(array('order_id' => '1001', 'order_token' => '1001:not-a-valid-signature', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(403, $invalidToken['status_code'], 'Invalid token should be rejected.');

$missingOrderId = $handler->handle_start(array('order_token' => $token, '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(400, $missingOrderId['status_code'], 'Missing order id should be rejected.');

$notFound = $handler->handle_start(array('order_id' => '9999', 'order_token' => '9999:anything', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(404, $notFound['status_code'], 'Nonexistent order should be rejected.');

patwc_ajax_reset_env();
$service = new PatwcAjaxFakePaymentService();
$handler = patwc_ajax_handler($service, $orders);
$GLOBALS['patwc_ajax_caps'] = array('manage_woocommerce' => true);
$service->start_response = array('status' => 'created');
$createdWithoutTrace = $handler->handle_start(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(200, $createdWithoutTrace['status_code'], 'Created without synchronous trace should remain a successful pending response.');
patwc_ajax_assert_same('pending_callback', $createdWithoutTrace['body']['status'], 'Created without trace should be normalized to pending callback.');
patwc_ajax_assert_same('Payment sent to terminal.', $createdWithoutTrace['body']['message'], 'Pending callback should preserve safe user message.');
patwc_ajax_assert_same(true, $createdWithoutTrace['body']['continue_polling'], 'Pending callback should continue polling.');
patwc_ajax_assert_true(!isset($createdWithoutTrace['body']['trace_id']) || trim((string) $createdWithoutTrace['body']['trace_id']) === '', 'Pending callback should not invent a trace id.');

$service->start_response = array('status' => 'decline');
$decline = $handler->handle_start(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(true, $decline['body']['retry_allowed'], 'Decline response should allow retry.');
patwc_ajax_assert_same(false, $decline['body']['continue_polling'], 'Decline response should stop polling.');

$service->poll_response = array('status' => 'existing_attempt');
$existing = $handler->handle_poll(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(true, $existing['body']['continue_polling'], 'Existing attempt should continue polling.');

$service->poll_response = array('status' => 'pending_callback');
$pending = $handler->handle_poll(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
patwc_ajax_assert_same(true, $pending['body']['continue_polling'], 'Pending callback should continue polling.');

$startCallsBeforeValidate = $service->start_calls;
$validateMissingNonce = $handler->handle_validate_settings(array());
patwc_ajax_assert_same(403, $validateMissingNonce['status_code'], 'Validate settings should require nonce for managers.');
patwc_ajax_assert_true(!isset($validateMissingNonce['body']['diagnostics']), 'Validate settings without nonce should not include diagnostics.');

$validate = $handler->handle_validate_settings(array('_ajax_nonce' => 'valid-settings-nonce'));
patwc_ajax_assert_same(200, $validate['status_code'], 'Validate settings should return success status.');
patwc_ajax_assert_same('ok', $validate['body']['status'], 'Validate settings should return local ok status.');
patwc_ajax_assert_same(Settings::GATEWAY_ID, $validate['body']['diagnostics']['gateway_id'], 'Validate settings should include local diagnostics.');
patwc_ajax_assert_same($startCallsBeforeValidate, $service->start_calls, 'Validate settings should not call PayArc payment service.');

try {
    $throwingService = new class extends PatwcAjaxFakePaymentService {
        public function start_payment_for_order($order, string $terminal_id = ''): array
        {
            throw new RuntimeException('secret-token leaked');
        }
    };
    $throwingHandler = patwc_ajax_handler($throwingService, $orders);
    $exceptionResponse = $throwingHandler->handle_start(array('order_id' => '1001', '_ajax_nonce' => 'valid-payment-nonce'));
    patwc_ajax_assert_same(500, $exceptionResponse['status_code'], 'Exceptions should return 500.');
    patwc_ajax_assert_same('Unable to process payment request.', $exceptionResponse['body']['message'], 'Exceptions should not leak raw messages.');
} finally {
    // no-op; keeps the exception regression close to the handler contract.
}



patwc_ajax_reset_env();
$service = new PatwcAjaxFakePaymentService();
$connectionService = new PatwcAjaxFakeConnectionService();
$handler = new AjaxHandler($service, static function (int $order_id) use ($orders) {
    return $orders[$order_id] ?? null;
}, null, $connectionService);
$GLOBALS['patwc_ajax_caps'] = array('manage_woocommerce' => true);
$GLOBALS['patwc_ajax_valid_nonces']['patwc_payarc_connection'] = 'valid-connection-nonce';
$connect = $handler->handle_connect_payarc(array(
    '_ajax_nonce' => 'valid-connection-nonce',
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
));
patwc_ajax_assert_same(200, $connect['status_code'], 'Manager should connect PayArc settings.');
patwc_ajax_assert_same('connected', $connect['body']['status'], 'Connect endpoint should return connected status.');
patwc_ajax_assert_same(1, $connect['body']['terminal_count'], 'Connect endpoint should expose sanitized terminal count.');
patwc_ajax_assert_same(array(array(
    'connect_email' => 'merchant@example.com',
    'connect_mid' => '0000123456789012',
    'connect_client_secret' => 'client-secret',
    'connect_secret_key' => 'merchant-api-token',
)), $connectionService->connect_calls, 'Connect endpoint should pass unsaved credentials to connection service.');
patwc_ajax_assert_missing_keys($connect['body'], array('connect_secret_key', 'connect_client_secret', 'connect_access_token', 'api_bearer_token'), 'Connect response should omit secrets.');

$refresh = $handler->handle_refresh_payarc_terminals(array('_ajax_nonce' => 'valid-connection-nonce'));
patwc_ajax_assert_same(200, $refresh['status_code'], 'Manager should refresh PayArc terminals.');
patwc_ajax_assert_same(1, $connectionService->refresh_calls, 'Refresh endpoint should call connection service.');

$disconnect = $handler->handle_disconnect_payarc(array('_ajax_nonce' => 'valid-connection-nonce'));
patwc_ajax_assert_same(200, $disconnect['status_code'], 'Manager should disconnect PayArc.');
patwc_ajax_assert_same(1, $connectionService->disconnect_calls, 'Disconnect endpoint should call connection service.');

$unauthorizedConnect = $handler->handle_connect_payarc(array('_ajax_nonce' => 'valid-connection-nonce'));
$GLOBALS['patwc_ajax_caps'] = array();
$unauthorizedConnect = $handler->handle_connect_payarc(array('_ajax_nonce' => 'valid-connection-nonce'));
patwc_ajax_assert_same(403, $unauthorizedConnect['status_code'], 'Connect endpoint should require manager capability.');
