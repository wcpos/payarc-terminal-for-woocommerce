<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;
use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Logger;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\PayArcIds;

class PayArcPaymentService
{
    private const META_LAST_POLL_AT = '_patwc_last_poll_at';
    private const POLL_THROTTLE_SECONDS = 2;
    private const RECONCILIATION_LOCK = 'reconcile';

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

        return PaymentLock::with_lock($this->order_id($order), 'terminal', function () use ($order, $terminal_id): array {
            $current = PaymentAttempt::current($order);
            $currentStatus = isset($current['status']) ? (string) $current['status'] : '';

            if ($this->has_existing_attempt($current) && $this->is_in_flight_status($currentStatus)) {
                return array('status' => 'existing_attempt', 'attempt' => $current, 'continue_polling' => true);
            }

            $terminal = $terminal_id === ''
                ? $this->terminal_service->validate_default_terminal()
                : $this->terminal_service->validate_terminal($terminal_id);

            $attemptUuid = PayArcIds::idempotency_key();
            $transactionId = PayArcIds::transaction_id($this->order_id($order), $attemptUuid);
            $idempotencyKey = PayArcIds::idempotency_key();
            $amount = Money::to_payarc_amount_object($this->order_total($order), $this->order_currency($order));
            $payload = array(
                'tenantId' => $terminal['tenantId'],
                'terminalId' => $terminal['terminalId'],
                'transactionId' => $transactionId,
                'tenderType' => $this->settings->tender_type(),
                'amount' => $amount,
                'printReceipt' => $this->settings->print_receipt(),
                'callbackURL' => $this->settings->webhook_url(),
                'metadata' => array(
                    'order_id' => $this->order_id($order),
                    'terminal_id' => $terminal['terminalId'],
                    'mode' => $this->settings->mode(),
                ),
            );

            $attempt = array(
                'attempt_uuid' => $attemptUuid,
                'transaction_id' => $transactionId,
                'terminal_id' => $terminal['terminalId'],
                'status' => 'created',
            );
            PaymentAttempt::mark_in_flight($order, $attempt);

            $this->log('PayArc sale started', array(
                'order_id' => $this->order_id($order),
                'mode' => $this->settings->mode(),
                'terminal_id_masked' => Settings::mask_identifier($terminal['terminalId']),
                'tender_type' => $this->settings->tender_type(),
                'amount_total' => $amount['total'],
                'currency' => $amount['currency'],
                'transaction_id' => $transactionId,
                'has_callback_url' => trim((string) $payload['callbackURL']) !== '',
            ));

            try {
                $response = $this->client->sale($payload, $idempotencyKey);
            } catch (Throwable $exception) {
                PaymentAttempt::clear_in_flight($order);
                $this->log('PayArc sale request failed', array(
                    'order_id' => $this->order_id($order),
                    'transaction_id' => $transactionId,
                    'exception_class' => get_class($exception),
                    'message' => Logger::redact_untrusted_text($exception->getMessage()),
                ), 'error');
                throw $exception;
            }

            $attempt['sale_response'] = $response;

            $traceId = $this->extract_scalar($response, 'traceId');
            $this->log('PayArc sale accepted', array(
                'order_id' => $this->order_id($order),
                'transaction_id' => $transactionId,
                'trace_id_returned' => $traceId !== '',
                'trace_id_masked' => Settings::mask_identifier($traceId),
            ));
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

        if (!$this->is_in_flight_status($status)) {
            $attempt['continue_polling'] = false;

            return $attempt;
        }

        return PaymentLock::with_lock($this->order_id($order), self::RECONCILIATION_LOCK, function () use ($order): array {
            $attempt = PaymentAttempt::current($order);
            $status = isset($attempt['status']) ? (string) $attempt['status'] : 'created';
            $traceId = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';

            if ($traceId === '') {
                $attempt['status'] = 'pending_callback';
                $attempt['continue_polling'] = true;

                return $attempt;
            }

            if (!$this->is_in_flight_status($status)) {
                $attempt['continue_polling'] = false;

                return $attempt;
            }

            if ($this->is_poll_throttled($order)) {
                $attempt['continue_polling'] = true;

                return $attempt;
            }

            $this->store_last_poll_at($order, $this->now());

            try {
                $payload = $this->client->get_transaction($traceId);
            } catch (Throwable $exception) {
                // Verified live 2026-07-23: PayArc accepts a sale (200 + traceId)
                // but GET /v3/transactions/{traceId} returns TRANSACTION_NOT_FOUND
                // for several seconds until the transaction becomes visible, so
                // that window means "keep waiting", not "the payment failed".
                if ($exception instanceof PayArcRequestException && $exception->payarc_code() === 'TRANSACTION_NOT_FOUND') {
                    $this->log('PayArc transaction not visible yet; continuing to poll', array(
                        'order_id' => $this->order_id($order),
                        'trace_id_masked' => Settings::mask_identifier($traceId),
                    ));
                    $attempt['continue_polling'] = true;

                    return $attempt;
                }

                throw $exception;
            }

            return $this->reconcile($order, $payload, 'poll');
        });
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @return array<string, mixed>
     */
    public function cancel_order_payment($order): array
    {
        return PaymentLock::with_lock($this->order_id($order), 'terminal', function () use ($order): array {
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

            if (!$this->is_in_flight_status($status)) {
                $attempt['continue_polling'] = false;

                return $attempt;
            }

            $terminalId = isset($attempt['terminal_id']) && is_scalar($attempt['terminal_id']) && trim((string) $attempt['terminal_id']) !== ''
                ? trim((string) $attempt['terminal_id'])
                : $this->settings->default_terminal_id();
            $terminal = $this->terminal_service->validate_terminal($terminalId);

            $this->log('PayArc cancel requested', array(
                'order_id' => $this->order_id($order),
                'trace_id_masked' => Settings::mask_identifier($traceId),
            ));

            try {
                $this->client->cancel($traceId, $terminal, PayArcIds::idempotency_key());
            } catch (Throwable $exception) {
                $this->log('PayArc cancel request failed', array(
                    'order_id' => $this->order_id($order),
                    'trace_id_masked' => Settings::mask_identifier($traceId),
                    'exception_class' => get_class($exception),
                    'message' => Logger::redact_untrusted_text($exception->getMessage()),
                ), 'error');
                if ($this->is_already_processed_error($exception)) {
                    return PaymentLock::with_lock($this->order_id($order), self::RECONCILIATION_LOCK, function () use ($order, $traceId): array {
                        $payload = $this->client->get_transaction($traceId);

                        return $this->reconcile($order, $payload, 'cancel_lookup');
                    });
                }

                throw $exception;
            }

            $updated = PaymentAttempt::update_status($order, 'cancel_requested', array('continue_polling' => true));
            $updated['continue_polling'] = true;

            return $updated;
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


    private function is_in_flight_status(string $status): bool
    {
        return PaymentAttempt::is_non_final($status) || PaymentAttempt::normalize_status($status) === 'cancel_requested';
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

        $fallbackClass = '\\WCPOS\\WooCommercePOS\\PayArcTerminal\\PaymentReconciler';
        if ($reconciler === null && class_exists($fallbackClass)) {
            $reconciler = new $fallbackClass($this->settings);
        }

        if ($reconciler === null || !method_exists($reconciler, 'reconcile')) {
            throw new RuntimeException('PayArc payment reconciliation is required but no reconciler is configured.');
        }

        $result = $reconciler->reconcile($order, $payload, $source);

        if (!is_array($result)) {
            throw new RuntimeException('PayArc payment reconciler must return an array.');
        }

        if ($source === 'poll' && array_key_exists('continue_polling', $result) && $result['continue_polling'] === false) {
            $status = isset($result['status']) && is_scalar($result['status']) ? (string) $result['status'] : '';
            $traceId = $this->extract_scalar($payload, 'traceId');
            if ($traceId === '') {
                $attempt = PaymentAttempt::current($order);
                $traceId = isset($attempt['trace_id']) && is_scalar($attempt['trace_id']) ? trim((string) $attempt['trace_id']) : '';
            }

            $this->log('PayArc payment attempt resolved', array(
                'order_id' => $this->order_id($order),
                'status' => $status,
                'trace_id_masked' => Settings::mask_identifier($traceId),
            ));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context, string $level = 'info'): void
    {
        try {
            Logger::log($message, $context, null, $level);
        } catch (Throwable $exception) {
            // Diagnostic logging must not interrupt payment processing.
        }
    }

    private function is_already_processed_error(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $upperMessage = strtoupper($exception->getMessage());

        foreach (array('TRANSACTION_CANNOT_BE_CANCELLED', 'TRANSACTION_CANNOT_BE_CANCELED', 'CANNOT_BE_CANCELLED', 'CANNOT_BE_CANCELED', 'ALREADY_PROCESSED') as $code) {
            if (strpos($upperMessage, $code) !== false) {
                return true;
            }
        }

        return strpos($message, 'already processed') !== false
            || strpos($message, 'already been processed') !== false
            || strpos($message, 'transaction processed') !== false;
    }
}
