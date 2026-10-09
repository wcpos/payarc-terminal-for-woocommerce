<?php

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';

// Sales the old panel left mid-flight become WCPOS Pro's; the cases stub Free, Pro and WooCommerce.
patwc_run_isolated(__DIR__ . '/support/legacy-adoption-cases.php');
