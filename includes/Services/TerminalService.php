<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use InvalidArgumentException;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

class TerminalService
{
    /** @var Settings */
    private $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
    }

    /**
     * @return array<string, string>
     */
    public function validate_default_terminal(): array
    {
        return $this->validate_terminal($this->settings->default_terminal_id());
    }

    /**
     * @return array<string, string>
     */
    public function validate_terminal(string $terminal_id): array
    {
        $tenantId = $this->settings->tenant_id();
        $terminalId = trim($terminal_id) !== '' ? trim($terminal_id) : $this->settings->default_terminal_id();

        if ($tenantId === '') {
            throw new InvalidArgumentException('PayArc tenant id is missing. Connect PayArc with the merchant MID first.');
        }

        if ($terminalId === '') {
            throw new InvalidArgumentException('No PayArc terminal serial number is configured. Enter the terminal serial number in the gateway settings.');
        }

        return array('tenantId' => $tenantId, 'terminalId' => $terminalId);
    }
}
