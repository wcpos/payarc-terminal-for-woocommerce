<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

use RuntimeException;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;

class PaymentReconciler
{
    public const META_CHARGE_ID = '_patwc_charge_id';
    public const META_CARD_BRAND = '_patwc_card_brand';
    public const META_CARD_ENTRY_MODE = '_patwc_card_entry_mode';
    public const META_CARD_LAST4 = '_patwc_card_last4';
    public const META_PROCESSOR_RESPONSE_CODE = '_patwc_processor_response_code';
    public const META_PROCESSOR_RESPONSE_TEXT = '_patwc_processor_response_text';
    public const META_VERIFICATION_FAILURE = '_patwc_verification_failure';

    /** @var Settings */
    private $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
    }

    /**
     * @param object $order WooCommerce order-like object.
     * @param array<string, mixed> $payload Authoritative fetched PayArc transaction payload.
     * @return array<string, mixed>
     */
    public function reconcile($order, array $payload, string $source): array
    {
        $status = PaymentAttempt::normalize_status($this->payload_status($payload));
        $traceId = $this->extract_scalar($payload, 'traceId');
        $transactionId = $this->extract_scalar($payload, 'transactionId');
        $chargeId = $this->extract_scalar($payload, 'chargeId');
        $callbackKey = $this->callback_key($traceId, $transactionId, $status);

        if ($callbackKey !== '' && $this->callback_already_processed($order, $callbackKey)) {
            return array('status' => 'idempotent', 'continue_polling' => false, 'attempt' => PaymentAttempt::current($order));
        }

        $identity = $this->verify_identity($order, $payload, $traceId, $transactionId);
        if (!$identity['valid']) {
            $this->store_verification_failure($order, $identity['message']);
            $this->add_note($order, 'PayArc reconciliation conflict: ' . $identity['message']);
            $this->save($order);

            return array('status' => 'conflict', 'continue_polling' => false, 'message' => $identity['message']);
        }

        if ($this->is_paid($order) && $this->paid_order_conflicts($order, $traceId, $transactionId)) {
            return array(
                'status' => 'conflict',
                'continue_polling' => false,
                'message' => 'Order is already paid by a different PayArc transaction.',
            );
        }

        $fields = array_filter(array(
            'trace_id' => $traceId,
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
        ), static function ($value): bool {
            return is_scalar($value) && trim((string) $value) !== '';
        });

        $this->store_detail_meta($order, $payload, $chargeId);

        if ($status === 'success') {
            $amountVerification = $this->verify_success_amount($order, $payload);
            if (!$amountVerification['valid']) {
                PaymentAttempt::update_status($order, 'failure', $fields);
                $this->store_verification_failure($order, $amountVerification['message']);
                $this->add_note($order, 'PayArc reconciliation verification failed: ' . $amountVerification['message']);
                $this->save($order);

                return array('status' => 'verification_failed', 'continue_polling' => false, 'message' => $amountVerification['message']);
            }
        }

        $attempt = PaymentAttempt::update_status($order, $status, $fields);
        $isFinal = $status === 'success' || PaymentAttempt::is_final_unpaid($status);

        if ($isFinal) {
            $this->add_note($order, 'PayArc transaction reconciled with final status: ' . $status . '.');
        }

        if ($callbackKey !== '' && $isFinal) {
            $this->mark_callback_processed($order, $callbackKey);
        }

        if ($status === 'success' && $this->can_complete_from_source($source) && !$this->is_paid($order)) {
            $this->payment_complete($order, $chargeId !== '' ? $chargeId : ($traceId !== '' ? $traceId : $transactionId));
        }

        $this->save($order);

        return array('status' => $status, 'continue_polling' => !$isFinal, 'attempt' => $attempt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payload_status(array $payload): string
    {
        $status = $this->extract_scalar($payload, 'status');
        if ($status !== '') {
            return $status;
        }

        if (isset($payload['response']) && is_array($payload['response'])) {
            return $this->extract_scalar($payload['response'], 'status');
        }

        return 'created';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verify_identity($order, array $payload, string $traceId, string $transactionId): array
    {
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : array();
        $payloadOrderId = isset($metadata['order_id']) && is_scalar($metadata['order_id']) ? trim((string) $metadata['order_id']) : '';

        if ($payloadOrderId !== '') {
            $orderId = $this->order_id($order);
            if ($payloadOrderId === (string) $orderId) {
                return array('valid' => true, 'message' => '');
            }

            return array('valid' => false, 'message' => 'PayArc metadata order_id does not match this order.');
        }

        $current = PaymentAttempt::current($order);
        $currentTrace = isset($current['trace_id']) && is_scalar($current['trace_id']) ? trim((string) $current['trace_id']) : '';
        $currentTransaction = isset($current['transaction_id']) && is_scalar($current['transaction_id']) ? trim((string) $current['transaction_id']) : '';

        if ($currentTrace === '' && $currentTransaction === '') {
            return array('valid' => false, 'message' => 'PayArc payload has no metadata order_id and the order has no current PayArc identifiers.');
        }

        if ($currentTrace !== '' && $traceId !== '' && hash_equals($currentTrace, $traceId)) {
            return array('valid' => true, 'message' => '');
        }

        if ($currentTransaction !== '' && $transactionId !== '' && hash_equals($currentTransaction, $transactionId)) {
            return array('valid' => true, 'message' => '');
        }

        return array('valid' => false, 'message' => 'PayArc payload identifiers do not match the current order attempt.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verify_success_amount($order, array $payload): array
    {
        $payloadCurrency = $this->payload_currency($payload);
        $orderCurrency = strtoupper(trim($this->order_currency($order)));

        if ($payloadCurrency === '') {
            return array('valid' => false, 'message' => 'PayArc amount currency is missing.');
        }

        if ($payloadCurrency !== $orderCurrency) {
            return array('valid' => false, 'message' => 'PayArc amount currency mismatch.');
        }

        $payloadAmount = $this->payload_amount_minor_units($payload);
        if ($payloadAmount === null) {
            return array('valid' => false, 'message' => 'PayArc approved amount is missing.');
        }

        $expectedAmount = Money::to_minor_units($this->order_total($order), $orderCurrency);
        if ($payloadAmount !== $expectedAmount) {
            return array('valid' => false, 'message' => 'PayArc approved amount mismatch.');
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payload_currency(array $payload): string
    {
        if (isset($payload['amount']) && is_array($payload['amount']) && isset($payload['amount']['currency']) && is_scalar($payload['amount']['currency'])) {
            return strtoupper(trim((string) $payload['amount']['currency']));
        }

        return $this->extract_scalar_upper($payload, 'currency');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payload_amount_minor_units(array $payload): ?int
    {
        if (isset($payload['amount']) && is_array($payload['amount'])) {
            foreach (array('approved', 'total') as $key) {
                if (array_key_exists($key, $payload['amount']) && is_numeric($payload['amount'][$key])) {
                    return (int) $payload['amount'][$key];
                }
            }
        }

        if (isset($payload['amount']) && is_numeric($payload['amount'])) {
            return (int) $payload['amount'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store_detail_meta($order, array $payload, string $chargeId): void
    {
        $this->update_meta($order, self::META_CHARGE_ID, $chargeId);

        $card = isset($payload['card']) && is_array($payload['card']) ? $payload['card'] : array();
        $this->update_meta($order, self::META_CARD_BRAND, $this->first_scalar($card, array('brand', 'cardBrand', 'card_brand')));
        $this->update_meta($order, self::META_CARD_ENTRY_MODE, $this->first_scalar($card, array('entryMode', 'entry_mode', 'entry')));
        $this->update_meta($order, self::META_CARD_LAST4, $this->first_scalar($card, array('last4', 'lastFour', 'last_four')));

        $processor = isset($payload['processorResponse']) && is_array($payload['processorResponse']) ? $payload['processorResponse'] : array();
        if ($processor === array() && isset($payload['processor']) && is_array($payload['processor'])) {
            $processor = $payload['processor'];
        }
        if ($processor === array() && isset($payload['response']) && is_array($payload['response'])) {
            $processor = $payload['response'];
        }

        $this->update_meta($order, self::META_PROCESSOR_RESPONSE_CODE, $this->first_scalar($processor, array('code', 'responseCode', 'response_code')));
        $this->update_meta($order, self::META_PROCESSOR_RESPONSE_TEXT, $this->first_scalar($processor, array('text', 'message', 'responseText', 'response_text', 'friendlyMessage')));
    }

    private function callback_key(string $traceId, string $transactionId, string $status): string
    {
        $identifier = $traceId !== '' ? $traceId : $transactionId;

        return $identifier === '' ? '' : $identifier . '|' . $status;
    }

    private function callback_already_processed($order, string $callbackKey): bool
    {
        $processed = $this->get_meta($order, PaymentAttempt::META_PROCESSED_CALLBACKS);

        return is_array($processed) && in_array($callbackKey, $processed, true);
    }

    private function mark_callback_processed($order, string $callbackKey): void
    {
        $processed = $this->get_meta($order, PaymentAttempt::META_PROCESSED_CALLBACKS);
        if (!is_array($processed)) {
            $processed = array();
        }

        if (!in_array($callbackKey, $processed, true)) {
            $processed[] = $callbackKey;
            $this->update_meta($order, PaymentAttempt::META_PROCESSED_CALLBACKS, $processed);
        }
    }

    private function paid_order_conflicts($order, string $traceId, string $transactionId): bool
    {
        return $this->paid_transaction_id_conflicts($order, $transactionId)
            || !$this->matches_current_identifier($order, $traceId, $transactionId);
    }

    private function paid_transaction_id_conflicts($order, string $transactionId): bool
    {
        if ($transactionId === '') {
            return false;
        }

        $current = PaymentAttempt::current($order);
        $currentTransaction = isset($current['transaction_id']) && is_scalar($current['transaction_id']) ? trim((string) $current['transaction_id']) : '';

        return $currentTransaction !== '' && !hash_equals($currentTransaction, $transactionId);
    }

    private function matches_current_identifier($order, string $traceId, string $transactionId): bool
    {
        $current = PaymentAttempt::current($order);
        $currentTrace = isset($current['trace_id']) && is_scalar($current['trace_id']) ? trim((string) $current['trace_id']) : '';
        $currentTransaction = isset($current['transaction_id']) && is_scalar($current['transaction_id']) ? trim((string) $current['transaction_id']) : '';

        if ($currentTrace !== '' && $traceId !== '' && hash_equals($currentTrace, $traceId)) {
            return true;
        }

        if ($currentTransaction !== '' && $transactionId !== '' && hash_equals($currentTransaction, $transactionId)) {
            return true;
        }

        return false;
    }

    private function can_complete_from_source(string $source): bool
    {
        return in_array($source, array('webhook', 'poll', 'cancel_lookup'), true);
    }

    private function payment_complete($order, string $transactionId): void
    {
        if (is_object($order) && method_exists($order, 'payment_complete')) {
            $order->payment_complete($transactionId);
        }
    }

    private function add_note($order, string $note): void
    {
        if (is_object($order) && method_exists($order, 'add_order_note')) {
            $order->add_order_note($note);
        }
    }

    private function is_paid($order): bool
    {
        return is_object($order) && method_exists($order, 'is_paid') && $order->is_paid();
    }

    private function store_verification_failure($order, string $message): void
    {
        $this->update_meta($order, self::META_VERIFICATION_FAILURE, $message);
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
     * @param array<string, mixed> $payload
     */
    private function extract_scalar_upper(array $payload, string $key): string
    {
        return strtoupper($this->extract_scalar($payload, $key));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function first_scalar(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return trim((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @param object $order
     * @return mixed
     */
    private function get_meta($order, string $key)
    {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            return $order->get_meta($key, true);
        }

        return '';
    }

    /**
     * @param object $order
     * @param mixed $value
     */
    private function update_meta($order, string $key, $value): void
    {
        if (is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($key, $value);
        }
    }

    /**
     * @param object $order
     */
    private function save($order): void
    {
        if (is_object($order) && method_exists($order, 'save')) {
            $order->save();
        }
    }
}
