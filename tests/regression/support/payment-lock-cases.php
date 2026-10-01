<?php

/**
 * Regression #23: PaymentLock must be an atomic per-order claim.
 *
 * add_option() checks and then upserts, its expiry takeover deleted whatever
 * row it found, and release deleted a newer holder's lock. The lock now claims
 * the options row with INSERT IGNORE, takes over only the expired row it read,
 * and releases only the row it wrote.
 */

declare(strict_types=1);

require_once __DIR__ . '/fake-wpdb.php';
require_once dirname(__DIR__, 3) . '/includes/PaymentLock.php';

use WCPOS\WooCommercePOS\PayArcTerminal\PaymentLock;

function patwc_lock_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$wpdb = $GLOBALS['wpdb'];
$key = 'patwc_lock_123_reconcile_payment';

patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment') === true, 'a free lock must be acquired');
patwc_lock_expect(isset($wpdb->rows[$key]), 'the claim must be an options row');
patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment') === false, 'a held lock must not be acquired again');
PaymentLock::release(123, 'reconcile_payment');
patwc_lock_expect(!isset($wpdb->rows[$key]), 'release must delete the claim it holds');
patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment') === true, 'a released lock must be acquired again');
PaymentLock::release(123, 'reconcile_payment');

$held = json_decode((string) ($wpdb->rows[$key] ?? ''), true);
patwc_lock_expect($held === null, 'release must leave no row behind');
PaymentLock::acquire(123, 'reconcile_payment', 120);
$held = json_decode($wpdb->rows[$key], true);
patwc_lock_expect(is_array($held) && is_string($held['token'] ?? null) && $held['token'] !== '', 'the claim must carry an owner token');
patwc_lock_expect(($held['expires_at'] ?? 0) >= time() + 119, 'the claim must expire after the given ttl');
PaymentLock::release(123, 'reconcile_payment');

$expired = json_encode(array('token' => 'old', 'expires_at' => time() - 60));
$live = json_encode(array('token' => 'competitor', 'expires_at' => time() + 120));

$wpdb->rows[$key] = $expired;
patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment'), 'an expired claim must be taken over');
$taken = json_decode($wpdb->rows[$key], true);
patwc_lock_expect('old' !== $taken['token'] && $taken['expires_at'] >= time(), 'takeover must store a fresh token and expiry');
PaymentLock::release(123, 'reconcile_payment');

// The first insert loses to the expired row; another request wins the second.
$wpdb->rows[$key] = $expired;
$wpdb->before_insert = function () use ($wpdb, $key, $live): void {
    $wpdb->before_insert = function () use ($wpdb, $key, $live): void {
        $wpdb->rows[$key] = $live;
    };
};
patwc_lock_expect(!PaymentLock::acquire(123, 'reconcile_payment'), 'a competing claim between takeover attempts must win');
patwc_lock_expect($live === $wpdb->rows[$key], 'the competing claim must stay untouched');
PaymentLock::release(123, 'reconcile_payment');
patwc_lock_expect($live === $wpdb->rows[$key], 'release without ownership must leave the existing row');
unset($wpdb->rows[$key]);

patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment'), 'acquire before replacement');
$wpdb->rows[$key] = $live;
PaymentLock::release(123, 'reconcile_payment');
patwc_lock_expect($live === $wpdb->rows[$key], 'an old holder must not delete a replacement claim');
unset($wpdb->rows[$key]);

// A lock row written by 0.1.16 (serialized by add_option) or garbage is treated as expired.
foreach (array('{}', '{broken', '{"expires_at":"invalid"}', serialize(array('expires_at' => time() + 30))) as $invalid) {
    $wpdb->rows[$key] = $invalid;
    patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment'), 'missing or unparsable expiry must allow takeover: ' . $invalid);
    PaymentLock::release(123, 'reconcile_payment');
}

patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment'), 'acquire before with_lock contention');
$busyRan = false;
$busy = PaymentLock::with_lock(123, 'reconcile_payment', function () use (&$busyRan): array {
    $busyRan = true;

    return array('status' => 'unexpected');
});
patwc_lock_expect(!$busyRan, 'a busy with_lock must not run its callback');
patwc_lock_expect(array(
    'status' => 'conflict',
    'message' => 'Another PayArc operation is already in progress for this order.',
    'continue_polling' => true,
) === $busy, 'a busy with_lock must keep its conflict answer');
PaymentLock::release(123, 'reconcile_payment');

$result = PaymentLock::with_lock(123, 'reconcile_payment', function () use ($wpdb, $key): array {
    patwc_lock_expect(isset($wpdb->rows[$key]), 'the callback must run with the claim held');

    return array('status' => 'ok');
});
patwc_lock_expect(array('status' => 'ok') === $result && !isset($wpdb->rows[$key]), 'with_lock must return the callback result and release');

try {
    PaymentLock::with_lock(123, 'reconcile_payment', function (): array {
        throw new RuntimeException('callback failed');
    });
    patwc_lock_expect(false, 'a callback exception must propagate');
} catch (RuntimeException $exception) {
    patwc_lock_expect('callback failed' === $exception->getMessage(), 'the original callback exception must propagate');
}
patwc_lock_expect(!isset($wpdb->rows[$key]), 'a throwing callback must release the claim');

// The incumbent can release between a failed insert and the read that follows.
$GLOBALS['wpdb'] = new class extends PatwcFakeWpdb {
    public function get_var($prepared)
    {
        $this->rows = array();

        return parent::get_var($prepared);
    }
};
$GLOBALS['wpdb']->rows[$key] = $live;
patwc_lock_expect(PaymentLock::acquire(123, 'reconcile_payment'), 'a row gone at read time must permit one more claim');
PaymentLock::release(123, 'reconcile_payment');

// Without WordPress (no $wpdb) the lock still serialises within the process.
unset($GLOBALS['wpdb']);
patwc_lock_expect(PaymentLock::acquire(456, 'terminal'), 'the in-process fallback must acquire');
patwc_lock_expect(!PaymentLock::acquire(456, 'terminal'), 'the in-process fallback must refuse a held lock');
PaymentLock::release(456, 'terminal');
patwc_lock_expect(PaymentLock::acquire(456, 'terminal'), 'the in-process fallback must release');
PaymentLock::release(456, 'terminal');

echo "payment-lock ok\n";
