<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WCPOS\WooCommercePOS\PayArcTerminal\Logger;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcNotSentException;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\TerminalService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\PayArcIds;
use WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter;
use WCPOS\WooCommercePOSPro\Payments\Server\Money_Units;

/**
 * PayArc Connect V3 behind WCPOS Pro's flow-blind contract; Free owns the ledger.
 *
 * Facts the mapping rests on (PayArc Connect V3 reference; the plugin's own live notes of 2026-07-23):
 * - A sale is accepted at once (200 with PayArc's traceId) and ends asynchronously on the terminal; the
 *   outcome is posted to the sale's callbackURL and readable at GET /v3/transactions/{traceId}. The
 *   status is free text: APPROVED is money; DECLINE, DUP TRANSACTION and FAILURE are failures; TIMEOUT
 *   (no card presented) ends the transaction; CANCELLED/ABORTED follow a cancel; created, pending,
 *   sent and processing are live. For a few seconds after the sale the GET answers TRANSACTION_NOT_FOUND,
 *   which means "not visible yet", never "gone".
 * - Every call carries X-Idempotency-Key ("reuse the same key only when retrying the same payload"); the
 *   sale's key is the ledger row id, so Free's replay of an unanswered create hands back the same sale.
 * - A cancel is refused once the card has begun processing; a 200 is confirmed by reading the transaction.
 * - A refund is a terminal command linked by the sale's own 16-character transactionId, accepted with a
 *   traceId and decided on the terminal; 409 TERMINAL_OFFLINE sends nothing.
 * - The callback is authenticated by the plugin's own token in the callbackURL, or PayArc's bearer token.
 */
class PayArc_Server_Provider extends Abstract_Provider_Adapter
{
    public const PROVIDER = 'payarc';

    /** @var Settings */
    private $settings;
    /** @var PayArcClient */
    private $client;
    /** @var TerminalService */
    private $terminals;

    public function __construct(?Settings $settings = null, ?PayArcClient $client = null, ?TerminalService $terminals = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
        $this->client = $client === null ? new PayArcClient($this->settings) : $client;
        $this->terminals = $terminals === null ? new TerminalService($this->settings) : $terminals;
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function describe(\WC_Payment_Gateway $gateway): array
    {
        // No tip prompt is requested on the terminal, so the approved amount is the row's.
        return array(
            'capabilities' => array('tips' => 'none', 'refunds' => array('via' => 'provider', 'partial' => true), 'void' => true),
            'provider_data' => array('mode' => $this->settings->mode()),
        );
    }

    public function list_readers()
    {
        // PayArc Connect names terminals by serial; the registry is what Connect PayArc fetched, plus the
        // serial the merchant typed. Neither reports connectivity: a sale to an offline terminal is refused
        // at once (409 TERMINAL_OFFLINE) and nothing is sent.
        $readers = array();
        $known = array();
        foreach ($this->settings->terminal_registry() as $terminal) {
            $known[(string) $terminal['terminal_id']] = true;
            if (empty($terminal['enabled'])) {
                continue; // Disabled in the registry: not offered, even as the typed default.
            }
            $readers[(string) $terminal['terminal_id']] = array('id' => (string) $terminal['terminal_id'], 'label' => (string) $terminal['label'], 'status' => 'online');
        }
        $default = $this->settings->default_terminal_id();
        if ($default !== '' && !isset($known[$default])) {
            $readers[$default] = array('id' => $default, 'label' => Settings::terminal_label('PayArc terminal', '', $default), 'status' => 'online');
        }

        return array_values($readers);
    }

    public function create_reader_action(array $row, string $reader_id)
    {
        $order = wc_get_order((int) $row['order_id']);
        if (!$order) {
            return new \WP_Error('wcpos_order_not_found', __('Order not found.', 'payarc-terminal-for-woocommerce'), array('status' => 404));
        }
        $held = Sale_Guard::held((string) $row['id']);
        try {
            // Free's replay of a lost answer must send the byte-identical command under the same key:
            // the sale as first sent, whatever the settings say now, and built from nothing current.
            $payload = $held !== null && isset($held['payload']) && is_array($held['payload']) ? $held['payload'] : $this->sale_payload($row, $reader_id, $order);
            // The row id is the idempotency key: a replay after a lost answer is the same command.
            try {
                $response = $this->client->sale($payload, (string) $row['id']);
            } catch (PayArcNotSentException $e) {
                if ($held !== null) {
                    // A replay the client could not send: the first command may be on the terminal.
                    return $this->indeterminate('payarc_unanswered', $e->getMessage());
                }

                return self::provider_error($e, 'payarc_configuration', 400); // Nothing left the server.
            } catch (PayArcRequestException $e) {
                // A refused replay whose code speaks of the key or a duplicate means the first command exists.
                if (self::unanswered($e) || self::idempotency_conflict($e)) {
                    Sale_Guard::hold((string) $row['id'], (int) $order->get_id(), $payload);
                    return $this->indeterminate('payarc_unanswered', $e->getMessage());
                }

                return self::provider_error($e);
            } catch (Throwable $e) {
                // Transport loss, or an answer that could not be read: the sale may be on the terminal.
                Sale_Guard::hold((string) $row['id'], (int) $order->get_id(), $payload);
                return $this->indeterminate('payarc_unanswered', $e->getMessage());
            }
            $trace_id = self::scalar($response, 'traceId');
            // A sale may be on the terminal from here on: the settings guard (mode, credentials, Connect)
            // holds until it ends, and the command is kept for a byte-identical replay.
            Sale_Guard::hold((string) $row['id'], (int) $order->get_id(), $payload, $trace_id);
            if ($trace_id === '') {
                // Accepted without a handle: nothing to poll, and the terminal may have the sale.
                return $this->indeterminate('payarc_unanswered', __('PayArc accepted the sale without a trace id.', 'payarc-terminal-for-woocommerce'));
            }

            return array('ref' => $trace_id, 'expires_at' => null);
        } catch (InvalidArgumentException $e) {
            return self::provider_error($e, 'payarc_configuration', 400);
        } catch (Throwable $e) {
            return self::provider_error($e, 'payarc_configuration', 400); // Before the sale was built: nothing sent.
        }
    }

    /**
     * The sale command for a row, as first built: terminal, money, the store's own ids and the callback.
     *
     * @param array<string, mixed> $row
     * @param object $order
     * @return array<string, mixed>
     */
    private function sale_payload(array $row, string $reader_id, $order): array
    {
        $terminal = $this->terminals->validate_terminal($reader_id);
        $amount = Money::to_payarc_amount_object((string) $row['amount'], (string) $row['currency']);

        return array(
            'tenantId' => $terminal['tenantId'],
            'terminalId' => $terminal['terminalId'],
            // The store's own id for the sale, which a linked refund names later; 16 characters.
            'transactionId' => PayArcIds::transaction_id((int) $order->get_id(), (string) $row['id']),
            'tenderType' => $this->settings->tender_type(),
            'amount' => $amount,
            'printReceipt' => $this->settings->print_receipt(),
            'callbackURL' => self::webhook_url($this->settings),
            'metadata' => array(
                'wcpos_payment_id' => (string) $row['id'],
                'order_id' => (string) $order->get_id(),
                'terminal_id' => $terminal['terminalId'],
                'tender_type' => $this->settings->tender_type(),
                'mode' => $this->settings->mode(),
            ),
        );
    }

    public function fetch(string $ref)
    {
        try {
            $transaction = $this->client->get_transaction($ref);
            $observation = self::normalize($transaction);
            $this->release_guard_if_final($transaction, $observation);

            return $observation;
        } catch (PayArcRequestException $e) {
            if ($e->payarc_code() === 'TRANSACTION_NOT_FOUND') {
                // Not visible yet (a few seconds after the sale): live, not gone.
                return array('status' => 'pending', 'amount' => null, 'currency' => null, 'provider_refs' => array('payarc_trace_id' => $ref));
            }

            return self::unanswered($e) ? $this->indeterminate('payarc_unanswered', $e->getMessage()) : self::provider_error($e);
        } catch (Throwable $e) {
            return $this->indeterminate('payarc_unanswered', $e->getMessage()); // Nothing observed; the next poll asks again.
        }
    }

    /**
     * Pure projection of a PayArc transaction read, not a ledger transition.
     *
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    public static function normalize(array $transaction): array
    {
        $status = PaymentAttempt::normalize_status(self::scalar($transaction, 'status'));
        $amount = isset($transaction['amount']) && is_array($transaction['amount']) ? $transaction['amount'] : array();
        $currency = strtoupper(self::scalar($amount, 'currency'));
        $refs = array(
            'payarc_trace_id' => self::scalar($transaction, 'traceId'),
            // The store's own sale id, which a linked refund names.
            'payarc_transaction_id' => self::scalar($transaction, 'transactionId'),
            'reader' => isset($transaction['metadata']['terminal_id']) ? (string) $transaction['metadata']['terminal_id'] : '',
            // The tender the sale was taken with; a linked refund must name the same one.
            'payarc_tender_type' => isset($transaction['metadata']['tender_type']) ? (string) $transaction['metadata']['tender_type'] : '',
        );
        if ($status === 'success') {
            $charge_id = self::scalar($transaction, 'chargeId');
            // Only the approved amount is money PayArc confirms; without it the amount is unknown, not the total.
            $approved = isset($amount['approved']) && is_numeric($amount['approved']) ? (int) $amount['approved'] : null;
            // `transaction_id` is what Free copies into the order's transaction id on capture: the traceId,
            // by which a refund can read the sale back (its own transactionId and terminal) with nothing but
            // Free's reference. The old panel copied the processor's charge id instead; a refund of one of
            // its sales finds the sale through the attempt the old panel kept on the order.
            $refs['transaction_id'] = $refs['payarc_trace_id'];
            $refs['charge_id'] = $charge_id;

            return array(
                'status' => 'completed',
                'amount' => $approved === null || $currency === '' ? null : Money_Units::major($approved, $currency),
                'currency' => $currency !== '' ? $currency : null,
                'provider_refs' => array_filter($refs, 'strlen'),
                'receipt' => self::receipt($transaction),
            );
        }
        if (PaymentAttempt::is_final_unpaid($status)) {
            if ($status === 'timeout') {
                return array('status' => 'expired', 'amount' => null, 'currency' => null, 'provider_refs' => array_filter($refs, 'strlen'));
            }
            if ($status === 'cancelled' || $status === 'aborted') {
                return array('status' => 'cancelled', 'amount' => null, 'currency' => null, 'provider_refs' => array_filter($refs, 'strlen'));
            }
            $processor = isset($transaction['processor']) && is_array($transaction['processor']) ? $transaction['processor'] : array();
            $reason = self::scalar($processor, 'responseText');

            return array('status' => 'failed', 'amount' => null, 'currency' => null, 'provider_refs' => array_filter($refs, 'strlen'), 'failure_reason' => $reason !== '' ? $reason : $status);
        }

        // created, pending, sent, processing, or anything PayArc adds: still on the terminal.
        return array('status' => $status === 'created' ? 'pending' : 'in_progress', 'amount' => null, 'currency' => null, 'provider_refs' => array_filter($refs, 'strlen'));
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<string, string>
     */
    private static function receipt(array $transaction): array
    {
        $card = isset($transaction['card']) && is_array($transaction['card']) ? $transaction['card'] : array();

        return array_filter(array(
            'card_label' => self::scalar($card, 'brand'),
            'card_last4' => self::scalar($card, 'last4'),
            'read_method' => self::scalar($card, 'entryMode'),
            'auth_code' => self::scalar($transaction, 'authCode'),
            'charge_id' => self::scalar($transaction, 'chargeId'),
            'payarc_trace_id' => self::scalar($transaction, 'traceId'),
        ), 'strlen');
    }

    public function cancel(string $ref)
    {
        try {
            $terminal = $this->terminal_for($ref);
            try {
                // A fresh key each time: a cancel is its own command, never a replay of the sale.
                $this->client->cancel($ref, array('tenantId' => $terminal['tenantId'], 'terminalId' => $terminal['terminalId']), PayArcIds::idempotency_key());
            } catch (PayArcRequestException $e) {
                if (self::unanswered($e)) {
                    throw $e;
                }
                // Refused: the card has begun processing, or the sale already ended. Only the read says which.
            }
            $transaction = $this->client->get_transaction($ref);
            $observation = self::normalize($transaction);
            $this->release_guard_if_final($transaction, $observation);

            return $observation['status'] === 'cancelled' || $observation['status'] === 'expired' || $observation['status'] === 'failed' ? 'final' : 'requested';
        } catch (PayArcRequestException $e) {
            if ($e->payarc_code() === 'TRANSACTION_NOT_FOUND') {
                return 'requested'; // Not visible yet: the next poll decides.
            }

            return self::provider_error($e);
        } catch (Throwable $e) {
            return self::provider_error($e);
        }
    }

    public function refund(array $row, int $refund_id, string $amount)
    {
        $refund = wc_get_order($refund_id);
        // A historical webview row names no order; the refund record does.
        $order = wc_get_order((int) ($row['order_id'] ?? ($refund instanceof \WC_Order_Refund ? $refund->get_parent_id() : 0)));
        if (!$order || !$refund instanceof \WC_Order_Refund) {
            return new \WP_Error('wcpos_refund_not_found', __('Order or refund not found.', 'payarc-terminal-for-woocommerce'), array('status' => 404));
        }
        $key = null;
        $row_id = (string) ($row['id'] ?? '');
        try {
            $sale = $this->sale_for_refund($row, $order);
            if ($sale === null) {
                return new \WP_Error('wcpos_provider_error', __('No PayArc sale found for refund.', 'payarc-terminal-for-woocommerce'), array('status' => 400));
            }
            $request = array(
                'tenantId' => $sale['tenantId'],
                'terminalId' => $sale['terminalId'],
                // The refund's own 16-character id, from the refund record AND the payment row (a refund split
                // across two payments is two commands), so a replay is the same command.
                'transactionId' => PayArcIds::transaction_id((int) $refund->get_id(), 'refund:' . $row_id),
                'originalTransactionId' => $sale['transactionId'],
                'tenderType' => $sale['tenderType'],
                'amount' => array('total' => Money_Units::minor($amount, (string) $row['currency']), 'currency' => strtoupper((string) $row['currency'])),
                'reason' => (string) $refund->get_reason(),
                'printReceipt' => $this->settings->print_receipt(),
                'callbackURL' => self::webhook_url($this->settings),
                // Never `wcpos_payment_id`: a refund's read must not be mistaken for the sale's.
                'metadata' => array('wcpos_refund_id' => (string) $refund->get_id(), 'wcpos_refund_row' => $row_id, 'order_id' => (string) $order->get_id()),
            );
            // Saved before the POST: a replay of the record, for this row, asks under the same key.
            $key = Refund_Reask::attempt_key($refund, $row_id, $request);
            $trace_id = $this->refund_once($request, $key);
            $refund->update_meta_data(Refund_Reask::key(Refund_Reask::META_TRACE, $row_id), $trace_id);
            $refund->save();
            // The terminal decides; a quick read may already know.
            try {
                $observation = self::normalize($this->client->get_transaction($trace_id));
            } catch (Throwable $e) {
                $observation = array('status' => 'pending');
            }
            if ($observation['status'] === 'completed') {
                return array('status' => 'succeeded', 'provider_ref' => $trace_id);
            }
            if (in_array($observation['status'], array('failed', 'cancelled', 'expired'), true)) {
                return array('status' => 'failed', 'provider_ref' => $trace_id);
            }

            return array('status' => 'pending', 'provider_ref' => $trace_id);
        } catch (PayArcNotSentException $e) {
            return self::provider_error($e, 'payarc_configuration', 400); // Nothing left the server, key or no key.
        } catch (PayArcRequestException $e) {
            if ($key !== null && (self::unanswered($e) || self::idempotency_conflict($e))) {
                // Unanswered, or a command under this key exists already: its traceId is asked for.
                return $this->refund_unanswered($order, $refund_id, $row_id, $e);
            }

            return self::unanswered($e) ? $this->indeterminate('payarc_unanswered', $e->getMessage()) : self::provider_error($e);
        } catch (Throwable $e) {
            // A read before the POST that went unanswered made nothing; an unanswered POST may have.
            return $key !== null ? $this->refund_unanswered($order, $refund_id, $row_id, $e) : $this->indeterminate('payarc_unanswered', $e->getMessage());
        }
    }

    /**
     * The POST itself went unanswered: the refund command may have reached the terminal. An error would make
     * WooCommerce delete the record, and a later refund would be a new command under a new key. The record
     * stands as pending, the order says so, and the re-ask replays the identical request under the saved key.
     *
     * @param object $order
     * @return array<string, mixed>
     */
    private function refund_unanswered($order, int $refund_id, string $row_id, Throwable $e): array
    {
        Logger::log('PayArc did not answer a refund POST; the refund record stays pending and is asked about again', array('refund_id' => $refund_id, 'message' => Logger::redact_untrusted_text($e->getMessage())), null, 'warning');
        Refund_Reask::unanswered($order, $refund_id, $row_id);

        return array('status' => 'pending', 'provider_ref' => null);
    }

    /**
     * One refund POST under a given key; PayArc dedupes a replay of the same payload.
     *
     * @param array<string, mixed> $request
     * @return string The refund's traceId.
     * @throws PayArcRequestException|RuntimeException
     */
    public function refund_once(array $request, string $key): string
    {
        $response = $this->client->refund($request, $key);
        $trace_id = self::scalar($response, 'traceId');
        if ($trace_id === '') {
            throw new RuntimeException('PayArc accepted the refund without a trace id.');
        }

        return $trace_id;
    }

    /**
     * The sale a refund is linked to: the row's own refs, else (a historical webview row, which carries
     * only Free's transaction id) the old panel's attempt on the order whose charge or trace id matches,
     * else a read of the transaction when the id is PayArc's traceId.
     *
     * @param array<string, mixed> $row
     * @param object $order
     * @return array{tenantId: string, terminalId: string, transactionId: string, tenderType: string}|null
     */
    private function sale_for_refund(array $row, $order): ?array
    {
        $refs = isset($row['provider_refs']) && is_array($row['provider_refs']) ? $row['provider_refs'] : array();
        $transaction_id = (string) ($refs['payarc_transaction_id'] ?? '');
        $terminal_id = (string) ($refs['reader'] ?? '');
        $tender_type = (string) ($refs['payarc_tender_type'] ?? '');
        $reference = (string) ($refs['transaction_id'] ?? '');
        if ($transaction_id === '' && $reference !== '') {
            foreach (self::old_attempts($order) as $attempt) {
                if (in_array($reference, array((string) ($attempt['charge_id'] ?? ''), (string) ($attempt['trace_id'] ?? '')), true) && (string) ($attempt['transaction_id'] ?? '') !== '') {
                    $transaction_id = (string) $attempt['transaction_id'];
                    $terminal_id = $terminal_id !== '' ? $terminal_id : (string) ($attempt['terminal_id'] ?? '');
                    break;
                }
            }
        }
        if ($transaction_id === '' && preg_match('/^[0-9a-f-]{36}$/i', $reference) === 1) {
            $transaction = $this->client->get_transaction($reference);
            $transaction_id = self::scalar($transaction, 'transactionId');
            $terminal_id = $terminal_id !== '' ? $terminal_id : (isset($transaction['metadata']['terminal_id']) ? (string) $transaction['metadata']['terminal_id'] : '');
            $tender_type = $tender_type !== '' ? $tender_type : (isset($transaction['metadata']['tender_type']) ? (string) $transaction['metadata']['tender_type'] : '');
        }
        if ($transaction_id === '') {
            return null;
        }
        $terminal = $this->terminals->validate_terminal($terminal_id);

        return array('tenantId' => $terminal['tenantId'], 'terminalId' => $terminal['terminalId'], 'transactionId' => $transaction_id, 'tenderType' => $tender_type !== '' ? $tender_type : $this->settings->tender_type());
    }

    /**
     * The old panel's attempts on an order, current first: the fields a linked refund needs.
     *
     * @param object $order
     * @return array<int, array<string, mixed>>
     */
    private static function old_attempts($order): array
    {
        $attempts = array();
        $current = PaymentAttempt::current($order);
        if (!empty($current)) {
            $attempts[] = $current;
        }
        $history = $order->get_meta(PaymentAttempt::META_ATTEMPT_HISTORY, true);
        foreach (is_array($history) ? array_reverse($history) : array() as $attempt) {
            if (is_array($attempt)) {
                $attempts[] = $attempt;
            }
        }

        return $attempts;
    }

    public function verify_webhook(\WP_REST_Request $request)
    {
        if (!self::authenticated($request, $this->settings)) {
            return new \WP_Error('payarc_webhook_unauthorized', __('PayArc callback not authenticated.', 'payarc-terminal-for-woocommerce'), array('status' => 401));
        }
        $body = json_decode((string) $request->get_body(), true);
        $body = is_array($body) ? $body : array();
        $trace_id = self::scalar($body, 'traceId');
        if (preg_match('/^[0-9a-f-]{36}$/i', $trace_id) !== 1) {
            return new \WP_Error('payarc_webhook_invalid', __('PayArc callback names no transaction.', 'payarc-terminal-for-woocommerce'), array('status' => 400));
        }
        // An attempt Pro adopted from the old panel carries no ledger id in its metadata; Pro's adoption
        // record names its row. A local read, before any call to PayArc.
        $adopted = function_exists('wcpos_pro_payment_id_for_action') ? wcpos_pro_payment_id_for_action($this->provider(), $trace_id) : null;
        try {
            // The authenticated read, not the posted body, is the evidence, its transaction type included.
            $transaction = $this->client->get_transaction($trace_id);
        } catch (PayArcRequestException $e) {
            if ($e->payarc_code() === 'TRANSACTION_NOT_FOUND') {
                return new \WP_Error('payarc_webhook_unknown', __('Unknown PayArc transaction.', 'payarc-terminal-for-woocommerce'), array('status' => 404));
            }

            return self::provider_error($e);
        } catch (Throwable $e) {
            return self::provider_error($e);
        }
        if (strtoupper(self::scalar($transaction, 'transType')) === 'REFUND' || isset($transaction['metadata']['wcpos_refund_id'])) {
            // A refund, by its type or by the refund it names: never settled against a sale row.
            return $this->refund_callback($transaction, $trace_id);
        }
        $payment_id = $adopted !== null ? $adopted : (isset($transaction['metadata']['wcpos_payment_id']) ? (string) $transaction['metadata']['wcpos_payment_id'] : '');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payment_id) !== 1) {
            return new \WP_Error('payarc_webhook_ignored', __('Not a WCPOS payment.', 'payarc-terminal-for-woocommerce'), array('status' => 200));
        }
        $this->release_guard_if_final($transaction, self::normalize($transaction));

        return array('payment_id' => strtolower($payment_id), 'patch' => self::webhook_patch($transaction));
    }

    /**
     * The ledger patch a verified callback carries; ledger vocabulary, not an observation.
     *
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    public static function webhook_patch(array $transaction): array
    {
        $observation = self::normalize($transaction);
        $patch = array('event_id' => self::scalar($transaction, 'traceId') . ':' . $observation['status'] . ':' . (string) ($observation['provider_refs']['charge_id'] ?? ''));
        if ($observation['status'] === 'completed') {
            // Settlement replaces the row's refs with the patch's, so a capture carries the complete set.
            return $patch + array(
                'status' => 'captured',
                'amount' => $observation['amount'],
                'currency' => $observation['currency'],
                'provider_refs' => array('action' => self::scalar($transaction, 'traceId')) + $observation['provider_refs'],
                'receipt' => $observation['receipt'],
            );
        }
        if ($observation['status'] === 'cancelled') {
            $patch['status'] = 'voided';
        } elseif ($observation['status'] === 'failed' || $observation['status'] === 'expired') {
            $patch['status'] = 'failed';
        } else {
            $patch['status'] = 'pending';
        }

        return $patch;
    }

    /**
     * A refund's outcome, from the terminal: noted on the order and the refund record. Pro's ledger holds
     * the refund as pending (nothing in Pro re-reads a pending refund); the note is what staff see.
     *
     * @param array<string, mixed> $body
     */
    private function refund_callback(array $transaction, string $trace_id): \WP_Error
    {
        $refund_id = isset($transaction['metadata']['wcpos_refund_id']) ? (int) $transaction['metadata']['wcpos_refund_id'] : 0;
        $row_id = isset($transaction['metadata']['wcpos_refund_row']) ? (string) $transaction['metadata']['wcpos_refund_row'] : '';
        $refund = $refund_id ? wc_get_order($refund_id) : null;
        $order = $refund instanceof \WC_Order_Refund ? wc_get_order((int) $refund->get_parent_id()) : null;
        if (!$order) {
            return new \WP_Error('payarc_webhook_ignored', __('Not a WCPOS refund.', 'payarc-terminal-for-woocommerce'), array('status' => 200));
        }
        // PayArc answered for this command: a pending re-ask of it has nothing to ask.
        if ((string) $refund->get_meta(Refund_Reask::key(Refund_Reask::META_TRACE, $row_id), true) === '') {
            $refund->update_meta_data(Refund_Reask::key(Refund_Reask::META_TRACE, $row_id), $trace_id);
            $refund->save();
        }
        $status = PaymentAttempt::normalize_status(self::scalar($transaction, 'status'));
        if (!($status === 'success' || PaymentAttempt::is_final_unpaid($status))) {
            return new \WP_Error('payarc_webhook_ignored', __('Refund still on the terminal.', 'payarc-terminal-for-woocommerce'), array('status' => 200)); // Not decided yet: nothing to tell staff.
        }
        $outcome_key = Refund_Reask::key('_patwc_refund_outcome', $row_id);
        if ((string) $refund->get_meta($outcome_key, true) === $status) {
            return new \WP_Error('payarc_webhook_ignored', __('Refund outcome already recorded.', 'payarc-terminal-for-woocommerce'), array('status' => 200));
        }
        $refund->update_meta_data($outcome_key, $status);
        $refund->save();
        $order->add_order_note($status === 'success'
            /* translators: 1: refund id, 2: PayArc traceId. */
            ? sprintf(__('PayArc confirmed refund #%1$d on the terminal (trace %2$s).', 'payarc-terminal-for-woocommerce'), $refund_id, $trace_id)
            /* translators: 1: refund id, 2: PayArc status. */
            : sprintf(__('PayArc reports refund #%1$d as %2$s: no money was returned. The record still counts as refunded here: delete it, then refund from the PayArc dashboard or the terminal if the money is owed.', 'payarc-terminal-for-woocommerce'), $refund_id, strtoupper($status)));
        $order->save();

        return new \WP_Error('payarc_webhook_refund', __('Refund outcome recorded.', 'payarc-terminal-for-woocommerce'), array('status' => 200));
    }

    /**
     * The callback is the plugin's own: its URL carries a secret the plugin minted (hash_equals), or PayArc's
     * bearer token for the merchant, when one was issued. Checked before any lookup or call.
     */
    public static function authenticated(\WP_REST_Request $request, Settings $settings): bool
    {
        $url_token = $settings->callback_url_token();
        $provided = (string) $request->get_param('patwc_cb');
        if ($url_token !== '' && $provided !== '' && hash_equals($url_token, $provided)) {
            return true;
        }
        $bearer = $settings->callback_bearer_token();
        $header = (string) $request->get_header('authorization');
        if ($bearer !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1 && hash_equals($bearer, trim($m[1]))) {
            return true;
        }

        return false;
    }

    /** Pro's shared route, with the provider family and the plugin's callback secret. */
    public static function webhook_url(Settings $settings): string
    {
        $args = array('provider' => self::PROVIDER);
        if ($settings->callback_url_token() !== '') {
            $args['patwc_cb'] = $settings->callback_url_token();
        }

        return add_query_arg($args, rest_url('wcpos/v2/payments/webhook'));
    }

    /**
     * The terminal a transaction is on, from its metadata, else the configured default.
     *
     * @return array<string, string>
     */
    private function terminal_for(string $trace_id): array
    {
        try {
            $transaction = $this->client->get_transaction($trace_id);
            $terminal_id = isset($transaction['metadata']['terminal_id']) ? (string) $transaction['metadata']['terminal_id'] : '';
        } catch (Throwable $e) {
            $terminal_id = '';
        }

        return $this->terminals->validate_terminal($terminal_id);
    }

    /**
     * A sale that ended releases the guard its create took: this sale's, by the row id its metadata carries.
     *
     * @param array<string, mixed> $transaction The read.
     * @param array<string, mixed>|\WP_Error $observation Its projection.
     */
    private function release_guard_if_final(array $transaction, $observation): void
    {
        if (!is_array($observation) || !in_array($observation['status'] ?? '', array('completed', 'failed', 'expired', 'cancelled'), true)) {
            return;
        }
        $row_id = isset($transaction['metadata']['wcpos_payment_id']) ? (string) $transaction['metadata']['wcpos_payment_id'] : '';
        if ($row_id !== '') {
            Sale_Guard::release($row_id);
        }
    }

    /** Whether PayArc's answer leaves the command's outcome unknown. */
    public static function unanswered(PayArcRequestException $e): bool
    {
        $status = $e->http_status();

        return $status === 0 || $status >= 500 || $status === 429;
    }

    /** A refused command whose error speaks of the idempotency key or a duplicate: the first command exists. */
    public static function idempotency_conflict(PayArcRequestException $e): bool
    {
        $haystack = strtoupper($e->payarc_code() . ' ' . $e->getMessage());

        return strpos($haystack, 'IDEMPOTEN') !== false || strpos($haystack, 'DUPLICATE') !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function scalar(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
    }

    private static function provider_error(Throwable $e, string $code = 'payarc_api_error', int $status = 502): \WP_Error
    {
        $detail = array('code' => $e instanceof PayArcRequestException && $e->payarc_code() !== '' ? $e->payarc_code() : $code, 'message' => Logger::redact_untrusted_text($e->getMessage()));
        if ($e instanceof PayArcRequestException) {
            $detail['http_status'] = $e->http_status();
        }

        return new \WP_Error('wcpos_provider_error', Logger::redact_untrusted_text($e->getMessage()), array('status' => $status, 'detail' => $detail));
    }
}
