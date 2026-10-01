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

require_once __DIR__ . '/support/isolated-process.php';
patwc_run_isolated(__DIR__ . '/support/payment-complete-race-cases.php');
