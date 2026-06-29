<?php

declare(strict_types=1);

use WCPOS\WooCommercePOS\PayArcTerminal\Utils\Money;
use WCPOS\WooCommercePOS\PayArcTerminal\Utils\PayArcIds;

$moneyFile = dirname(__DIR__, 2) . '/includes/Utils/Money.php';
$idsFile = dirname(__DIR__, 2) . '/includes/Utils/PayArcIds.php';

if (!is_readable($moneyFile)) {
    throw new RuntimeException('Money utility class file is missing.');
}

if (!is_readable($idsFile)) {
    throw new RuntimeException('PayArcIds utility class file is missing.');
}

require_once $moneyFile;
require_once $idsFile;

function patwc_money_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function patwc_money_assert_throws(callable $callback, string $expectedClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException($message . ' Expected ' . $expectedClass . ', got ' . get_class($exception) . '.');
    }

    throw new RuntimeException($message . ' Expected ' . $expectedClass . ' to be thrown.');
}

patwc_money_assert_same(1023, Money::to_minor_units('10.23', 'USD'), 'USD decimal amount should convert to cents.');
patwc_money_assert_same(9, Money::to_minor_units('0.09', 'USD'), 'Small USD decimal amount should convert to cents without float rounding.');
patwc_money_assert_same(10, Money::to_minor_units('10', 'JPY'), 'JPY amount should remain in whole minor units.');

patwc_money_assert_same(array(
    'total' => 1023,
    'subtotal' => 1023,
    'currency' => 'USD',
    'tip' => 0,
    'tax' => 0,
), Money::to_payarc_amount_object('10.23', 'USD'), 'PayArc amount payload must use the V3 object shape.');

patwc_money_assert_throws(static function (): void {
    Money::to_minor_units('-1.00', 'USD');
}, InvalidArgumentException::class, 'Negative totals should be rejected.');

patwc_money_assert_throws(static function (): void {
    Money::to_minor_units('10.23', 'AUD');
}, InvalidArgumentException::class, 'Unsupported currency precision should be rejected.');

patwc_money_assert_throws(static function (): void {
    Money::to_minor_units('10.23', 'JPY');
}, InvalidArgumentException::class, 'Fractional JPY amounts should be rejected.');

patwc_money_assert_throws(static function (): void {
    Money::to_minor_units('92233720368547758.08', 'USD');
}, InvalidArgumentException::class, 'Minor-unit amounts greater than PHP_INT_MAX should be rejected.');

patwc_money_assert_throws(static function (): void {
    Money::to_payarc_amount_object('92233720368547758.07', 'USD', 1, 0);
}, InvalidArgumentException::class, 'PayArc amount totals greater than PHP_INT_MAX should be rejected.');

$transactionId = PayArcIds::transaction_id(12345, '550e8400-e29b-41d4-a716-446655440000');
patwc_money_assert_same(1, preg_match('/^[A-Za-z0-9]{1,16}$/', $transactionId), 'Transaction IDs must be 1-16 alphanumeric characters.');
patwc_money_assert_same($transactionId, PayArcIds::transaction_id(12345, '550e8400-e29b-41d4-a716-446655440000'), 'Transaction IDs should be stable for the same order id and attempt UUID.');

$maxOrderTransactionA = PayArcIds::transaction_id(PHP_INT_MAX, '00000000-0000-4000-8000-000000000008');
$maxOrderTransactionB = PayArcIds::transaction_id(PHP_INT_MAX, '00000000-0000-4000-8000-000000000015');

patwc_money_assert_same(1, preg_match('/^P[A-Za-z0-9]{0,15}$/', $maxOrderTransactionA), 'PHP_INT_MAX transaction IDs must preserve the P prefix and 1-16 alphanumeric shape.');
patwc_money_assert_same(1, preg_match('/^P[A-Za-z0-9]{0,15}$/', $maxOrderTransactionB), 'PHP_INT_MAX transaction IDs must preserve the P prefix and 1-16 alphanumeric shape.');

if ($maxOrderTransactionA === $maxOrderTransactionB) {
    throw new RuntimeException('PHP_INT_MAX transaction IDs should remain distinct across different attempt UUIDs.');
}

$idempotencyKey = PayArcIds::idempotency_key();
patwc_money_assert_same(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idempotencyKey), 'Idempotency keys should be UUID v4 strings.');
