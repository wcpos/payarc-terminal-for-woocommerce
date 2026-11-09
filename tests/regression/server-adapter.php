<?php

/**
 * Regression: the WCPOS Pro server adapter's own rules (roadmap#95): unanswered vs refused on a
 * sale, the not-found window after a sale, cancel semantics, a refund linked through the old
 * panel's attempt, an unanswered refund POST kept pending and re-asked under the same key, and
 * the callback's two forms of authentication.
 */

declare(strict_types=1);

require_once __DIR__ . '/support/isolated-process.php';
patwc_run_isolated(__DIR__ . '/support/server-adapter-cases.php');
