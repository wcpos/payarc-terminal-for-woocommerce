<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Server;

use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

/**
 * Registers the server adapter with WCPOS Pro when a compatible Pro is active. Pro defines its
 * helpers from its own plugins_loaded hook at priority 20, so this runs at 30.
 */
final class Registration
{
    /** First Pro release the adapter runs on: the shared payments base and its conformance suite. */
    public const REQUIRED_PRO_VERSION = '2.0.0';

    public static function pro_supported(): bool
    {
        return function_exists('wcpos_pro_register_server_provider')
            && function_exists('wcpos_pro_requires')
            && wcpos_pro_requires(self::REQUIRED_PRO_VERSION);
    }

    public static function register(): bool
    {
        if (!self::pro_supported()) {
            return false;
        }

        wcpos_pro_register_server_provider(Settings::GATEWAY_ID, PayArc_Server_Provider::class);
        Refund_Reask::register();

        return true;
    }
}
