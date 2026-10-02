<?php

/**
 * Regression #23: the webhook and the POS poll can complete one PayArc
 * payment twice, reducing stock twice.
 *
 * Both requests load the unpaid order before either takes the per-order lock,
 * so the second still holds an unpaid in-memory copy after the first has paid
 * the database row. Completion must decide from a fresh copy.
 */

declare(strict_types=1);

require_once __DIR__ . '/fake-wpdb.php';

$root = dirname(__DIR__, 3);
foreach (array(
    $root . '/includes/Settings.php',
    $root . '/includes/Logger.php',
    $root . '/includes/PaymentAttempt.php',
    $root . '/includes/PaymentReconciler.php',
    $root . '/includes/PaymentLock.php',
    $root . '/includes/Utils/Money.php',
    $root . '/includes/Utils/PayArcIds.php',
    $root . '/includes/Services/PayArcRequestException.php',
    $root . '/includes/Services/PayArcClient.php',
    $root . '/includes/Services/TerminalService.php',
    $root . '/includes/Services/PayArcPaymentService.php',
) as $file) {
    require_once $file;
}

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentReconciler;
use WCPOS\WooCommercePOS\PayArcTerminal\Services\PayArcPaymentService;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

function patwc_race_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function wc_get_logger()
{
    return new class {
        public function info($message, array $context = array()): void
        {
            $GLOBALS['patwc_race_logs'][] = array('message' => $message, 'context' => $context);
        }

        public function warning($message, array $context = array()): void
        {
            $this->info($message, $context);
        }

        public function error($message, array $context = array()): void
        {
            $this->info($message, $context);
        }
    };
}

const PATWC_RACE_ORDER_ID = 1001;
const PATWC_RACE_CLAIM = 'patwc_lock_1001_complete_payment';

class PatwcRaceOrder
{
    /** @var array<string, mixed> */
    private $row;

    /** @var array<string, bool> */
    private $changedFields = array();

    /** @var array<string, bool> */
    private $changedMeta = array();

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function get_id(): int
    {
        return PATWC_RACE_ORDER_ID;
    }

    public function get_total(): string
    {
        return '10.23';
    }

    public function get_currency(): string
    {
        return 'USD';
    }

    public function get_meta($key, $single = true)
    {
        return $this->row['meta'][$key] ?? '';
    }

    public function get_payment_method(): string
    {
        return $this->row['payment_method'];
    }

    public function get_payment_method_title(): string
    {
        return $this->row['payment_method_title'];
    }

    public function is_paid(): bool
    {
        return in_array($this->row['status'], array('processing', 'completed'), true);
    }

    public function update_meta_data($key, $value): void
    {
        if (!array_key_exists($key, $this->row['meta']) || $this->row['meta'][$key] !== $value) {
            $this->row['meta'][$key] = $value;
            $this->changedMeta[$key] = true;
        }
    }

    public function delete_meta_data($key): void
    {
        if (array_key_exists($key, $this->row['meta'])) {
            unset($this->row['meta'][$key]);
            $this->changedMeta[$key] = true;
        }
    }

    public function set_payment_method($method): void
    {
        $this->set_field('payment_method', $method);
    }

    public function set_payment_method_title($title): void
    {
        $this->set_field('payment_method_title', $title);
    }

    public function add_order_note($note): void
    {
        $GLOBALS['patwc_race_notes'][] = $note;
    }

    public function save(): void
    {
        $GLOBALS['patwc_race_saves']++;
        // WC_Data persists changes, not the entire stale snapshot; notes are immediate.
        foreach (array_keys($this->changedFields) as $field) {
            $GLOBALS['patwc_race_rows'][$this->get_id()][$field] = $this->row[$field];
        }
        foreach (array_keys($this->changedMeta) as $key) {
            if (array_key_exists($key, $this->row['meta'])) {
                $GLOBALS['patwc_race_rows'][$this->get_id()]['meta'][$key] = $this->row['meta'][$key];
            } else {
                unset($GLOBALS['patwc_race_rows'][$this->get_id()]['meta'][$key]);
            }
        }
        $this->changedFields = array();
        $this->changedMeta = array();
    }

    // WC_Data::read_meta_data(): the 'orders' meta cache unless forced to read the database.
    public function read_meta_data($forceRead = false): void
    {
        if ($forceRead) {
            // With HPOS data caching on, even a forced read goes through the meta store's cache.
            $this->row['meta'] = $GLOBALS['patwc_race_hpos_meta_cache'][$this->get_id()]
                ?? $GLOBALS['patwc_race_rows'][$this->get_id()]['meta'];
            $GLOBALS['patwc_race_meta_cache'][$this->get_id()] = $this->row['meta'];
            $this->changedMeta = array();
        }
    }

    public function payment_complete($transactionId = ''): bool
    {
        if (!isset($GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM])) {
            $GLOBALS['patwc_race_unclaimed_completions']++;
        }
        if ($GLOBALS['patwc_race_throw_completion']) {
            $GLOBALS['patwc_race_throw_completion'] = false;
            throw new RuntimeException('completion failed');
        }
        if (!in_array($this->row['status'], array('pending', 'failed', 'on-hold'), true)) {
            return false;
        }
        $this->set_field('status', 'processing');
        $this->set_field('transaction_id', $transactionId);
        // Stands in for wc_maybe_reduce_stock_levels on woocommerce_payment_complete:
        // its stock_reduced guard is also read-then-write across requests.
        $GLOBALS['patwc_race_stock_reductions']++;
        $GLOBALS['patwc_race_payment_complete_calls']++;
        $this->save();

        return true;
    }

    /**
     * @param mixed $value
     */
    private function set_field(string $field, $value): void
    {
        if ($this->row[$field] !== $value) {
            $this->row[$field] = $value;
            $this->changedFields[$field] = true;
        }
    }
}

// Each request keeps the order it loaded until clean_post_cache() (posts store)
// or OrderCache::remove() (HPOS) drops it, as WooCommerce does without a
// persistent object cache. HPOS datastore caching keeps the order row until
// clear_cached_data(), and order meta comes from the request's 'orders' meta
// cache, which neither of those clears, until read_meta_data(true).
function wc_get_order($id)
{
    if ($GLOBALS['patwc_race_missing_order'] || !isset($GLOBALS['patwc_race_rows'][$id])) {
        return false;
    }
    if (isset($GLOBALS['patwc_race_hpos_cache'][$id])) {
        return clone $GLOBALS['patwc_race_hpos_cache'][$id];
    }
    if (isset($GLOBALS['patwc_race_hpos_data_cache'][$id])) {
        return new PatwcRaceOrder($GLOBALS['patwc_race_hpos_data_cache'][$id]);
    }
    if (!isset($GLOBALS['patwc_race_post_cache'][$id])) {
        $row = $GLOBALS['patwc_race_rows'][$id];
        if (isset($GLOBALS['patwc_race_hpos_meta_cache'][$id])) {
            $row['meta'] = $GLOBALS['patwc_race_hpos_meta_cache'][$id];
        } elseif (isset($GLOBALS['patwc_race_meta_cache'][$id])) {
            $row['meta'] = $GLOBALS['patwc_race_meta_cache'][$id];
        } else {
            $GLOBALS['patwc_race_meta_cache'][$id] = $row['meta'];
        }
        $GLOBALS['patwc_race_post_cache'][$id] = new PatwcRaceOrder($row);
    }

    return clone $GLOBALS['patwc_race_post_cache'][$id];
}

function clean_post_cache($id): void
{
    unset($GLOBALS['patwc_race_post_cache'][$id]);
    $GLOBALS['patwc_race_cleaned_posts'][] = $id;
}

class PatwcRaceClient
{
    /** @var array<string, mixed> */
    public $transaction;

    /** @var int */
    public $lookups = 0;

    /** @var int */
    public $cancels = 0;

    public function get_transaction(string $traceId): array
    {
        $this->lookups++;

        return $this->transaction;
    }

    public function cancel(string $traceId, array $terminal, string $idempotencyKey): array
    {
        $this->cancels++;

        throw new RuntimeException('TRANSACTION_CANNOT_BE_CANCELLED');
    }
}

function patwc_race_reset(): void
{
    $GLOBALS['patwc_race_rows'] = array(
        PATWC_RACE_ORDER_ID => array(
            'status' => 'pending',
            'transaction_id' => '',
            'payment_method' => '',
            'payment_method_title' => '',
            'meta' => array(
                PaymentAttempt::META_CURRENT_TRACE_ID => 'trace-1001',
                PaymentAttempt::META_CURRENT_TRANSACTION_ID => 'txn-1001',
                PaymentAttempt::META_CURRENT_STATUS => 'processing',
                PaymentAttempt::META_CURRENT_TERMINAL_ID => 'TERM-1',
                PaymentAttempt::META_CURRENT_ATTEMPT => array(
                    'status' => 'processing',
                    'trace_id' => 'trace-1001',
                    'transaction_id' => 'txn-1001',
                    'terminal_id' => 'TERM-1',
                ),
            ),
        ),
    );
    $GLOBALS['patwc_race_notes'] = array();
    $GLOBALS['patwc_race_stock_reductions'] = 0;
    $GLOBALS['patwc_race_payment_complete_calls'] = 0;
    $GLOBALS['patwc_race_post_cache'] = array();
    $GLOBALS['patwc_race_hpos_cache'] = array();
    $GLOBALS['patwc_race_hpos_data_cache'] = array();
    $GLOBALS['patwc_race_hpos_meta_cache'] = array();
    $GLOBALS['patwc_race_meta_cache'] = array();
    $GLOBALS['patwc_race_cleaned_posts'] = array();
    $GLOBALS['patwc_race_saves'] = 0;
    $GLOBALS['patwc_race_logs'] = array();
    $GLOBALS['patwc_race_throw_completion'] = false;
    $GLOBALS['patwc_race_missing_order'] = false;
    $GLOBALS['wpdb']->rows = array();
}

function patwc_race_completion_notes(): int
{
    return count(array_filter($GLOBALS['patwc_race_notes'], static function (string $note): bool {
        return strpos($note, 'final status: success') !== false;
    }));
}

$payload = array(
    'traceId' => 'trace-1001',
    'transactionId' => 'txn-1001',
    'chargeId' => 'charge-1001',
    'status' => 'SUCCESS',
    'amount' => array('approved' => 1023, 'total' => 1023, 'currency' => 'USD'),
    'metadata' => array('order_id' => '1001'),
);
$settings = new Settings(array('title' => 'PayArc Terminal', 'tenant_id' => 'tenant-1', 'default_terminal_id' => 'TERM-1'));
$GLOBALS['patwc_race_unclaimed_completions'] = 0;
$reconciler = new PaymentReconciler($settings);

// Scenario 1: the webhook and the poll load their copies while the order is unpaid.
patwc_race_reset();
$webhookCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$pollCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$webhookResult = $reconciler->reconcile($webhookCopy, $payload, 'webhook');
patwc_race_expect('success' === ($webhookResult['status'] ?? ''), 'the webhook must reconcile the approved transaction');
$pollResult = $reconciler->reconcile($pollCopy, $payload, 'poll');
// Stock first, so the unfixed code fails on the merchant-visible regression.
patwc_race_expect(1 === $GLOBALS['patwc_race_stock_reductions'], 'a payment reconciled by webhook and poll must reduce stock once (reduced ' . $GLOBALS['patwc_race_stock_reductions'] . ' times)');
patwc_race_expect(1 === $GLOBALS['patwc_race_payment_complete_calls'], 'webhook and poll must complete payment once (completed ' . $GLOBALS['patwc_race_payment_complete_calls'] . ' times)');
patwc_race_expect('idempotent' === ($pollResult['status'] ?? ''), 'the poll holding a stale unpaid copy must report the callback already processed');
patwc_race_expect('charge-1001' === $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['transaction_id'], 'the order must keep the PayArc charge id');
patwc_race_expect('processing' === $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['status'], 'the paid order must stay processing');
patwc_race_expect(1 === patwc_race_completion_notes(), 'webhook and poll must write one final-status note (wrote ' . patwc_race_completion_notes() . ')');
patwc_race_expect(array(PATWC_RACE_ORDER_ID, PATWC_RACE_ORDER_ID) === $GLOBALS['patwc_race_cleaned_posts'], 'both approved reconciliations must invalidate the post cache');
patwc_race_expect(0 === $GLOBALS['patwc_race_unclaimed_completions'], 'completion must hold the complete_payment claim');
patwc_race_expect(!isset($GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM]), 'completion must release its claim');

// Scenario 2: another PayArc transaction paid the order after this request loaded it.
patwc_race_reset();
$stale = wc_get_order(PATWC_RACE_ORDER_ID);
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['status'] = 'processing';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['transaction_id'] = 'charge-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRACE_ID] = 'trace-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRANSACTION_ID] = 'txn-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_ATTEMPT] = array(
    'status' => 'success',
    'trace_id' => 'trace-other',
    'transaction_id' => 'txn-other',
);
$conflict = $reconciler->reconcile($stale, $payload, 'poll');
patwc_race_expect('conflict' === ($conflict['status'] ?? ''), 'a stale copy of an order paid by another transaction must report conflict');
patwc_race_expect(0 === $GLOBALS['patwc_race_payment_complete_calls'] && 0 === $GLOBALS['patwc_race_stock_reductions'], 'a conflicting payment must not complete or reduce stock');
patwc_race_expect('charge-other' === $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['transaction_id'], 'a conflicting payment must not overwrite the other transaction');

// Scenario 3: while another request holds the completion claim, answer pending and touch nothing.
patwc_race_reset();
$unchanged = $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID];
patwc_race_expect(PaymentLock::acquire(PATWC_RACE_ORDER_ID, 'complete_payment', 120), 'another request claims completion');
$busy = $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'poll');
patwc_race_expect(array('status' => 'pending', 'continue_polling' => true) === $busy, 'a busy completion must answer pending and keep polling');
patwc_race_expect(0 === $GLOBALS['patwc_race_payment_complete_calls'] && 0 === $GLOBALS['patwc_race_stock_reductions'], 'a busy completion must not complete or reduce stock');
patwc_race_expect($unchanged === $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID] && 0 === $GLOBALS['patwc_race_saves'], 'a busy completion must not touch or save the order');
patwc_race_expect(array() === $GLOBALS['patwc_race_cleaned_posts'], 'a busy completion must not reload the order');
// Logger sets its own 'source' (the plugin), so the path that found the claim busy needs its own key.
$busyLogs = array_values(array_filter($GLOBALS['patwc_race_logs'], static function (array $log): bool {
    return $log['message'] === 'PayArc payment completion already in progress for this order.';
}));
patwc_race_expect(1 === count($busyLogs) && 'poll' === ($busyLogs[0]['context']['completion_source'] ?? null), 'a busy completion must log which path found the claim held');
PaymentLock::release(PATWC_RACE_ORDER_ID, 'complete_payment');
$retried = $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'poll');
patwc_race_expect('success' === $retried['status'] && 1 === $GLOBALS['patwc_race_payment_complete_calls'] && 1 === $GLOBALS['patwc_race_stock_reductions'], 'the next poll must complete exactly once');
patwc_race_expect(!isset($GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM]), 'a successful completion must release the claim');

// Scenario 4: a claim left by a request that died is taken over after it expires.
patwc_race_reset();
$GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM] = json_encode(array('token' => 'dead', 'expires_at' => time() - 60));
$recovered = $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'webhook');
patwc_race_expect('success' === $recovered['status'] && 1 === $GLOBALS['patwc_race_payment_complete_calls'], 'an expired claim must allow one completion');
patwc_race_expect(!isset($GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM]), 'a recovered completion must release the claim');

// Scenario 5: an exception releases the claim so the next request can finish.
patwc_race_reset();
$GLOBALS['patwc_race_throw_completion'] = true;
try {
    $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'webhook');
    patwc_race_expect(false, 'a completion exception must propagate');
} catch (RuntimeException $exception) {
    patwc_race_expect('completion failed' === $exception->getMessage(), 'the original exception must propagate');
}
patwc_race_expect(!isset($GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM]), 'a throwing completion must release the claim');
$retried = $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'poll');
patwc_race_expect('success' === $retried['status'] && 1 === $GLOBALS['patwc_race_payment_complete_calls'], 'a retry after an exception must complete once');

// Scenario 6: an unapproved transaction needs neither the claim nor a reload.
patwc_race_reset();
patwc_race_expect(PaymentLock::acquire(PATWC_RACE_ORDER_ID, 'complete_payment', 120), 'hold completion while reconciling a decline');
$held = $GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM];
$declined = $reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), array_merge($payload, array('status' => 'DECLINED')), 'poll');
patwc_race_expect('decline' === $declined['status'], 'a decline must reconcile despite a held completion claim');
patwc_race_expect($held === $GLOBALS['wpdb']->rows[PATWC_RACE_CLAIM] && array() === $GLOBALS['patwc_race_cleaned_posts'], 'a decline must not claim or reload');
PaymentLock::release(PATWC_RACE_ORDER_ID, 'complete_payment');

// Scenario 7: when WooCommerce cannot reload the order, complete the copy the caller gave.
patwc_race_reset();
$copy = wc_get_order(PATWC_RACE_ORDER_ID);
$GLOBALS['patwc_race_missing_order'] = true;
$fallback = $reconciler->reconcile($copy, $payload, 'poll');
patwc_race_expect('success' === $fallback['status'] && $copy->is_paid(), 'a missing reload must use the given order');

// Scenario 8: the poll's own copy is stale after the webhook completed the order.
// It must answer success from the database without asking PayArc again.
patwc_race_reset();
$client = new PatwcRaceClient();
$client->transaction = $payload;
$service = new PayArcPaymentService($settings, $client, null, $reconciler, static function (): int {
    return time();
});
$pollCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'webhook');
$polled = $service->poll_order($pollCopy);
patwc_race_expect(1 === $GLOBALS['patwc_race_payment_complete_calls'] && 1 === $GLOBALS['patwc_race_stock_reductions'], 'a poll after the webhook must not complete again');
patwc_race_expect('success' === ($polled['status'] ?? '') && false === ($polled['continue_polling'] ?? null), 'a poll after the webhook must answer success so the panel submits the order');
patwc_race_expect(0 === $client->lookups, 'a poll after the webhook must not look the transaction up again');

// Scenario 9: a cancel from a stale copy after the webhook completed the order
// must answer success and must not send a cancel to the terminal.
patwc_race_reset();
$client = new PatwcRaceClient();
$client->transaction = $payload;
$service = new PayArcPaymentService($settings, $client, null, $reconciler, static function (): int {
    return time();
});
$cancelCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'webhook');
$cancelled = $service->cancel_order_payment($cancelCopy);
patwc_race_expect(1 === $GLOBALS['patwc_race_payment_complete_calls'], 'a cancel after the webhook must not complete again');
patwc_race_expect('success' === ($cancelled['status'] ?? '') && false === ($cancelled['continue_polling'] ?? null), 'a cancel after the webhook must answer success');
patwc_race_expect(0 === $client->cancels, 'a cancel after the webhook must not cancel on the terminal');

// Scenario 10: HPOS keeps its own order cache, which must be cleared as well.
class PatwcRaceOrderCache
{
    public function remove($id): void
    {
        unset($GLOBALS['patwc_race_hpos_cache'][$id]);
    }
}
class_alias(PatwcRaceOrderCache::class, 'Automattic\\WooCommerce\\Caches\\OrderCache');
class PatwcRaceOrdersTableDataStore
{
    // Like WooCommerce 11.1.2: the meta cache is cleared only for ids whose
    // row-cache delete succeeded, and a delete fails when the row entry is gone.
    public function clear_cached_data(array $orderIds): array
    {
        $deleted = array();
        foreach ($orderIds as $id) {
            $deleted[$id] = isset($GLOBALS['patwc_race_hpos_data_cache'][$id]);
            unset($GLOBALS['patwc_race_hpos_data_cache'][$id]);
        }
        (new PatwcRaceOrdersTableDataStoreMeta())->clear_cached_data(array_keys(array_filter($deleted)));

        return $deleted;
    }
}
class_alias(PatwcRaceOrdersTableDataStore::class, 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore');
class PatwcRaceOrdersTableDataStoreMeta
{
    public function clear_cached_data(array $orderIds): array
    {
        foreach ($orderIds as $id) {
            unset($GLOBALS['patwc_race_hpos_meta_cache'][$id]);
        }

        return array_fill_keys($orderIds, true);
    }
}
class_alias(PatwcRaceOrdersTableDataStoreMeta::class, 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStoreMeta');
function wc_get_container()
{
    return new class {
        public function get($class)
        {
            if ('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' === $class) {
                return new PatwcRaceOrdersTableDataStore();
            }
            if ('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStoreMeta' === $class) {
                return new PatwcRaceOrdersTableDataStoreMeta();
            }
            patwc_race_expect('Automattic\\WooCommerce\\Caches\\OrderCache' === $class, 'reload must ask for the HPOS order cache');

            return new PatwcRaceOrderCache();
        }
    };
}
patwc_race_reset();
$webhookCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$pollCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$GLOBALS['patwc_race_hpos_cache'][PATWC_RACE_ORDER_ID] = clone $pollCopy;
$reconciler->reconcile($webhookCopy, $payload, 'webhook');
// The webhook's request cleared its own HPOS cache; the poll's request still has the stale entry.
$GLOBALS['patwc_race_hpos_cache'][PATWC_RACE_ORDER_ID] = clone $pollCopy;
$hpos = $reconciler->reconcile($pollCopy, $payload, 'poll');
patwc_race_expect('idempotent' === ($hpos['status'] ?? '') && 1 === $GLOBALS['patwc_race_payment_complete_calls'], 'the HPOS reload must see the completion from the other request');

// Scenario 11: with HPOS datastore caching on, the order row is cached apart from OrderCache.
patwc_race_reset();
$webhookCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$pollCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$unpaidRow = $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID];
$reconciler->reconcile($webhookCopy, $payload, 'webhook');
// The poll's request cached the row while it was unpaid.
$GLOBALS['patwc_race_hpos_data_cache'][PATWC_RACE_ORDER_ID] = $unpaidRow;
$cached = $reconciler->reconcile($pollCopy, $payload, 'poll');
patwc_race_expect(1 === $GLOBALS['patwc_race_payment_complete_calls'] && 1 === $GLOBALS['patwc_race_stock_reductions'], 'the reload must clear the HPOS datastore cache (completed ' . $GLOBALS['patwc_race_payment_complete_calls'] . ' times)');
patwc_race_expect('idempotent' === ($cached['status'] ?? ''), 'the reload past the HPOS datastore cache must see the completion from the other request');

// Scenario 12: fresh meta alone does not stop a stale HPOS status. When another
// transaction paid the order, a cached unpaid row would complete it again.
foreach (array('order cache' => 'patwc_race_hpos_cache', 'datastore cache' => 'patwc_race_hpos_data_cache') as $cacheName => $cacheGlobal) {
    patwc_race_reset();
    $stale = wc_get_order(PATWC_RACE_ORDER_ID);
    $GLOBALS[$cacheGlobal][PATWC_RACE_ORDER_ID] = $cacheGlobal === 'patwc_race_hpos_cache'
        ? clone $stale
        : $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID];
    $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['status'] = 'processing';
    $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['transaction_id'] = 'charge-other';
    $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRACE_ID] = 'trace-other';
    $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRANSACTION_ID] = 'txn-other';
    $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_ATTEMPT] = array(
        'status' => 'success',
        'trace_id' => 'trace-other',
        'transaction_id' => 'txn-other',
    );
    $conflict = $reconciler->reconcile($stale, $payload, 'poll');
    patwc_race_expect(0 === $GLOBALS['patwc_race_payment_complete_calls'] && 0 === $GLOBALS['patwc_race_stock_reductions'], 'a stale HPOS ' . $cacheName . ' must not complete an order paid by another transaction');
    patwc_race_expect('conflict' === ($conflict['status'] ?? ''), 'a stale HPOS ' . $cacheName . ' copy of an order paid by another transaction must report conflict');
}

// Scenario 13 (#25): with HPOS data caching on, the row's cache entry can already be
// gone, so WooCommerce's row-cache delete fails and leaves the meta entry in place.
// The reload must clear the meta store's cache itself.
patwc_race_reset();
$client = new PatwcRaceClient();
$client->transaction = $payload;
$service = new PayArcPaymentService($settings, $client, null, $reconciler, static function (): int {
    return time();
});
$pollCopy = wc_get_order(PATWC_RACE_ORDER_ID);
$unpaidMeta = $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'];
$reconciler->reconcile(wc_get_order(PATWC_RACE_ORDER_ID), $payload, 'webhook');
// The poll's request cached the meta while the payment was still in flight.
$GLOBALS['patwc_race_hpos_meta_cache'][PATWC_RACE_ORDER_ID] = $unpaidMeta;
$polled = $service->poll_order($pollCopy);
patwc_race_expect(0 === $client->lookups, 'a poll after the webhook must not look the transaction up again when the HPOS row-cache delete fails');
patwc_race_expect(1 === patwc_race_completion_notes(), 'a stale HPOS meta cache must not add a second final-status note (wrote ' . patwc_race_completion_notes() . ')');
patwc_race_expect('success' === ($polled['status'] ?? '') && 1 === $GLOBALS['patwc_race_payment_complete_calls'], 'a poll past a stale HPOS meta cache must answer success and complete once');

// Scenario 14 (#25): the same stale meta must not hide that another transaction paid the order.
patwc_race_reset();
$stale = wc_get_order(PATWC_RACE_ORDER_ID);
$GLOBALS['patwc_race_hpos_meta_cache'][PATWC_RACE_ORDER_ID] = $GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'];
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['status'] = 'processing';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['transaction_id'] = 'charge-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRACE_ID] = 'trace-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_TRANSACTION_ID] = 'txn-other';
$GLOBALS['patwc_race_rows'][PATWC_RACE_ORDER_ID]['meta'][PaymentAttempt::META_CURRENT_ATTEMPT] = array(
    'status' => 'success',
    'trace_id' => 'trace-other',
    'transaction_id' => 'txn-other',
);
$conflict = $reconciler->reconcile($stale, $payload, 'webhook');
patwc_race_expect('conflict' === ($conflict['status'] ?? ''), 'a stale HPOS meta cache must not hide that another transaction paid the order');
patwc_race_expect(0 === $GLOBALS['patwc_race_payment_complete_calls'], 'a stale HPOS meta cache must not complete an order another transaction paid');

patwc_race_expect(0 === $GLOBALS['patwc_race_unclaimed_completions'], 'every completion must hold the complete_payment claim');

echo "payment-complete-race ok\n";
