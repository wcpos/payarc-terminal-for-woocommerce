<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

/**
 * Registers the server adapter with WCPOS Pro. Called from the plugin's gate (patwc_init(), plugins_loaded
 * at 30), which has already asked wcpos_pro_requires() for REQUIRED_PRO_VERSION; Pro defines its helpers
 * from its own plugins_loaded hook at priority 20.
 */
final class Registration
{
    /** First Pro release the plugin runs on: the shared payments base and its conformance suite. */
    public const REQUIRED_PRO_VERSION = '2.0.0';

    public static function register(): bool
    {
        if (!function_exists('wcpos_pro_register_server_provider')) {
            return false;
        }

        wcpos_pro_register_server_provider(Settings::GATEWAY_ID, PayArc_Server_Provider::class);
        Refund_Reask::register();

        return true;
    }
}
