<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcPaymentService;

class AjaxHandler
{
    /** @var object */
    private $payment_service;

    /** @var callable|object|null */
    private $order_locator;

    /** @var array<string, mixed>|null */
    private $request_data;

    /**
     * @param object|null $payment_service
     * @param callable|object|null $order_locator
     * @param array<string, mixed>|null $request_data
     */
    public function __construct($payment_service = null, $order_locator = null, ?array $request_data = null)
    {
        $this->payment_service = $payment_service === null ? new PayArcPaymentService() : $payment_service;
        $this->order_locator = $order_locator;
        $this->request_data = $request_data;
    }

    public function init(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('wp_ajax_patwc_start_payment', array($this, 'handle_start'));
        add_action('wp_ajax_nopriv_patwc_start_payment', array($this, 'handle_start'));
        add_action('wp_ajax_patwc_poll_payment', array($this, 'handle_poll'));
        add_action('wp_ajax_nopriv_patwc_poll_payment', array($this, 'handle_poll'));
        add_action('wp_ajax_patwc_cancel_payment', array($this, 'handle_cancel'));
        add_action('wp_ajax_nopriv_patwc_cancel_payment', array($this, 'handle_cancel'));
        add_action('wp_ajax_patwc_validate_settings', array($this, 'handle_validate_settings'));
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    public function handle_start(?array $request = null)
    {
        return $this->handle_lifecycle('start', $request);
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    public function handle_poll(?array $request = null)
    {
        return $this->handle_lifecycle('poll', $request);
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    public function handle_cancel(?array $request = null)
    {
        return $this->handle_lifecycle('cancel', $request);
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    public function handle_validate_settings(?array $request = null)
    {
        $emit = $request === null;
        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return $this->maybe_emit($this->error_response(403, 'Access denied.'), $emit);
        }

        $response = array(
            'status_code' => 200,
            'body' => array(
                'status' => 'ok',
                'diagnostics' => (new Settings())->diagnostics(),
            ),
        );

        return $this->maybe_emit($response, $emit);
    }

    /**
     * @param object $order
     */
    public static function order_token_for($order): string
    {
        $orderId = self::order_id($order);
        $orderKey = self::order_key($order);
        $signature = hash_hmac('sha256', $orderId . '|' . $orderKey, self::token_salt());

        return $orderId . ':' . $signature;
    }

    /**
     * @param object|null $order
     * @param array<string, mixed>|null $request
     */
    public function can_access_order(int $order_id, $order = null, ?array $request = null): bool
    {
        if (function_exists('current_user_can')) {
            if (current_user_can('manage_woocommerce')) {
                return true;
            }

            if (current_user_can('edit_shop_order', $order_id)) {
                return true;
            }
        }

        if ($order === null) {
            $order = $this->locate_order($order_id);
        }

        if ($order === null) {
            return false;
        }

        $request = $this->request($request);
        $token = isset($request['order_token']) && is_scalar($request['order_token']) ? trim((string) $request['order_token']) : '';

        if ($token === '') {
            return false;
        }

        return self::token_matches_order($token, $order_id, $order);
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    private function handle_lifecycle(string $action, ?array $request = null)
    {
        $emit = $request === null;
        $request = $this->request($request);
        $orderId = $this->order_id_from_request($request);

        if ($orderId <= 0) {
            return $this->maybe_emit($this->error_response(400, 'Missing or invalid order id.'), $emit);
        }

        $order = $this->locate_order($orderId);
        if ($order === null) {
            return $this->maybe_emit($this->error_response(404, 'Order not found.'), $emit);
        }

        if (!$this->can_access_order($orderId, $order, $request)) {
            return $this->maybe_emit($this->error_response(403, 'Access denied.'), $emit);
        }

        try {
            if ($action === 'start') {
                $terminalId = isset($request['terminal_id']) && is_scalar($request['terminal_id']) ? trim((string) $request['terminal_id']) : '';
                $body = $this->payment_service->start_payment_for_order($order, $terminalId);
            } elseif ($action === 'poll') {
                $body = $this->payment_service->poll_order($order);
            } else {
                $body = $this->payment_service->cancel_order_payment($order);
            }
        } catch (Throwable $exception) {
            return $this->maybe_emit($this->error_response(500, 'Unable to process payment request.'), $emit);
        }

        return $this->maybe_emit(array('status_code' => 200, 'body' => $this->normalize_body($body, $action)), $emit);
    }

    /**
     * @param array<string, mixed>|null $request
     * @return array<string, mixed>
     */
    private function request(?array $request): array
    {
        if ($request !== null) {
            return $request;
        }

        if ($this->request_data !== null) {
            return $this->request_data;
        }

        return $_REQUEST;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function order_id_from_request(array $request): int
    {
        if (!isset($request['order_id']) || !is_scalar($request['order_id'])) {
            return 0;
        }

        $orderId = trim((string) $request['order_id']);
        if ($orderId === '' || !ctype_digit($orderId)) {
            return 0;
        }

        return (int) $orderId;
    }

    /**
     * @return object|null
     */
    private function locate_order(int $order_id)
    {
        if (is_callable($this->order_locator)) {
            return call_user_func($this->order_locator, $order_id);
        }

        if (is_object($this->order_locator)) {
            foreach (array('locate', 'get_order', 'find') as $method) {
                if (method_exists($this->order_locator, $method)) {
                    return $this->order_locator->{$method}($order_id);
                }
            }
        }

        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);

            return is_object($order) ? $order : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normalize_body(array $body, string $action): array
    {
        $status = isset($body['status']) && is_scalar($body['status']) ? (string) $body['status'] : '';

        if ($status === 'created') {
            $traceId = isset($body['trace_id']) && is_scalar($body['trace_id']) ? trim((string) $body['trace_id']) : '';
            if ($traceId === '') {
                $body['status'] = 'pending_callback';
                $status = 'pending_callback';
            }

            if (!array_key_exists('message', $body)) {
                $body['message'] = 'Payment sent to terminal.';
            }

            if (!array_key_exists('continue_polling', $body)) {
                $body['continue_polling'] = true;
            }
        }

        if ($status === 'success') {
            if (!array_key_exists('submit_form', $body)) {
                $body['submit_form'] = true;
            }

            $body['continue_polling'] = false;
        }

        if (in_array($status, array('decline', 'failure', 'failed'), true)) {
            if (!array_key_exists('retry_allowed', $body)) {
                $body['retry_allowed'] = true;
            }

            $body['continue_polling'] = false;
        }

        if ($status === 'cancel_requested') {
            $body['continue_polling'] = true;
        }

        if (in_array($status, array('existing_attempt', 'pending_callback'), true)) {
            $body['continue_polling'] = true;
        }

        return $this->public_response_body($body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function public_response_body(array $body): array
    {
        $public = array();

        foreach (array('status', 'trace_id', 'message') as $key) {
            if (isset($body[$key]) && is_scalar($body[$key]) && trim((string) $body[$key]) !== '') {
                $public[$key] = (string) $body[$key];
            }
        }

        foreach (array('continue_polling', 'submit_form', 'retry_allowed') as $key) {
            if (array_key_exists($key, $body)) {
                $public[$key] = (bool) $body[$key];
            }
        }

        return $public;
    }

    /**
     * @return array{status_code:int, body:array<string, mixed>}
     */
    private function error_response(int $status_code, string $message): array
    {
        return array(
            'status_code' => $status_code,
            'body' => array(
                'status' => 'error',
                'message' => $message,
            ),
        );
    }

    /**
     * @param array{status_code:int, body:array<string, mixed>} $response
     * @return array{status_code:int, body:array<string, mixed>}|void
     */
    private function maybe_emit(array $response, bool $emit)
    {
        if ($emit && function_exists('wp_send_json')) {
            wp_send_json($response['body'], $response['status_code']);

            return;
        }

        return $response;
    }

    /**
     * @param object $order
     */
    private static function token_matches_order(string $token, int $order_id, $order): bool
    {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] !== (string) $order_id || $parts[1] === '') {
            return false;
        }

        $expected = self::order_token_for($order);
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $token);
        }

        return $expected === $token;
    }

    /**
     * @param object $order
     */
    private static function order_id($order): int
    {
        if (is_object($order) && method_exists($order, 'get_id')) {
            return (int) $order->get_id();
        }

        return isset($order->id) ? (int) $order->id : 0;
    }

    /**
     * @param object $order
     */
    private static function order_key($order): string
    {
        if (is_object($order) && method_exists($order, 'get_order_key')) {
            return (string) $order->get_order_key();
        }

        return isset($order->order_key) && is_scalar($order->order_key) ? (string) $order->order_key : '';
    }

    private static function token_salt(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        return 'patwc-local-test-salt';
    }
}
