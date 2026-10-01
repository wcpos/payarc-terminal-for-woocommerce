<?php

/**
 * Runs a regression case file in a fresh PHP process.
 *
 * tests/run.php loads every regression file into one process, so cases that
 * need their own wc_get_order(), $wpdb or WooCommerce class stubs live under
 * support/ and run here instead of colliding with the stubs earlier files
 * defined.
 */

declare(strict_types=1);

if (!function_exists('patwc_run_isolated')) {
    function patwc_run_isolated(string $caseFile): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($caseFile) . ' 2>&1', $output, $exitCode);
        foreach ($output as $line) {
            echo $line, "\n";
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(basename($caseFile) . ' failed in its own process (exit ' . $exitCode . ').');
        }
    }
}
