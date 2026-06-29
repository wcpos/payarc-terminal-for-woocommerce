<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Utils;

use InvalidArgumentException;

class PayArcIds
{
    private const MIN_HASH_SUFFIX_LENGTH = 8;

    public static function transaction_id(int $order_id, string $attempt_uuid): string
    {
        if ($order_id < 0) {
            throw new InvalidArgumentException('Order id must not be negative.');
        }

        $orderPart = strtoupper(base_convert((string) $order_id, 10, 36));
        $hash = strtoupper(sha1((string) $order_id . '|' . $attempt_uuid));
        $orderLength = max(0, 16 - 1 - self::MIN_HASH_SUFFIX_LENGTH);
        $prefix = 'P' . substr($orderPart, 0, $orderLength);
        $suffixLength = 16 - strlen($prefix);

        return $prefix . substr($hash, 0, $suffixLength);
    }

    public static function idempotency_key(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return strtolower((string) wp_generate_uuid4());
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
