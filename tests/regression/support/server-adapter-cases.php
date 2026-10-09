<?php

declare(strict_types=1);

namespace WCPOS\WooCommercePOSPro\Payments\Server {
    abstract class Abstract_Provider_Adapter
    {
        public function diagnostics(\WC_Payment_Gateway $gateway): array { return array(); }
        public function describe(\WC_Payment_Gateway $gateway): array { return array(); }
        public function answer(string $ref, string $prompt_id, string $button_id) { return null; }
        public function capture(string $ref) { return null; }
        public function refund(array $row, int $refund_id, string $amount) { return null; }
        public function verify_webhook(\WP_REST_Request $request) { return null; }
        protected function indeterminate(string $code, string $message): \WP_Error
        {
            return new \WP_Error($code, $message, array('indeterminate' => true, 'status' => 502));
        }
    }
    final class Money_Units
    {
        public static function minor(string $amount, string $currency): int { return (int) round(((float) $amount) * 100); }
        public static function major(int $minor, string $currency): string { return number_format($minor / 100, 2, '.', ''); }
    }
}

namespace {
    class WC_Payment_Gateway {}
    class WP_Error
    {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
    class WP_REST_Request
    {
        private $body = ''; private $headers = array(); private $params = array();
        public function set_body($b) { $this->body = (string) $b; }
        public function get_body() { return $this->body; }
        public function set_header($k, $v) { $this->headers[strtolower($k)] = $v; }
        public function get_header($k) { return $this->headers[strtolower($k)] ?? null; }
        public function set_query_params(array $p) { $this->params = $p; }
        public function get_param($k) { return $this->params[$k] ?? null; }
    }
    class PatwcOrder
    {
        public $id; public $meta = array(); public $notes = array(); public $paid = false; public $saves = 0;
        public function __construct(int $id) { $this->id = $id; }
        public function get_id() { return $this->id; }
        public function is_paid() { return $this->paid; }
        public function get_meta($k, $single = true) { return $this->meta[$k] ?? ($single ? '' : array()); }
        public function get_meta_data() { $out = array(); foreach ($this->meta as $k => $v) { $out[] = (object) array('key' => $k, 'value' => $v); } return $out; }
        public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
        public function add_order_note($n) { $this->notes[] = $n; }
        public function save() { $this->saves++; }
    }
    class WC_Order_Refund extends PatwcOrder
    {
        public $parent = 0; public $reason = 'why';
        public function get_parent_id() { return $this->parent; }
        public $amount = '92.95';
        public function get_amount() { return $this->amount; }
        public function get_reason() { return $this->reason; }
    }
    function is_wp_error($t) { return $t instanceof WP_Error; }
    function __($t, $d = '') { return $t; }
    function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
    function add_action($h, $c, $p = 10, $a = 1) { $GLOBALS['actions'][$h][] = $c; }
    function wp_schedule_single_event($t, $h, $args = array()) { $GLOBALS['events'][] = array($h, $args); return true; }
    function wp_generate_uuid4() { return sprintf('%08x-0000-4000-8000-%012x', ++$GLOBALS['uuid'], $GLOBALS['uuid']); }
    function rest_url($p = '') { return 'https://store.test/wp-json/' . $p; }
    function add_query_arg($args, $url = '') { return $url . '?' . http_build_query($args); }
    function wcpos_pro_payment_id_for_action($provider, $ref) { $GLOBALS['lookups'][] = $ref; return $GLOBALS['adopted'][$ref] ?? null; }
    function wp_json_encode($d) { return json_encode($d); }
    function apply_filters($h, $v) { return $v; }
    function get_option($k, $d = false) { if (!empty($GLOBALS['notoptions'][$k])) { return $d; } return $GLOBALS['options'][$k] ?? $d; }
    function is_serialized($d) { return is_string($d) && (strpos($d, 'a:') === 0 || strpos($d, 's:') === 0 || strpos($d, 'i:') === 0 || $d === 'b:0;' || strpos($d, 'b:') === 0 || strpos($d, 'd:') === 0 || $d === 'N;'); }
    function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
    function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
    /**
     * The options table as Sale_Guard reads it: the rows under its prefix, and a compare-and-delete.
     * $GLOBALS['options'] is the table; $GLOBALS['notoptions'] names rows the object cache wrongly
     * lists as missing, which get_option() honours and the table does not.
     */
    class PatwcGuardWpdb
    {
        public $options = 'wp_options';
        public $last_error = '';
        public function esc_like($s) { return addcslashes($s, '_%\\'); }
        public function prepare($q, ...$a) { return json_encode(array($q, $a)); }
        public function get_var($prepared)
        {
            list($sql, $args) = json_decode($prepared, true);
            if ($sql !== "SELECT option_value FROM {$this->options} WHERE option_name = %s") { throw new \RuntimeException('guard wpdb: unsupported query: ' . $sql); }
            return isset($GLOBALS['options'][$args[0]]) ? serialize($GLOBALS['options'][$args[0]]) : null;
        }
        public function get_results($prepared, $output = null)
        {
            if ($this->last_error !== '') { return array(); } // What wpdb does on a failed query.
            list($sql, $args) = json_decode($prepared, true);
            if ($sql !== "SELECT option_name, option_value FROM {$this->options} WHERE option_name LIKE %s") { throw new \RuntimeException('guard wpdb: unsupported query: ' . $sql); }
            $prefix = stripcslashes(substr($args[0], 0, -1));
            $rows = array();
            foreach ($GLOBALS['options'] as $k => $v) { if (strpos((string) $k, $prefix) === 0) { $rows[] = array('option_name' => (string) $k, 'option_value' => serialize($v)); } }
            return $rows;
        }
        public $before_delete;
        public function query($prepared)
        {
            list($sql, $args) = json_decode($prepared, true);
            if ($this->before_delete) { $cb = $this->before_delete; $this->before_delete = null; $cb(); }
            if ($sql !== "DELETE FROM {$this->options} WHERE option_name = %s AND option_value = %s") { throw new \RuntimeException('guard wpdb: unsupported query: ' . $sql); }
            if (isset($GLOBALS['options'][$args[0]]) && serialize($GLOBALS['options'][$args[0]]) === $args[1]) { unset($GLOBALS['options'][$args[0]]); return 1; }
            return 0;
        }
    }
    $GLOBALS['wpdb'] = new PatwcGuardWpdb();

    $root = dirname(__DIR__, 3);
    foreach (array('Settings', 'Logger', 'PaymentAttempt', 'Utils/Money', 'Utils/PayArcIds', 'Services/PayArcRequestException', 'Services/PayArcNotSentException', 'Services/PayArcClient', 'Services/TerminalService', 'Server/Sale_Guard', 'Server/Refund_Reask', 'Server/PayArc_Server_Provider') as $file) {
        require_once $root . '/includes/' . $file . '.php';
    }
}

namespace WCPOS\WooCommercePOS\PayArcTerminal\Tests\Regression {
    use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
    use WCPOS\WooCommercePOS\PayArcTerminal\Server\PayArc_Server_Provider;
    use WCPOS\WooCommercePOS\PayArcTerminal\Server\Refund_Reask;
    use WCPOS\WooCommercePOS\PayArcTerminal\Server\Sale_Guard;
    use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcClient;
    use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcNotSentException;
    use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcRequestException;
    use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

    /** Queued answers; each call pops one. A Throwable is thrown. */
    final class QueueClient extends PayArcClient
    {
        public $queue = array();
        public $calls = array();
        public function __construct() {}
        private function next(string $op, array $args)
        {
            $this->calls[] = array($op, $args);
            if (!$this->queue) { throw new \LogicException('Unexpected PayArc call: ' . $op); }
            $a = array_shift($this->queue);
            if ($a instanceof \Throwable) { throw $a; }
            return $a;
        }
        public function sale(array $payload, string $key): array { return $this->next('sale', array($payload, $key)); }
        public function refund(array $payload, string $key): array { return $this->next('refund', array($payload, $key)); }
        public function get_transaction(string $trace): array { return $this->next('get', array($trace)); }
        public function cancel(string $trace, array $payload, string $key): array { return $this->next('cancel', array($trace, $payload, $key)); }
    }

    function check(bool $ok, string $what): void { if (!$ok) { throw new \RuntimeException('FAILED: ' . $what); } }
    function settings(): Settings
    {
        return new Settings(array('mode' => 'test', 'connect_mid' => '123456789012', 'connect_secret_key' => 's', 'default_terminal_id' => '1234567890', 'terminal_registry' => array(array('terminal_id' => '1234567890', 'label' => 'Front')), 'callback_url_token' => 'urltok', 'callback_bearer_token' => 'bearertok', 'tender_type' => 'CREDIT', 'print_receipt' => '0'));
    }
    function adapter(QueueClient $client): PayArc_Server_Provider { return new PayArc_Server_Provider(settings(), $client); }
    function approved(string $trace, array $extra = array()): array
    {
        return array('transactionId' => 'P1ABCDEF12345678', 'transType' => 'SALE', 'status' => 'APPROVED', 'chargeId' => 'ch_1', 'authCode' => 'A1', 'amount' => array('total' => 9295, 'subtotal' => 9295, 'approved' => 9295, 'currency' => 'USD'), 'card' => array('brand' => 'VISA', 'entryMode' => 'CHIP', 'last4' => '4242'), 'processor' => array('responseCode' => '00', 'responseText' => 'Approved'), 'traceId' => $trace, 'metadata' => array('wcpos_payment_id' => 'aaaaaaaa-1111-4222-8333-444455556666', 'terminal_id' => '1234567890')) + $extra;
    }
    function reset(): void { $GLOBALS['orders'] = array(99 => new \PatwcOrder(99)); $GLOBALS['events'] = array(); $GLOBALS['adopted'] = array(); $GLOBALS['lookups'] = array(); $GLOBALS['uuid'] = 0; $GLOBALS['options'] = array(); $GLOBALS['notoptions'] = array(); }
    $row = array('id' => 'aaaaaaaa-1111-4222-8333-444455556666', 'order_id' => 99, 'amount' => '92.95', 'currency' => 'USD', 'provider_refs' => array());

    // Create: the row id is the key; a lost answer, a 5xx and a key conflict are indeterminate; a refusal is final.
    reset(); $c = new QueueClient(); $c->queue = array(array('traceId' => 't1', 'response' => array('status' => 'SUCCESS')));
    $r = adapter($c)->create_reader_action($row, '1234567890');
    check($r['ref'] === 't1' && $r['expires_at'] === null, 'create returns the traceId with no provider deadline');
    check($c->calls[0][1][1] === $row['id'], 'the sale is keyed on the row id');
    check($c->calls[0][1][0]['metadata']['wcpos_payment_id'] === $row['id'] && strlen($c->calls[0][1][0]['transactionId']) === 16, 'the sale names the row and a 16-character transaction id');
    check(Sale_Guard::any_live() && PaymentAttempt::has_in_flight_attempts(), 'a sale on the terminal holds the settings guard');
    $sent = $c->calls[0][1][0];
    // A replay (lost answer or a later sweep) sends the byte-identical command, whatever the settings say now.
    $c = new QueueClient(); $c->queue = array(array('traceId' => 't1', 'response' => array('status' => 'SUCCESS')));
    (new PayArc_Server_Provider(new Settings(array('mode' => 'test', 'connect_mid' => '123456789012', 'connect_secret_key' => 's', 'default_terminal_id' => '1234567890', 'terminal_registry' => array(array('terminal_id' => '1234567890', 'label' => 'Front')), 'tender_type' => 'DEBIT', 'print_receipt' => '3')), $c))->create_reader_action($row, '1234567890');
    check($c->calls[0][1][0] === $sent, 'the replay sends the first command byte for byte');
    // Even with nothing current to build from: the held command is the whole of it.
    $c = new QueueClient(); $c->queue = array(array('traceId' => 't1', 'response' => array('status' => 'SUCCESS')));
    (new PayArc_Server_Provider(new Settings(array('mode' => 'test', 'default_terminal_id' => '')), $c))->create_reader_action($row, '1234567890');
    check($c->calls[0][1][0] === $sent, 'a replay on a site whose terminal and MID are gone still sends the first command');
    // Every held sale is found on its own, whatever else was held or released around it, and a stale one is swept.
    Sale_Guard::hold('second-till', 7, array('y' => 2));
    Sale_Guard::release('second-till');
    check(Sale_Guard::any_live(), 'releasing another till\'s sale leaves this one holding the guard');
    // A marker only the table knows of (another process wrote it, and this one's object cache lists it as missing) holds the guard and survives.
    $GLOBALS['options']['patwc_pro_sale_' . md5('other-process')] = array('order_id' => 5, 'trace_id' => '', 'payload' => array('z' => 1), 'updated_at' => time());
    $GLOBALS['notoptions']['patwc_pro_sale_' . md5('other-process')] = true;
    Sale_Guard::release($row['id']);
    check(Sale_Guard::any_live() && isset($GLOBALS['options']['patwc_pro_sale_' . md5('other-process')]), 'a marker the object cache lists as missing still holds the guard, and is not deleted');
    check(Sale_Guard::held('other-process') !== null, 'a replay finds its own marker in the table when the object cache lists it as missing');
    // The table cannot be read: wpdb answers an empty array and sets last_error; the guard fails closed.
    $GLOBALS['wpdb']->last_error = 'MySQL server has gone away';
    check(Sale_Guard::any_live(), 'an unreadable options table counts as a sale on a terminal');
    $GLOBALS['wpdb']->last_error = '';
    // A stale marker re-held between the read and the delete is kept: the delete compares the value it read.
    $GLOBALS['notoptions'] = array(); unset($GLOBALS['options']['patwc_pro_sale_' . md5('other-process')]); Sale_Guard::release($row['id']);
    $GLOBALS['options']['patwc_pro_sale_' . md5('late')] = array('order_id' => 1, 'trace_id' => '', 'payload' => array(), 'updated_at' => time() - 1801);
    $GLOBALS['wpdb']->before_delete = static function () { Sale_Guard::hold('late', 1, array('again' => true)); };
    check(!Sale_Guard::any_live() && Sale_Guard::held('late') !== null, 'a marker held again between the read and the delete survives the sweep');
    Sale_Guard::release('late');
    $GLOBALS['notoptions'] = array(); unset($GLOBALS['options']['patwc_pro_sale_' . md5('other-process')]);
    Sale_Guard::hold($row['id'], 99, $sent, 't1');
    $GLOBALS['options']['patwc_pro_sale_' . md5('old')] = array('order_id' => 1, 'trace_id' => '', 'payload' => array(), 'updated_at' => time() - 1801);
    check(!Sale_Guard::held('old') && Sale_Guard::any_live() && !isset($GLOBALS['options']['patwc_pro_sale_' . md5('old')]), 'a stale marker neither holds the guard nor survives the pass');
    // A replay the client cannot send is indeterminate: the first command may be on the terminal.
    $c = new QueueClient(); $c->queue = array(new PayArcNotSentException('PayArc Connect base URL is not configured.'));
    $r = adapter($c)->create_reader_action($row, '1234567890');
    check($r instanceof \WP_Error && !empty($r->get_error_data()['indeterminate']), 'a replay the client refused to send is indeterminate');
    Sale_Guard::release($row['id']);
    foreach (array(new \RuntimeException('PayArc request failed before receiving a response.'), new PayArcRequestException('x', 'SERVER_ERROR', 503), new PayArcRequestException('Idempotency key already used', 'IDEMPOTENCY_KEY_CONFLICT', 400)) as $e) {
        $c = new QueueClient(); $c->queue = array($e);
        $r = adapter($c)->create_reader_action($row, '1234567890');
        check($r instanceof \WP_Error && !empty($r->get_error_data()['indeterminate']), 'unanswered create is indeterminate: ' . get_class($e));
    }
    $c = new QueueClient(); $c->queue = array(new PayArcRequestException('offline', 'TERMINAL_OFFLINE', 409));
    $r = adapter($c)->create_reader_action($row, '1234567890');
    check($r instanceof \WP_Error && empty($r->get_error_data()['indeterminate']) && $r->get_error_data()['detail']['code'] === 'TERMINAL_OFFLINE', 'a 409 TERMINAL_OFFLINE is a final refusal with its code');

    // Fetch: not visible yet is pending; statuses map; the traceId is the transaction reference.
    $c = new QueueClient(); $c->queue = array(new PayArcRequestException('nf', 'TRANSACTION_NOT_FOUND', 400), array('status' => 'processing', 'traceId' => 't1'), approved('t1'), array('status' => 'TIMEOUT', 'traceId' => 't1'), array('status' => 'DECLINE', 'traceId' => 't1', 'processor' => array('responseText' => 'Do not honor')), array('status' => 'aborted', 'traceId' => 't1'));
    $a = adapter($c);
    check($a->fetch('t1')['status'] === 'pending', 'TRANSACTION_NOT_FOUND after a sale is pending, not failure');
    check($a->fetch('t1')['status'] === 'in_progress', 'processing is in_progress');
    Sale_Guard::hold('aaaaaaaa-1111-4222-8333-444455556666', 99, array('x' => 1), 't1'); Sale_Guard::hold('other-row', 99, array('x' => 2), 't9');
    $done = $a->fetch('t1');
    check(Sale_Guard::held('aaaaaaaa-1111-4222-8333-444455556666') === null && Sale_Guard::held('other-row') !== null, 'a sale that ended releases its own guard, not another sale\'s on the same order');
    check($done['status'] === 'completed' && $done['amount'] === '92.95' && $done['provider_refs']['transaction_id'] === 't1' && $done['provider_refs']['charge_id'] === 'ch_1' && $done['provider_refs']['payarc_transaction_id'] === 'P1ABCDEF12345678', 'APPROVED is completed with the traceId as the transaction reference');
    check($a->fetch('t1')['status'] === 'expired', 'TIMEOUT is expired');
    $d = $a->fetch('t1'); check($d['status'] === 'failed' && $d['failure_reason'] === 'Do not honor', 'DECLINE is failed with the processor text');
    check($a->fetch('t1')['status'] === 'cancelled', 'aborted is cancelled');
    // A sale the old panel started carries no row id in its metadata; Pro's adoption record names the row to release.
    Sale_Guard::hold('adopted-row', 99, array(), 'trace-adopted'); $GLOBALS['adopted']['trace-adopted'] = 'adopted-row';
    $c2 = new QueueClient(); $c2->queue = array(array('traceId' => 'trace-adopted', 'status' => 'processing'), array('traceId' => 'trace-adopted', 'status' => 'TIMEOUT'));
    $a2 = adapter($c2);
    $a2->fetch('trace-adopted');
    check(Sale_Guard::held('adopted-row') !== null, 'a sale still on the terminal keeps the marker its adoption holds');
    $a2->fetch('trace-adopted');
    check(Sale_Guard::held('adopted-row') === null, 'an adopted old-panel sale that ended releases the marker its adoption holds');

    // Cancel: 200 is confirmed by the read; a refusal leaves the read to decide.
    $c = new QueueClient(); $c->queue = array(array('metadata' => array('terminal_id' => '1234567890')), array('traceId' => 't1'), array('status' => 'aborted', 'traceId' => 't1'));
    check(adapter($c)->cancel('t1') === 'final', 'an accepted cancel whose read shows aborted is final');
    $c = new QueueClient(); $c->queue = array(array('metadata' => array()), new PayArcRequestException('card processing', 'TRANSACTION_CANNOT_BE_CANCELLED', 400), approved('t1'));
    check(adapter($c)->cancel('t1') === 'requested', 'a refused cancel whose read shows approved is requested');
    $c = new QueueClient(); $c->queue = array(array('metadata' => array()), array('traceId' => 't1'), array('status' => 'processing', 'traceId' => 't1'));
    check(adapter($c)->cancel('t1') === 'requested', 'an accepted cancel whose read is still live is requested');

    // Refund: a Pro leg links by its own transaction id; a historical row by the old panel's attempt, else by reading the traceId.
    reset(); $refund = new \WC_Order_Refund(501); $refund->parent = 99; $GLOBALS['orders'][501] = $refund;
    $leg = array('id' => 'r', 'order_id' => 99, 'currency' => 'USD', 'provider_refs' => array('action' => 't1', 'transaction_id' => 't1', 'payarc_transaction_id' => 'P1ABCDEF12345678', 'reader' => '1234567890', 'payarc_tender_type' => 'DEBIT'));
    $c = new QueueClient(); $c->queue = array(array('traceId' => 'rf1'), approved('rf1', array('transType' => 'REFUND')));
    $r = adapter($c)->refund($leg, 501, '5.00');
    check($r['status'] === 'succeeded' && $r['provider_ref'] === 'rf1', 'a refund the terminal already approved is succeeded');
    check($c->calls[0][1][0]['originalTransactionId'] === 'P1ABCDEF12345678' && $c->calls[0][1][0]['amount']['total'] === 500 && strlen($c->calls[0][1][0]['transactionId']) === 16, 'the refund links the sale by its transaction id, in cents');
    $key = $refund->meta[Refund_Reask::key(Refund_Reask::META_ATTEMPT, 'r')];
    check($key !== '' && $c->calls[0][1][1] === $key && $refund->saves >= 1, 'the attempt key is saved on the record, for this row, before the POST and sent');
    check($c->calls[0][1][0]['tenderType'] === 'DEBIT', 'the refund names the tender the sale was taken with');
    // The same refund record on a second payment row is a second command with its own key and id.
    $leg2 = $leg; $leg2['id'] = 'r2'; $leg2['provider_refs']['payarc_transaction_id'] = 'P2ABCDEF12345678'; $leg2['provider_refs']['action'] = 't2'; $leg2['provider_refs']['transaction_id'] = 't2';
    $c2 = new QueueClient(); $c2->queue = array(array('traceId' => 'rfB'), approved('rfB', array('transType' => 'REFUND')));
    adapter($c2)->refund($leg2, 501, '3.00');
    check($c2->calls[0][1][1] !== $key && $c2->calls[0][1][0]['transactionId'] !== $c->calls[0][1][0]['transactionId'] && $c2->calls[0][1][0]['originalTransactionId'] === 'P2ABCDEF12345678', 'a refund split across two payments is two commands with two keys and two ids');
    check(!isset($c->calls[0][1][0]['metadata']['wcpos_payment_id']) && $c->calls[0][1][0]['metadata']['wcpos_refund_row'] === 'r', 'a refund never carries the sale row\'s key in its metadata');
    // Historical: the old panel's attempt on the order names the sale by its charge id.
    reset(); $refund = new \WC_Order_Refund(502); $refund->parent = 99; $GLOBALS['orders'][502] = $refund;
    $GLOBALS['orders'][99]->meta[PaymentAttempt::META_ATTEMPT_HISTORY] = array(array('transaction_id' => 'POLD000000000001', 'charge_id' => 'ch_old', 'trace_id' => 'told', 'terminal_id' => '1234567890', 'status' => 'success'));
    $c = new QueueClient(); $c->queue = array(array('traceId' => 'rf2'), array('status' => 'processing', 'traceId' => 'rf2'));
    $r = adapter($c)->refund(array('id' => 'h', 'currency' => 'USD', 'provider_refs' => array('transaction_id' => 'ch_old')), 502, '5.00');
    check($r['status'] === 'pending' && $r['provider_ref'] === 'rf2' && $c->calls[0][1][0]['originalTransactionId'] === 'POLD000000000001', 'a historical row refunds through the old attempt that carries its charge id, pending until the terminal answers');
    // Unanswered POST: pending, note, re-ask under the same key; a read before the POST is an error with nothing scheduled.
    reset(); $refund = new \WC_Order_Refund(503); $refund->parent = 99; $GLOBALS['orders'][503] = $refund;
    $c = new QueueClient(); $c->queue = array(new \RuntimeException('PayArc request failed before receiving a response.'));
    $r = adapter($c)->refund($leg, 503, '5.00');
    check($r === array('status' => 'pending', 'provider_ref' => null) && count($GLOBALS['events']) === 1 && $GLOBALS['events'][0][1] === array(503, 1, 99, 'r'), 'an unanswered refund POST keeps the record pending and schedules the re-ask for this row');
    check(strpos($GLOBALS['orders'][99]->notes[0], 'did not confirm refund #503') !== false, 'the order says so');
    $key = $refund->meta[Refund_Reask::key(Refund_Reask::META_ATTEMPT, 'r')];
    // A conflict on the key, or nothing sent, keeps the question open; a refusal of the identical replay closes it.
    $c = new QueueClient(); $c->queue = array(new PayArcRequestException('dup', 'DUPLICATE_REQUEST', 400));
    Refund_Reask::run(503, 1, 99, 'r', adapter($c));
    check(end($GLOBALS['events'])[1] === array(503, 2, 99, 'r') && count($GLOBALS['orders'][99]->notes) === 1, 'a key conflict on the re-ask proves nothing: asked again, no note');
    $c = new QueueClient(); $c->queue = array(new PayArcNotSentException('not configured'));
    Refund_Reask::run(503, 2, 99, 'r', adapter($c));
    check(end($GLOBALS['events'])[1] === array(503, 3, 99, 'r'), 'a request that never left the server is asked again later');
    $c = new QueueClient(); $c->queue = array(array('traceId' => 'rf3'));
    Refund_Reask::run(503, 3, 99, 'r', adapter($c));
    check($c->calls[0][1][1] === $key && $c->calls[0][1][0]['originalTransactionId'] === 'P1ABCDEF12345678' && $refund->meta[Refund_Reask::key(Refund_Reask::META_TRACE, 'r')] === 'rf3', 'the re-ask replays the identical request under the saved key and records the traceId');
    Refund_Reask::run(503, 4, 99, 'r', adapter($c));
    check(count($c->calls) === 1, 'an answered record is not asked again');
    reset(); $refund = new \WC_Order_Refund(504); $refund->parent = 99; $GLOBALS['orders'][504] = $refund;
    $c = new QueueClient(); $c->queue = array(new \RuntimeException('lost'));
    $r = adapter($c)->refund(array('id' => 'h', 'currency' => 'USD', 'provider_refs' => array('transaction_id' => '00000000-0000-4000-8000-000000000001')), 504, '5.00');
    check($r instanceof \WP_Error && !empty($r->get_error_data()['indeterminate']) && $GLOBALS['events'] === array() && !isset($refund->meta[Refund_Reask::key(Refund_Reask::META_ATTEMPT, 'h')]), 'a lost read before the POST is an error with nothing made or scheduled');
    // Nothing left the server (credentials or Connect state): a plain refusal, no record kept pending.
    reset(); $refund = new \WC_Order_Refund(506); $refund->parent = 99; $GLOBALS['orders'][506] = $refund;
    $c = new QueueClient(); $c->queue = array(new PayArcNotSentException('PayArc Live mode requires a Live Connect AccessToken.'));
    $r = adapter($c)->refund($leg, 506, '5.00');
    check($r instanceof \WP_Error && empty($r->get_error_data()['indeterminate']) && $GLOBALS['events'] === array(), 'a refund the client refused to send is a plain error');
    $c = new QueueClient(); $c->queue = array(new PayArcNotSentException('PayArc Connect base URL is not configured.'));
    $r = adapter($c)->create_reader_action($row, '1234567890');
    check($r instanceof \WP_Error && empty($r->get_error_data()['indeterminate']), 'a sale the client refused to send is a plain error, not a pending leg');

    // Callback: the URL token or PayArc's bearer authenticates; neither is 401 before any call; adoption outranks the metadata.
    reset();
    $body = json_encode(array('traceId' => '00000000-0000-4000-8000-000000000001', 'transType' => 'SALE', 'status' => 'APPROVED'));
    $make = static function (array $query, string $bearer) use ($body): \WP_REST_Request { $q = new \WP_REST_Request(); $q->set_body($body); $q->set_query_params($query); if ($bearer !== '') { $q->set_header('Authorization', 'Bearer ' . $bearer); } return $q; };
    $c = new QueueClient();
    $r = adapter($c)->verify_webhook($make(array('patwc_cb' => 'wrong'), 'wrong'));
    check($r instanceof \WP_Error && $r->get_error_data()['status'] === 401 && $c->calls === array() && $GLOBALS['lookups'] === array(), 'an unauthenticated callback is refused before any lookup or call');
    foreach (array(array(array('patwc_cb' => 'urltok'), ''), array(array(), 'bearertok')) as $auth) {
        $c = new QueueClient(); $c->queue = array(approved('00000000-0000-4000-8000-000000000001'));
        $r = adapter($c)->verify_webhook($make($auth[0], $auth[1]));
        check(is_array($r) && $r['payment_id'] === 'aaaaaaaa-1111-4222-8333-444455556666' && $r['patch']['status'] === 'captured' && $r['patch']['provider_refs']['action'] === '00000000-0000-4000-8000-000000000001' && $r['patch']['provider_refs']['transaction_id'] === '00000000-0000-4000-8000-000000000001', 'an authenticated callback settles from the read with complete refs');
    }
    $GLOBALS['adopted']['00000000-0000-4000-8000-000000000001'] = 'bbbbbbbb-1111-4222-8333-444455556666';
    $GLOBALS['lookups'] = array();
    $c = new QueueClient(); $c->queue = array(approved('00000000-0000-4000-8000-000000000001'));
    $r = adapter($c)->verify_webhook($make(array('patwc_cb' => 'urltok'), ''));
    check($r['payment_id'] === 'bbbbbbbb-1111-4222-8333-444455556666' && $GLOBALS['lookups'] === array('00000000-0000-4000-8000-000000000001'), 'Pro\'s adoption record outranks the metadata and is read before the call');
    $c = new QueueClient(); $c->queue = array(new PayArcRequestException('nf', 'TRANSACTION_NOT_FOUND', 400));
    $r = adapter($c)->verify_webhook($make(array('patwc_cb' => 'urltok'), ''));
    check($r instanceof \WP_Error && $r->get_error_data()['status'] === 404, 'a transaction PayArc cannot see is unknown');
    // A refund callback is noted on the order, once.
    reset(); $refund = new \WC_Order_Refund(505); $refund->parent = 99; $GLOBALS['orders'][505] = $refund;
    $rb = json_encode(array('traceId' => '00000000-0000-4000-8000-000000000002', 'transType' => 'SALE', 'status' => 'DECLINE')); // The body lies about the type; the read decides.
    $q = new \WP_REST_Request(); $q->set_body($rb); $q->set_query_params(array('patwc_cb' => 'urltok'));
    $view = static function (string $status): array { return array('traceId' => '00000000-0000-4000-8000-000000000002', 'transType' => '', 'status' => $status, 'metadata' => array('wcpos_refund_id' => '505', 'wcpos_refund_row' => 'r')); }; // No transType on the read: the refund it names still routes it.
    $refund->meta[Refund_Reask::key(Refund_Reask::META_REQUEST, 'r')] = array('amount' => array('total' => 9295, 'currency' => 'USD')); // The command attempt_key() saved before the POST.
    $c = new QueueClient(); $c->queue = array($view('processing'), $view('DECLINE'), $view('DECLINE'));
    $r = adapter($c)->verify_webhook($q);
    check($r instanceof \WP_Error && $r->get_error_data()['status'] === 200 && $GLOBALS['orders'][99]->notes === array() && $refund->meta[Refund_Reask::key(Refund_Reask::META_TRACE, 'r')] === '00000000-0000-4000-8000-000000000002', 'a refund still on the terminal is acknowledged without a note, and its trace recorded so no re-ask repeats it');
    $r = adapter($c)->verify_webhook($q);
    check($r instanceof \WP_Error && $r->get_error_data()['status'] === 200 && count($GLOBALS['orders'][99]->notes) === 1 && strpos($GLOBALS['orders'][99]->notes[0], 'refund #505 (92.95 USD, trace 00000000-0000-4000-8000-000000000002) was not returned: PayArc reports it as DECLINE') !== false && strpos($GLOBALS['orders'][99]->notes[0], 'Delete the record') !== false, 'a refund outcome is noted on the order, naming the part and the trace');
    // A refund of a split payment is one record: when this part is less than the record (the rest went to
    // another PayArc row, or to cash, which PayArc's meta cannot see), a failed part never says to delete it.
    $refund->amount = '112.95';
    unset($refund->meta[Refund_Reask::key('_patwc_refund_outcome', 'r')]);
    $c->queue = array($view('DECLINE'));
    adapter($c)->verify_webhook($q);
    check(count($GLOBALS['orders'][99]->notes) === 2 && strpos($GLOBALS['orders'][99]->notes[1], 'refund #505 (92.95 USD, trace 00000000-0000-4000-8000-000000000002) was not returned') !== false && strpos($GLOBALS['orders'][99]->notes[1], 'Other parts of this refund') !== false && strpos($GLOBALS['orders'][99]->notes[1], 'Delete the record') === false, 'a failed part of a split refund names its amount and keeps the record');
    $refund->amount = '92.95'; unset($refund->meta[Refund_Reask::key(Refund_Reask::META_REQUEST, 'r')]);
    check($GLOBALS['orders'][99]->paid === false, 'a refund is never settled against a sale row');
    adapter($c)->verify_webhook($q);
    check(count($GLOBALS['orders'][99]->notes) === 2, 'the same outcome is noted once');

    echo "server-adapter cases passed\n";
}
