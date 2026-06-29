<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

use RuntimeException;
use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;

class WebhookHandler
{
    /** @var Settings */
    private $settings;

    /** @var object */
    private $client;

    /** @var object */
    private $reconciler;

    /** @var callable|null */
    private $order_locator;

    /**
     * @param object|null $client
     * @param object|null $reconciler
     * @param callable|null $order_locator
     */
    public function __construct(?Settings $settings = null, $client = null, $reconciler = null, ?callable $order_locator = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
        $this->client = $client === null ? new PayArcClient($this->settings) : $client;
        $this->reconciler = $reconciler === null ? new PaymentReconciler($this->settings) : $reconciler;
        $this->order_locator = $order_locator;
    }

    public function init(): void
    {
        if (function_exists('add_action')) {
            add_action('wp_ajax_patwc_payarc_callback', array($this, 'handle'));
            add_action('wp_ajax_nopriv_patwc_payarc_callback', array($this, 'handle'));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function handle()
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        $response = $this->handle_request($rawBody, $_SERVER);

        if (function_exists('wp_send_json')) {
            wp_send_json($response['body'], $response['status_code']);

            return null;
        }

        if (function_exists('status_header')) {
            status_header($response['status_code']);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function handle_request(string $rawBody, array $server = array()): array
    {
        if (!$this->is_authorized($server)) {
            return $this->response(401, array('error' => 'unauthorized'));
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->response(400, array('error' => 'invalid_json'));
        }

        $traceId = $this->extract_scalar($payload, 'traceId');
        $transactionId = $this->extract_scalar($payload, 'transactionId');
        $order = $this->locate_order($payload, $traceId, $transactionId);
        if (!is_object($order)) {
            return $this->response(404, array('error' => 'order_not_found'));
        }

        if ($traceId === '') {
            return $this->response(202, array('status' => 'accepted_without_trace'));
        }

        try {
            $transaction = $this->client->get_transaction($traceId);
        } catch (Throwable $exception) {
            return $this->response(502, array('error' => 'transaction_lookup_failed'));
        }

        if (!is_array($transaction)) {
            return $this->response(502, array('error' => 'transaction_lookup_invalid'));
        }

        $result = $this->reconciler->reconcile($order, $transaction, 'webhook');
        if (!is_array($result)) {
            throw new RuntimeException('PayArc payment reconciler must return an array.');
        }

        return $this->response(200, array('status' => 'ok', 'result' => $result));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function is_authorized(array $server): bool
    {
        $expected = $this->settings->callback_bearer_token();
        if ($expected === '') {
            return false;
        }

        $authorization = $this->authorization_header($server);
        if ($authorization === '') {
            return false;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return false;
        }

        $actual = trim($matches[1]);
        if ($actual === '') {
            return false;
        }

        if (function_exists('hash_equals')) {
            return hash_equals($expected, $actual);
        }

        return $expected === $actual;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function authorization_header(array $server): string
    {
        foreach (array('HTTP_AUTHORIZATION', 'Authorization', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
            if (isset($server[$key]) && is_scalar($server[$key]) && trim((string) $server[$key]) !== '') {
                return trim((string) $server[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return object|null
     */
    private function locate_order(array $payload, string $traceId, string $transactionId)
    {
        $criteria = array(
            'order_id' => $this->metadata_order_id($payload),
            'trace_id' => $traceId,
            'transaction_id' => $transactionId,
        );

        if ($this->order_locator !== null) {
            $order = call_user_func($this->order_locator, $criteria);

            return is_object($order) ? $order : null;
        }

        if ($criteria['order_id'] !== '' && function_exists('wc_get_order')) {
            $order = wc_get_order($criteria['order_id']);
            if (is_object($order)) {
                return $order;
            }
        }

        if ($traceId !== '') {
            $order = $this->locate_order_by_meta(PaymentAttempt::META_CURRENT_TRACE_ID, $traceId);
            if (is_object($order)) {
                return $order;
            }
        }

        if ($transactionId !== '') {
            $order = $this->locate_order_by_meta(PaymentAttempt::META_CURRENT_TRANSACTION_ID, $transactionId);
            if (is_object($order)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function metadata_order_id(array $payload): string
    {
        if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
            return '';
        }

        return isset($payload['metadata']['order_id']) && is_scalar($payload['metadata']['order_id']) ? trim((string) $payload['metadata']['order_id']) : '';
    }

    /**
     * @return object|null
     */
    private function locate_order_by_meta(string $metaKey, string $metaValue)
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
        ));

        if (is_array($orders) && isset($orders[0]) && is_object($orders[0])) {
            return $orders[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extract_scalar(array $payload, string $key): string
    {
        return isset($payload[$key]) && is_scalar($payload[$key]) ? trim((string) $payload[$key]) : '';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function response(int $statusCode, array $body): array
    {
        return array('status_code' => $statusCode, 'body' => $body);
    }
}
