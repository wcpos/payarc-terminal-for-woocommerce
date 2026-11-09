<?php

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';

// The gateway is POS-only: the cases stub the request helpers, so they run in their own process.
patwc_run_isolated(__DIR__ . '/support/gateway-availability-cases.php');
