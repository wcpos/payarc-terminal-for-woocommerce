<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

trait GatewayImplementation
{
    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = 'PayArc Terminal';
        $this->method_description = 'PayArc PAX terminal payments for WooCommerce POS.';
        $this->has_fields = false;
        $this->supports = array('products');

        if (function_exists('add_action')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        $this->init_form_fields();

        if (method_exists($this, 'init_settings')) {
            $this->init_settings();
        }

        $this->title = $this->gateway_option('title', 'PayArc Terminal');
        $this->description = $this->gateway_option('description', 'Pay in person at a PayArc PAX terminal.');
        $this->enabled = $this->gateway_option('enabled', 'no');
    }

    public function init_form_fields(): void
    {
        $settings = new Settings();

        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable PayArc Terminal',
                'default' => 'no',
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title shown at checkout.',
                'default' => 'PayArc Terminal',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description shown at checkout.',
                'default' => 'Pay in person at a PayArc PAX terminal.',
                'desc_tip' => true,
            ),
            'mode' => array(
                'title' => 'Mode',
                'type' => 'select',
                'description' => 'Production remains disabled until the production URL/token source is verified.',
                'default' => 'test',
                'options' => array(
                    'test' => 'Test',
                ),
            ),
            'api_bearer_token' => array(
                'title' => 'API bearer token',
                'type' => 'patwc_secret',
                'description' => 'PayArc Connect bearer or access token proven by Task 0. Used server-side only.',
                'default' => '',
            ),
            'callback_bearer_token' => array(
                'title' => 'Callback bearer token',
                'type' => 'patwc_secret',
                'description' => 'PayArc-provisioned secret expected in the callback Authorization header.',
                'default' => '',
            ),
            'tenant_id' => array(
                'title' => 'Tenant ID',
                'type' => 'text',
                'description' => 'Last 12 digits of the merchant identifier.',
                'default' => '',
            ),
            'default_terminal_id' => array(
                'title' => 'Default terminal ID',
                'type' => 'text',
                'description' => '10-digit PAX terminal serial/id.',
                'default' => '',
            ),
            'tender_type' => array(
                'title' => 'Tender type',
                'type' => 'select',
                'default' => 'CREDIT',
                'options' => array(
                    'CREDIT' => 'CREDIT',
                    'DEBIT' => 'DEBIT',
                ),
            ),
            'print_receipt' => array(
                'title' => 'Print receipt',
                'type' => 'select',
                'description' => 'PayArc receipt flag.',
                'default' => '0',
                'options' => array(
                    '0' => '0',
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                ),
            ),
            'webhook_url' => array(
                'title' => 'Webhook URL',
                'type' => 'text',
                'description' => 'Configure PayArc callbacks to send transaction updates to this URL.',
                'default' => $settings->webhook_url(),
                'custom_attributes' => array(
                    'readonly' => 'readonly',
                ),
            ),
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return string[]
     */
    public static function validate_settings(array $settings): array
    {
        $errors = array();
        $enabled = self::setting_string($settings, 'enabled') === 'yes';
        $mode = self::setting_string($settings, 'mode', 'test');
        $tenantId = self::setting_string($settings, 'tenant_id');
        $terminalId = self::setting_string($settings, 'default_terminal_id');
        $tenderType = strtoupper(self::setting_string($settings, 'tender_type', 'CREDIT'));
        $printReceipt = self::setting_string($settings, 'print_receipt', '0');

        if ($mode === 'production') {
            $errors[] = 'Production mode cannot be saved until the production URL/token source is verified.';
        }

        if ($enabled && preg_match('/^[0-9]{12}$/', $tenantId) !== 1) {
            $errors[] = 'Tenant ID must be exactly 12 digits when the gateway is enabled.';
        }

        if ($enabled && preg_match('/^[0-9]{10}$/', $terminalId) !== 1) {
            $errors[] = 'Default terminal ID must be exactly 10 digits when the gateway is enabled.';
        }

        if (!in_array($tenderType, array('CREDIT', 'DEBIT'), true)) {
            $errors[] = 'Tender type must be CREDIT or DEBIT.';
        }

        if (!in_array($printReceipt, array('0', '1', '2', '3'), true)) {
            $errors[] = 'Print receipt must be one of 0, 1, 2, or 3.';
        }

        return $errors;
    }

    public function process_admin_options()
    {
        $postedSettings = $this->posted_settings();
        $errors = self::validate_settings($postedSettings);

        foreach ($errors as $error) {
            $this->add_admin_error($error);
        }

        if (count($errors) > 0) {
            return false;
        }

        return $this->process_valid_admin_options();
    }

    /**
     * @return array<string, string>
     */
    private function posted_settings(): array
    {
        $data = method_exists($this, 'get_post_data') ? $this->get_post_data() : $_POST;
        $settings = array();
        $prefix = 'woocommerce_' . Settings::GATEWAY_ID . '_';

        foreach ($this->form_fields as $key => $field) {
            $postedKey = $prefix . $key;
            $value = array_key_exists($postedKey, $data) ? $data[$postedKey] : (array_key_exists($key, $data) ? $data[$key] : null);

            if ($key === 'enabled' && $value === null) {
                $value = 'no';
            }

            if (is_scalar($value)) {
                $settings[$key] = trim((string) $value);
            }
        }

        return $settings;
    }

    private function gateway_option(string $key, string $default): string
    {
        if (method_exists($this, 'get_option')) {
            $value = $this->get_option($key, $default);
            return is_scalar($value) ? (string) $value : $default;
        }

        $settings = new Settings();
        $diagnostics = $settings->diagnostics();

        if (array_key_exists($key, $diagnostics) && is_scalar($diagnostics[$key])) {
            return (string) $diagnostics[$key];
        }

        return $default;
    }

    /**
     * Generate a secret setting field without rendering the saved value into admin HTML.
     *
     * @param array<string, mixed> $data
     */
    public function generate_patwc_secret_html(string $key, array $data): string
    {
        $fieldKey = method_exists($this, 'get_field_key') ? $this->get_field_key($key) : 'woocommerce_' . Settings::GATEWAY_ID . '_' . $key;
        $title = $this->field_text($data, 'title', $key);
        $description = $this->field_text($data, 'description', '');
        $configured = $this->gateway_option($key, '') !== '';
        $placeholder = $configured ? 'Configured - enter a new value to replace' : 'Not configured';
        $status = $configured ? 'Configured' : 'Empty';

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc"><label for="' . $this->escape_attr($fieldKey) . '">' . $this->escape_html($title) . '</label></th>';
        $html .= '<td class="forminp"><input class="input-text regular-input" type="password" autocomplete="new-password" id="' . $this->escape_attr($fieldKey) . '" name="' . $this->escape_attr($fieldKey) . '" value="" placeholder="' . $this->escape_attr($placeholder) . '" />';
        $html .= '<p class="description">' . $this->escape_html($status . '. Leave blank to keep the existing value. ' . $description) . '</p>';
        $html .= '</td></tr>';

        return $html;
    }

    public function validate_patwc_secret_field(string $key, $value): string
    {
        $existing = $this->gateway_option($key, '');

        if (!is_scalar($value)) {
            return $existing;
        }

        $submitted = (string) $value;

        if (trim($submitted) === '') {
            return $existing;
        }

        if (preg_match('/[[:cntrl:]]/', $submitted) === 1) {
            $this->add_admin_error($this->secret_field_label($key) . ' cannot contain control characters or newlines.');
            return $existing;
        }

        return trim($submitted);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function field_text(array $data, string $key, string $default): string
    {
        if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
            return $default;
        }

        return (string) $data[$key];
    }

    private function secret_field_label(string $key): string
    {
        if ($key === 'api_bearer_token') {
            return 'API bearer token';
        }

        if ($key === 'callback_bearer_token') {
            return 'Callback bearer token';
        }

        return 'Secret field';
    }

    private function escape_attr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escape_html(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function setting_string(array $settings, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $settings) || !is_scalar($settings[$key])) {
            return $default;
        }

        return trim((string) $settings[$key]);
    }

    private function process_valid_admin_options()
    {
        return true;
    }

    private function add_admin_error(string $error): void
    {
        if (class_exists('WC_Admin_Settings') && method_exists('WC_Admin_Settings', 'add_error')) {
            \WC_Admin_Settings::add_error($error);
            return;
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice($error, 'error');
        }
    }
}

if (class_exists('WC_Payment_Gateway')) {
    class Gateway extends \WC_Payment_Gateway
    {
        use GatewayImplementation {
            process_valid_admin_options as private fallback_process_valid_admin_options;
        }

        private function process_valid_admin_options()
        {
            return parent::process_admin_options();
        }
    }
}
