<?php
/**
 * Minimal regression test runner for PayArc Terminal for WooCommerce.
 */

declare(strict_types=1);

$testDir = __DIR__ . '/regression';
$tests = glob($testDir . '/*.php');

if ($tests === false || count($tests) === 0) {
    echo "No regression tests found.\n";
    exit(0);
}

sort($tests);

foreach ($tests as $test) {
    echo 'Running ' . basename($test) . "...\n";
    try {
        require $test;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Failed ' . basename($test) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

echo 'All regression tests passed (' . count($tests) . ").\n";
