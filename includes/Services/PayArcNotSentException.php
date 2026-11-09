<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;

/**
 * A request the client refused to send: missing credentials or base URL, a Connect state that does
 * not match the selected mode, a body that could not be encoded. Nothing reached PayArc, so the
 * caller may treat it as a plain refusal rather than an unknown outcome.
 */
class PayArcNotSentException extends RuntimeException
{
}
