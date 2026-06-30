<?php

/**
 * Guard against shipping mock-only setup copy in merchant-test-ready builds.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$readme = file_get_contents($root . '/README.md');
if (!is_string($readme)) {
    throw new RuntimeException('Unable to read README.md.');
}

foreach (array('MOCK_CONTRACT_CREATED', 'local checks only', 'does not call PayArc') as $forbidden) {
    if (stripos($readme, $forbidden) !== false) {
        throw new RuntimeException('README still contains mock/local-only setup language: ' . $forbidden);
    }
}

foreach (array('Press Connect', 'PayArc Login', 'terminal discovery', 'low-value test payment') as $required) {
    if (stripos($readme, $required) === false) {
        throw new RuntimeException('README missing merchant-test-ready setup language: ' . $required);
    }
}


$adminJs = file_get_contents($root . '/assets/js/admin.js');
if (!is_string($adminJs)) {
    throw new RuntimeException('Unable to read admin.js.');
}

$connectOnlyNeedle = "if (action === 'patwc_connect_payarc')";
if (strpos($adminJs, $connectOnlyNeedle) === false) {
    throw new RuntimeException('admin.js should only append PayArc credential fields for Connect PayArc requests.');
}

foreach (array('patwc_refresh_payarc_terminals', 'patwc_disconnect_payarc') as $action) {
    $actionPosition = strpos($adminJs, $action);
    $secretLoopPosition = strpos($adminJs, "connect_client_secret', 'connect_secret_key", $actionPosition === false ? 0 : $actionPosition);
    if ($actionPosition !== false && $secretLoopPosition !== false && $secretLoopPosition < strpos($adminJs, "if (action === 'patwc_connect_payarc')")) {
        throw new RuntimeException('Non-connect admin actions must not post PayArc credential fields.');
    }
}
