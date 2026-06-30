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

        if (preg_match('/^[0-9]{12}$/', $tenantId) !== 1) {
            throw new InvalidArgumentException('PayArc tenant id must be exactly 12 digits. Connect PayArc with the merchant MID first.');
        }

        if (preg_match('/^[0-9]{10}$/', $terminalId) !== 1) {
            throw new InvalidArgumentException('No PayArc terminal has been discovered. Press Connect PayArc in the gateway settings and select a terminal.');
        }

        $options = $this->settings->terminal_registry_options();
        if (count($options) > 0 && !array_key_exists($terminalId, $options)) {
            throw new InvalidArgumentException('Selected PayArc terminal was not found in the discovered terminal registry. Refresh terminals in the gateway settings.');
        }

        return array('tenantId' => $tenantId, 'terminalId' => $terminalId);
    }
}
