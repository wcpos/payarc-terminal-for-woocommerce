<?php

/**
 * Regression #23: PaymentLock must be an atomic per-order claim.
 * The cases set their own $wpdb, so they run in a separate process.
 */

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';
patwc_run_isolated(__DIR__ . '/support/payment-lock-cases.php');
