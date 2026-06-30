<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

class Settings
{
    public const GATEWAY_ID = 'payarc_terminal_for_woocommerce';
    private const TEST_CONNECT_LOGIN_BASE_URL = 'https://testpayarcconnectapi.curvpos.com';
    private const TEST_CONNECT_BASE_URL = 'https://testpayarcconnectapi.payarc.net';
    private const TEST_MERCHANT_API_BASE_URL = 'https://testapi.payarc.net';

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

    public function connect_login_base_url(): string
    {
        $override = $this->string_setting('connect_login_base_url', '');
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return self::TEST_CONNECT_LOGIN_BASE_URL;
    }

    public function connect_base_url(): string
    {
        $override = $this->string_setting('connect_base_url', '');
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return self::TEST_CONNECT_BASE_URL;
    }

    public function merchant_api_base_url(): string
    {
        $override = $this->string_setting('merchant_api_base_url', '');
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return self::TEST_MERCHANT_API_BASE_URL;
    }

    public function connect_email(): string
    {
        return $this->string_setting('connect_email', '');
    }

    public function connect_mid(): string
    {
        return $this->string_setting('connect_mid', '');
    }

    public function connect_client_secret(): string
    {
        return $this->string_setting('connect_client_secret', '');
    }

    public function connect_secret_key(): string
    {
        $secret = $this->string_setting('connect_secret_key', '');

        return $secret !== '' ? $secret : $this->string_setting('api_bearer_token', '');
    }

    public function connect_access_token(): string
    {
        return $this->string_setting('connect_access_token', '');
    }

    public function connect_token_expires_at(): int
    {
        $value = $this->string_setting('connect_token_expires_at', '0');

        return ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * Backwards-compatible alias for older code/tests. New transaction calls should use connect_access_token().
     */
    public function api_bearer_token(): string
    {
        return $this->connect_secret_key();
    }

    public function callback_bearer_token(): string
    {
        return $this->string_setting('callback_bearer_token', '');
    }

    public function tenant_id(): string
    {
        $mid = preg_replace('/\D+/', '', $this->connect_mid());
        if (is_string($mid) && strlen($mid) >= 12) {
            return substr($mid, -12);
        }

        $manual = $this->string_setting('tenant_id', '');
        if (preg_match('/^[0-9]{12}$/', $manual) === 1) {
            return $manual;
        }

        return $manual;
    }

    public function default_terminal_id(): string
    {
        $manual = $this->string_setting('default_terminal_id', '');
        if (preg_match('/^[0-9]{10}$/', $manual) === 1) {
            return $manual;
        }

        foreach ($this->terminal_registry() as $terminal) {
            if (!empty($terminal['enabled']) && isset($terminal['terminal_id']) && preg_match('/^[0-9]{10}$/', (string) $terminal['terminal_id']) === 1) {
                return (string) $terminal['terminal_id'];
            }
        }

        return $manual;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function terminal_registry(): array
    {
        $registry = array_key_exists('terminal_registry', $this->settings) && is_array($this->settings['terminal_registry'])
            ? $this->settings['terminal_registry']
            : array();
        $normalized = array();

        foreach ($registry as $terminal) {
            if (!is_array($terminal)) {
                continue;
            }

            $terminalId = isset($terminal['terminal_id']) && is_scalar($terminal['terminal_id']) ? trim((string) $terminal['terminal_id']) : '';
            if (preg_match('/^[0-9]{10}$/', $terminalId) !== 1) {
                continue;
            }

            $label = isset($terminal['label']) && is_scalar($terminal['label']) ? trim((string) $terminal['label']) : '';
            if ($label === '') {
                $label = self::terminal_label(
                    isset($terminal['name']) && is_scalar($terminal['name']) ? (string) $terminal['name'] : 'PayArc terminal',
                    isset($terminal['type']) && is_scalar($terminal['type']) ? (string) $terminal['type'] : '',
                    $terminalId
                );
            }

            $normalized[] = array(
                'terminal_id' => $terminalId,
                'label' => $label,
                'enabled' => !array_key_exists('enabled', $terminal) || (bool) $terminal['enabled'],
                'name' => isset($terminal['name']) && is_scalar($terminal['name']) ? (string) $terminal['name'] : '',
                'type' => isset($terminal['type']) && is_scalar($terminal['type']) ? (string) $terminal['type'] : '',
                'device_id' => isset($terminal['device_id']) && is_scalar($terminal['device_id']) ? (string) $terminal['device_id'] : '',
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public function terminal_registry_options(): array
    {
        $options = array();

        foreach ($this->terminal_registry() as $terminal) {
            if (empty($terminal['enabled'])) {
                continue;
            }

            $terminalId = (string) $terminal['terminal_id'];
            $options[$terminalId] = (string) $terminal['label'];
        }

        return $options;
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
            'connect_login_base_url' => $this->connect_login_base_url(),
            'connect_base_url' => $this->connect_base_url(),
            'merchant_api_base_url' => $this->merchant_api_base_url(),
            'webhook_url' => $this->webhook_url(),
            'connect_email_configured' => $this->connect_email() !== '',
            'connect_mid_configured' => $this->connect_mid() !== '',
            'tenant_id_configured' => preg_match('/^[0-9]{12}$/', $this->tenant_id()) === 1,
            'default_terminal_id_configured' => preg_match('/^[0-9]{10}$/', $this->default_terminal_id()) === 1,
            'terminal_count' => count($this->terminal_registry_options()),
            'tender_type' => $this->tender_type(),
            'print_receipt' => $this->print_receipt(),
            'api_bearer_token_configured' => $this->connect_secret_key() !== '',
            'connect_secret_key_configured' => $this->connect_secret_key() !== '',
            'connect_access_token_configured' => $this->connect_access_token() !== '',
            'callback_bearer_token_configured' => $this->callback_bearer_token() !== '',
            'production_connect_base_url_verified' => false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings;
    }

    public static function terminal_label(string $name, string $type, string $terminal_id): string
    {
        $name = trim($name) !== '' ? trim($name) : 'PayArc terminal';
        $type = trim($type);
        $label = $type !== '' ? $name . ' (' . $type . ')' : $name;

        return $label . ' ' . self::mask_identifier($terminal_id);
    }

    public static function mask_identifier(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Not configured';
        }

        $lastFour = substr($value, -4);
        $maskLength = max(0, strlen($value) - 4);

        return str_repeat('•', $maskLength) . $lastFour;
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
