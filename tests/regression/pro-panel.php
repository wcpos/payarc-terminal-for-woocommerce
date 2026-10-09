<?php

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';

// The order-pay page under WCPOS Pro's panel, and the old paths yielding to Pro; own stubs, own process.
patwc_run_isolated(__DIR__ . '/support/pro-panel-cases.php');
