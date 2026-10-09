<?php

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';

// Without a compatible WCPOS Pro the plugin registers a notice and nothing else; with one, everything.
// Each boot needs its own WordPress hook surface, so the cases run in their own processes.
patwc_run_isolated(__DIR__ . '/support/bootstrap-gate-no-pro-cases.php');
patwc_run_isolated(__DIR__ . '/support/bootstrap-gate-pro-cases.php');
