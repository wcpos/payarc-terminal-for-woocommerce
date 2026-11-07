<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Tests\Conformance;

/**
 * A scripted PayArc Connect V3 behind WordPress's HTTP layer (`pre_http_request`): sales idempotent on
 * X-Idempotency-Key, the transaction read, cancel, and terminal refunds, as PayArc's reference and the
 * plugin's live notes describe them. A sale the card has begun processing cannot be cancelled.
 */
final class Fake_PayArc_Transport
{
    public const HOST = 'testpayarcconnectapi.payarc.net';
    public const SECRET = 'secret-conformance';
    public const TERMINALS = array('1234567890', '0987654321');

    /** Every request as WordPress sent it, for debugging a transcript. */
    public $raw = array();
    /** The traceId of the latest sale created or replayed. */
    public $current;
    private $sales = array();
    private $by_key = array();
    private $states = array('created');
    private $scenario = 'create_ok';
    private $refund_state = 'APPROVED';
    private $cancel = 'accepted';
    private $lost = false;
    private $seq = 0;

    /**
     * Arm a scenario: the states successive adapter fetches observe, the state a refund's terminal command
     * ends in, and whether a cancel is accepted (200, the sale aborted) or refused (the card is processing).
     */
    public function script(string $scenario, array $states, string $refund_state = 'APPROVED', string $cancel = 'accepted'): void
    {
        $this->scenario = $scenario;
        $this->states = $states;
        $this->refund_state = $refund_state;
        $this->cancel = $cancel;
        $this->lost = false;
    }

    /** Called by the recording adapter on entry to fetch(): the sale moves to its next scripted state. */
    public function advance(string $trace_id): void
    {
        if (!isset($this->sales[$trace_id]) || $this->is_final($this->sales[$trace_id]) || $this->sales[$trace_id]['transType'] !== 'SALE') {
            return;
        }
        $entry = &$this->sales[$trace_id];
        $state = count($entry['states']) > 1 ? array_shift($entry['states']) : $entry['states'][0];
        $this->apply_state($entry, $state);
    }

    /** A provider-side outcome (what a callback reports), even over an ended sale. */
    public function observe(string $trace_id, string $state): void
    {
        $this->apply_state($this->sales[$trace_id], $state);
    }

    public function sale_for_key(string $key): ?string
    {
        return $this->by_key[$key] ?? null;
    }

    /**
     * The callback body PayArc posts: the transaction as the read returns it. PayArc echoes the sale's
     * metadata here too; the fixture leaves it out, so the suite proves the adapter resolves the row from
     * the authenticated read (and Pro's adoption record), never from the posted body.
     */
    public function callback_body(string $trace_id): string
    {
        $body = $this->view($trace_id);
        unset($body['metadata']);

        return wp_json_encode($body);
    }

    /**
     * @param mixed $pre
     * @param array<string, mixed> $args
     * @return mixed
     */
    public function handle($pre, array $args, string $url)
    {
        if (wp_parse_url($url, PHP_URL_HOST) !== self::HOST) {
            return $pre;
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $body = isset($args['body']) && $args['body'] !== '' ? json_decode((string) $args['body'], true) : array();
        $headers = array_change_key_case((array) ($args['headers'] ?? array()), CASE_LOWER);
        $this->raw[] = array('method' => $method, 'path' => $path, 'body' => $body);
        if (($headers['authorization'] ?? '') !== 'Bearer ' . self::SECRET) {
            return self::error(401, 'UNAUTHORIZED', 'Invalid credentials.');
        }
        $key = (string) ($headers['x-idempotency-key'] ?? '');
        if ($key === '') {
            return self::error(400, 'IDEMPOTENCY_KEY_REQUIRED', 'X-Idempotency-Key is required.'); // Verified live 2026-07-23.
        }

        if ($path === '/v3/transactions/sale' && $method === 'POST') {
            return $this->sale($body, $key);
        }
        if ($path === '/v3/transactions/refund' && $method === 'POST') {
            return $this->refund($body, $key);
        }
        if (preg_match('#^/v3/transactions/([^/]+)$#', $path, $m) && $method === 'GET') {
            return isset($this->sales[$m[1]]) ? self::ok($this->view($m[1])) : self::error(400, 'TRANSACTION_NOT_FOUND', 'Transaction not found.');
        }
        if (preg_match('#^/v3/transactions/([^/]+)/cancel$#', $path, $m) && $method === 'POST') {
            if (!isset($this->sales[$m[1]])) {
                return self::error(400, 'TRANSACTION_NOT_FOUND', 'Transaction not found.');
            }
            $entry = &$this->sales[$m[1]];
            if ($this->is_final($entry) || $entry['cancel'] === 'refused') {
                return self::error(400, 'TRANSACTION_CANNOT_BE_CANCELLED', 'Once the card has begun processing, the transaction can no longer be cancelled.');
            }
            $this->apply_state($entry, 'ABORTED');
            return self::ok(array('traceId' => $m[1], 'response' => array('status' => 'SUCCESS')));
        }
        throw new \LogicException('Unexpected PayArc request: ' . $method . ' ' . $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\WP_Error
     */
    private function sale(array $body, string $key)
    {
        if (isset($this->by_key[$key])) {
            // PayArc replays the original answer for a key it has seen with the same payload.
            $this->current = $this->by_key[$key];
            return self::ok(array('traceId' => $this->current, 'response' => array('status' => 'SUCCESS')));
        }
        if (!in_array((string) ($body['terminalId'] ?? ''), self::TERMINALS, true)) {
            return self::error(409, 'TERMINAL_OFFLINE', 'The terminal is offline.');
        }
        $trace = $this->trace_id();
        $this->sales[$trace] = array(
            'traceId' => $trace,
            'transType' => 'SALE',
            'transactionId' => (string) ($body['transactionId'] ?? ''),
            'states' => $this->states,
            'state' => 'created',
            'amount' => (int) ($body['amount']['total'] ?? 0),
            'currency' => (string) ($body['amount']['currency'] ?? 'USD'),
            'terminal' => (string) $body['terminalId'],
            'metadata' => (array) ($body['metadata'] ?? array()),
            'cancel' => $this->cancel,
            'approved' => null,
            'created' => time(),
        );
        $this->by_key[$key] = $trace;
        $this->current = $trace;
        if ($this->scenario === 'create_indeterminate' && !$this->lost) {
            $this->lost = true; // Accepted, on the terminal, and the answer never arrives.
            return new \WP_Error('http_request_failed', 'Response lost after the sale was accepted');
        }
        return self::ok(array('traceId' => $trace, 'response' => array('status' => 'SUCCESS')));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|\WP_Error
     */
    private function refund(array $body, string $key)
    {
        if (isset($this->by_key[$key])) {
            return self::ok(array('traceId' => $this->by_key[$key], 'response' => array('status' => 'SUCCESS')));
        }
        $original = (string) ($body['originalTransactionId'] ?? '');
        $sale = null;
        foreach ($this->sales as $entry) {
            if ($entry['transType'] === 'SALE' && $entry['transactionId'] === $original && $entry['state'] === 'APPROVED') {
                $sale = $entry;
            }
        }
        if ($sale === null) {
            return self::error(400, 'ORIGINAL_TRANSACTION_NOT_FOUND', 'No approved sale with that transaction id.');
        }
        if (!in_array((string) ($body['terminalId'] ?? ''), self::TERMINALS, true)) {
            return self::error(409, 'TERMINAL_OFFLINE', 'The terminal is offline.');
        }
        $trace = $this->trace_id();
        $this->sales[$trace] = array(
            'traceId' => $trace,
            'transType' => 'REFUND',
            'transactionId' => (string) ($body['transactionId'] ?? ''),
            'states' => array($this->refund_state),
            'state' => 'created',
            'amount' => (int) ($body['amount']['total'] ?? 0),
            'currency' => (string) ($body['amount']['currency'] ?? 'USD'),
            'terminal' => (string) $body['terminalId'],
            'metadata' => (array) ($body['metadata'] ?? array()),
            'cancel' => 'refused',
            'approved' => null,
            'created' => time(),
        );
        // The terminal answers at once in this fake; a real one takes seconds.
        $this->apply_state($this->sales[$trace], $this->refund_state);
        $this->by_key[$key] = $trace;
        return self::ok(array('traceId' => $trace, 'response' => array('status' => 'SUCCESS')));
    }

    private function trace_id(): string
    {
        return sprintf('%08x-%04x-4%03x-8%03x-%012x', ++$this->seq, $this->seq, $this->seq, $this->seq, $this->seq);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function is_final(array $entry): bool
    {
        return in_array($entry['state'], array('APPROVED', 'DECLINE', 'TIMEOUT', 'ABORTED', 'DUP TRANSACTION'), true);
    }

    /**
     * @param array<string, mixed> $entry
     * @param string $state created, processing, APPROVED, APPROVED:short, APPROVED:eur, DECLINE, TIMEOUT, ABORTED, DUP TRANSACTION.
     */
    private function apply_state(array &$entry, string $state): void
    {
        list($status, $detail) = array_pad(explode(':', $state, 2), 2, '');
        if (!in_array($status, array('created', 'processing', 'APPROVED', 'DECLINE', 'TIMEOUT', 'ABORTED', 'DUP TRANSACTION'), true)) {
            throw new \OutOfBoundsException('Unknown transaction state: ' . $state);
        }
        $entry['state'] = $status;
        if ($status === 'APPROVED') {
            $entry['approved'] = $detail === 'short' ? 100 : $entry['amount'];
            $entry['approved_currency'] = $detail === 'eur' ? 'EUR' : $entry['currency'];
            $entry['chargeId'] = 'ch_' . substr($entry['traceId'], 0, 8);
        }
    }

    /**
     * The transaction as PayArc returns it (the CallbackResponse shape).
     *
     * @return array<string, mixed>
     */
    private function view(string $trace_id): array
    {
        $entry = $this->sales[$trace_id];
        $approved = $entry['state'] === 'APPROVED';
        $view = array(
            'transactionId' => $entry['transactionId'],
            'transType' => $entry['transType'],
            'status' => $entry['state'],
            'chargeId' => $approved ? $entry['chargeId'] : null,
            'authCode' => $approved ? 'A1B2C3' : null,
            'amount' => array(
                'total' => $entry['amount'],
                'subtotal' => $entry['amount'],
                'approved' => $approved ? $entry['approved'] : 0,
                'currency' => $approved ? $entry['approved_currency'] : $entry['currency'],
                'tip' => null,
                'tax' => null,
            ),
            'card' => $approved ? array('brand' => 'VISA', 'entryMode' => 'CONTACTLESS', 'last4' => '1111') : null,
            'processor' => array('type' => 'TRADITIONAL', 'responseCode' => $approved ? '00' : ($entry['state'] === 'DECLINE' ? '05' : ''), 'responseText' => $entry['state'] === 'DECLINE' ? 'Do not honor' : ($approved ? 'Approved' : '')),
            'timestamp' => gmdate('c', $entry['created']),
            'traceId' => $trace_id,
            'metadata' => $entry['metadata'],
            'error' => null,
        );

        return $view;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function ok(array $body): array
    {
        return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => 200, 'message' => ''), 'cookies' => array());
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(int $status, string $code, string $message): array
    {
        return array('headers' => array(), 'body' => wp_json_encode(array('traceId' => null, 'response' => array('status' => 'FAILURE', 'error' => array('code' => $code, 'message' => $message, 'friendlyMessage' => $message)))), 'response' => array('code' => $status, 'message' => ''), 'cookies' => array());
    }
}
