<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

class Settings
{
    public const GATEWAY_ID = 'payarc_terminal_for_woocommerce';
    private const TEST_CONNECT_BASE_URL = 'https://testpayarcconnectapi.payarc.net';

    /**
     * @var array<string, mixed>
     */
    private $settings;

    /**
     * @param array<string, mixed>|null $settings
     */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings === null ? $this->load_settings() : $settings;
    }

    public function mode(): string
    {
        $mode = $this->string_setting('mode', 'test');

        return $mode === 'production' ? 'production' : 'test';
    }

    public function connect_base_url(): string
    {
        if ($this->mode() === 'test') {
            return self::TEST_CONNECT_BASE_URL;
        }

        return '';
    }

    public function api_bearer_token(): string
    {
        return $this->string_setting('api_bearer_token', '');
    }

    public function callback_bearer_token(): string
    {
        return $this->string_setting('callback_bearer_token', '');
    }

    public function tenant_id(): string
    {
        return $this->string_setting('tenant_id', '');
    }

    public function default_terminal_id(): string
    {
        return $this->string_setting('default_terminal_id', '');
    }

    public function tender_type(): string
    {
        $tenderType = strtoupper($this->string_setting('tender_type', 'CREDIT'));

        return in_array($tenderType, array('CREDIT', 'DEBIT'), true) ? $tenderType : 'CREDIT';
    }

    public function print_receipt(): int
    {
        $printReceipt = $this->string_setting('print_receipt', '0');

        return in_array($printReceipt, array('0', '1', '2', '3'), true) ? (int) $printReceipt : 0;
    }

    public function webhook_url(): string
    {
        if (function_exists('admin_url')) {
            return admin_url('admin-ajax.php?action=patwc_payarc_callback');
        }

        return 'admin-ajax.php?action=patwc_payarc_callback';
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return array(
            'gateway_id' => self::GATEWAY_ID,
            'mode' => $this->mode(),
            'connect_base_url' => $this->connect_base_url(),
            'webhook_url' => $this->webhook_url(),
            'tenant_id_configured' => $this->tenant_id() !== '',
            'default_terminal_id_configured' => $this->default_terminal_id() !== '',
            'tender_type' => $this->tender_type(),
            'print_receipt' => $this->print_receipt(),
            'api_bearer_token_configured' => $this->api_bearer_token() !== '',
            'callback_bearer_token_configured' => $this->callback_bearer_token() !== '',
            'production_connect_base_url_verified' => false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function load_settings(): array
    {
        $option = 'woocommerce_' . self::GATEWAY_ID . '_settings';
        $settings = function_exists('get_option') ? get_option($option, array()) : array();

        return is_array($settings) ? $settings : array();
    }

    private function string_setting(string $key, string $default): string
    {
        if (!array_key_exists($key, $this->settings)) {
            return $default;
        }

        $value = $this->settings[$key];

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return $default;
    }
}
