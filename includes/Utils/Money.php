<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Utils;

use InvalidArgumentException;

class Money
{
    /**
     * @var array<string, int>
     */
    private const CURRENCY_PRECISION = array(
        'USD' => 2,
        'CAD' => 2,
        'GBP' => 2,
        'EUR' => 2,
        'JPY' => 0,
    );

    /**
     * @param mixed $amount
     */
    public static function to_minor_units($amount, string $currency): int
    {
        $currency = strtoupper(trim($currency));

        if (!array_key_exists($currency, self::CURRENCY_PRECISION)) {
            throw new InvalidArgumentException('Unsupported currency precision: ' . $currency);
        }

        $precision = self::CURRENCY_PRECISION[$currency];
        $normalized = self::normalize_amount($amount);

        if ($normalized[0] === '-') {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        $parts = explode('.', $normalized, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        if ($precision === 0) {
            if ($fraction !== '') {
                throw new InvalidArgumentException('Currency does not support fractional amounts: ' . $currency);
            }

            return (int) $whole;
        }

        if (strlen($fraction) > $precision) {
            throw new InvalidArgumentException('Amount has too many decimal places for currency: ' . $currency);
        }

        $fraction = str_pad($fraction, $precision, '0');

        return (int) ($whole . $fraction);
    }

    /**
     * @param mixed $amount
     * @return array<string, int|string>
     */
    public static function to_payarc_amount_object($amount, string $currency, int $tip = 0, int $tax = 0): array
    {
        if ($tip < 0 || $tax < 0) {
            throw new InvalidArgumentException('Tip and tax must not be negative.');
        }

        $currency = strtoupper(trim($currency));
        $subtotal = self::to_minor_units($amount, $currency);
        $total = $subtotal + $tip + $tax;

        if ($total < 0) {
            throw new InvalidArgumentException('Total must not be negative.');
        }

        return array(
            'total' => $total,
            'subtotal' => $subtotal,
            'currency' => $currency,
            'tip' => $tip,
            'tax' => $tax,
        );
    }

    /**
     * @param mixed $amount
     */
    private static function normalize_amount($amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new InvalidArgumentException('Amount must be finite.');
            }

            $amount = rtrim(rtrim(sprintf('%.10F', $amount), '0'), '.');
        }

        if (!is_string($amount)) {
            throw new InvalidArgumentException('Amount must be a numeric string, integer, or finite float.');
        }

        $amount = trim($amount);

        if ($amount === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException('Invalid amount string.');
        }

        return $amount;
    }
}
