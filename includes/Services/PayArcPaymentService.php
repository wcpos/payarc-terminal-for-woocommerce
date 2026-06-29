<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;
use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\PayArcIds;

class PayArcPaymentService
{
    private const META_LAST_POLL_AT = '_patwc_last_poll_at';
    private const POLL_THROTTLE_SECONDS = 2;

    /** @var Settings */
    private $settings;

    /** @var object */
    private $client;

    /** @var TerminalService */
    private $terminal_service;

    /** @var object|null */
    private $reconciler;

    /** @var callable|null */
    private $clock;

    /**
     * @param object|null $client
     * @param object|null $reconciler
     */
    public function __construct(?Settings $settings = null, $client = null, ?TerminalService $terminal_service = null, $reconciler = null, ?callable $clock = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
        $this->client = $client === null ? new PayArcClient($this->settings) : $client;
        $this->terminal_service = $terminal_service === null ? new TerminalService($this->settings) : $terminal_service;
        $this->reconciler = $reconciler;
        $this->clock = $clock;
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return array<string, mixed>
     */
    public function start_payment_for_order($order, string $terminal_id = ''): array
    {
        if (method_exists($order, 'is_paid') && $order->is_paid()) {
            return array('status' => 'already_paid', 'continue_polling' => false);
        }

        return PaymentLock::with_lock($this->order_id($order), 'start', function () use ($order, $terminal_id): array {
            $current = PaymentAttempt::current($order);
            $currentStatus = isset($current['status']) ? (string) $current['status'] : '';

            if ($this->has_existing_attempt($current) && PaymentAttempt::is_non_final($currentStatus)) {
                return array('status' => 'existing_attempt', 'attempt' => $current, 'continue_polling' => true);
            }

            $terminal = $terminal_id === ''
                ? $this->terminal_service->validate_default_terminal()
                : $this->terminal_service->validate_terminal($terminal_id);

            $attemptUuid = PayArcIds::idempotency_key();
            $transactionId = PayArcIds::transaction_id($this->order_id($order), $attemptUuid);
            $idempotencyKey = PayArcIds::idempotency_key();
            $payload = array(
                'tenantId' => $terminal['tenantId'],
                'terminalId' => $terminal['terminalId'],
                'transactionId' => $transactionId,
                'tenderType' => $this->settings->tender_type(),
                'amount' => Money::to_payarc_amount_object($this->order_total($order), $this->order_currency($order)),
                'printReceipt' => $this->settings->print_receipt(),
                'callbackURL' => $this->settings->webhook_url(),
                'metadata' => array(
                    'order_id' => $this->order_id($order),
                    'terminal_id' => $terminal['terminalId'],
                    'mode' => $this->settings->mode(),
                ),
            );

            $response = $this->client->sale($payload, $idempotencyKey);
            $attempt = array(
                'attempt_uuid' => $attemptUuid,
                'transaction_id' => $transactionId,
                'terminal_id' => $terminal['terminalId'],
                'status' => 'created',
                'sale_response' => $response,
            );

            $traceId = $this->extract_scalar($response, 'traceId');
            if ($traceId !== '') {
                $attempt['trace_id'] = $traceId;
            }

            return PaymentAttempt::record_new($order, $attempt);
        });
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return array<string, mixed>
     */
    public function poll_order($order): array
    {
        $attempt = PaymentAttempt::current($order);
        $status = isset($attempt['status']) ? (string) $attempt['status'] : 'created';
        $traceId = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';

        if ($traceId === '') {
            $attempt['status'] = 'pending_callback';
            $attempt['continue_polling'] = true;

            return $attempt;
        }

        if (!PaymentAttempt::is_non_final($status)) {
            $attempt['continue_polling'] = false;

            return $attempt;
        }

        if ($this->is_poll_throttled($order)) {
            $attempt['continue_polling'] = true;

            return $attempt;
        }

        $this->store_last_poll_at($order, $this->now());
        $payload = $this->client->get_transaction($traceId);

        return $this->reconcile($order, $payload, 'poll');
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return array<string, mixed>
     */
    public function cancel_order_payment($order): array
    {
        return PaymentLock::with_lock($this->order_id($order), 'cancel', function () use ($order): array {
            $attempt = PaymentAttempt::current($order);
            $status = isset($attempt['status']) ? (string) $attempt['status'] : 'created';
            $traceId = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';

            if ($traceId === '') {
                return array(
                    'status' => 'not_cancelable_without_trace',
                    'message' => 'PayArc did not provide a trace id yet. Wait for the callback or poll before cancelling this payment.',
                    'continue_polling' => true,
                );
            }

            if (!PaymentAttempt::is_non_final($status)) {
                $attempt['continue_polling'] = false;

                return $attempt;
            }

            $terminalId = isset($attempt['terminal_id']) && is_scalar($attempt['terminal_id']) && trim((string) $attempt['terminal_id']) !== ''
                ? trim((string) $attempt['terminal_id'])
                : $this->settings->default_terminal_id();
            $terminal = $this->terminal_service->validate_terminal($terminalId);

            try {
                $this->client->cancel($traceId, $terminal, PayArcIds::idempotency_key());
            } catch (Throwable $exception) {
                if ($this->is_already_processed_error($exception)) {
                    $payload = $this->client->get_transaction($traceId);

                    return $this->reconcile($order, $payload, 'cancel_lookup');
                }

                throw $exception;
            }

            return PaymentAttempt::update_status($order, 'cancel_requested');
        });
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private function has_existing_attempt(array $attempt): bool
    {
        foreach (array('trace_id', 'transaction_id') as $key) {
            if (isset($attempt[$key]) && is_scalar($attempt[$key]) && trim((string) $attempt[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object $order
     */
    private function order_id($order): int
    {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            throw new RuntimeException('Order id is unavailable.');
        }

        return (int) $order->get_id();
    }

    /**
     * @param object $order
     * @return mixed
     */
    private function order_total($order)
    {
        if (!is_object($order) || !method_exists($order, 'get_total')) {
            throw new RuntimeException('Order total is unavailable.');
        }

        return $order->get_total();
    }

    /**
     * @param object $order
     */
    private function order_currency($order): string
    {
        if (!is_object($order) || !method_exists($order, 'get_currency')) {
            throw new RuntimeException('Order currency is unavailable.');
        }

        return (string) $order->get_currency();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extract_scalar(array $payload, string $key): string
    {
        return isset($payload[$key]) && is_scalar($payload[$key]) ? trim((string) $payload[$key]) : '';
    }

    /**
     * @param object $order
     */
    private function is_poll_throttled($order): bool
    {
        $lastPollAt = $this->get_order_meta($order, self::META_LAST_POLL_AT);

        return is_numeric($lastPollAt) && ($this->now() - (int) $lastPollAt) < self::POLL_THROTTLE_SECONDS;
    }

    /**
     * @param object $order
     */
    private function store_last_poll_at($order, int $timestamp): void
    {
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(self::META_LAST_POLL_AT, $timestamp);
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * @param object $order
     * @return mixed
     */
    private function get_order_meta($order, string $key)
    {
        if (method_exists($order, 'get_meta')) {
            return $order->get_meta($key, true);
        }

        return '';
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return (int) call_user_func($this->clock);
        }

        return time();
    }

    /**
     * @param object $order
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function reconcile($order, array $payload, string $source): array
    {
        $reconciler = $this->reconciler;

        if ($reconciler === null && class_exists(__NAMESPACE__ . '\\PaymentReconciler')) {
            $class = __NAMESPACE__ . '\\PaymentReconciler';
            $reconciler = new $class($this->settings);
        }

        if ($reconciler === null || !method_exists($reconciler, 'reconcile')) {
            throw new RuntimeException('PayArc payment reconciliation is required but no reconciler is configured.');
        }

        $result = $reconciler->reconcile($order, $payload, $source);

        if (!is_array($result)) {
            throw new RuntimeException('PayArc payment reconciler must return an array.');
        }

        return $result;
    }

    private function is_already_processed_error(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return strpos($message, 'already processed') !== false
            || strpos($message, 'already been processed') !== false
            || strpos($message, 'transaction processed') !== false;
    }
}
