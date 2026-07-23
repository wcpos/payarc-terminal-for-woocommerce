<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;

/**
 * A PayArc V3 API failure response, carrying the machine-readable PayArc error
 * code so callers can react to specific failures (e.g. TRANSACTION_NOT_FOUND
 * during the window between sale acceptance and transaction visibility)
 * without parsing the human-readable message.
 */
class PayArcRequestException extends RuntimeException
{
    /** @var string */
    private $payarc_code;

    /** @var int */
    private $http_status;

    public function __construct(string $message, string $payarc_code = '', int $http_status = 0)
    {
        parent::__construct($message);
        $this->payarc_code = $payarc_code;
        $this->http_status = $http_status;
    }

    public function payarc_code(): string
    {
        return $this->payarc_code;
    }

    public function http_status(): int
    {
        return $this->http_status;
    }
}
