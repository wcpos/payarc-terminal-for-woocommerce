<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal;

trait GatewayImplementation
{
    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = 'PayArc Terminal';
        $this->method_description = 'PayArc PAX terminal payments for WooCommerce POS.';
        $this->has_fields = true;
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
        $terminalOptions = $settings->terminal_registry_options();
        if (count($terminalOptions) === 0) {
            $terminalOptions = array('' => 'Connect PayArc to discover terminals');
        }

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
                'description' => 'Use Test for PayArc Connect Test merchants and terminals. Production endpoints remain disabled until PayArc production Connect URLs are verified.',
                'default' => 'test',
                'options' => array(
                    'test' => 'Test',
                ),
            ),
            'connect_email' => array(
                'title' => 'PayArc login email',
                'type' => 'text',
                'description' => 'Required before connecting. Enter the email used to sign in to the PayArc merchant dashboard; the plugin cannot fetch this for you.',
                'default' => '',
            ),
            'connect_mid' => array(
                'title' => 'PayArc MID',
                'type' => 'text',
                'description' => 'Required before connecting. This is the merchant MID from the PayArc Merchant Profile, not the terminal ID. The plugin derives the Connect tenant ID from the last 12 digits.',
                'default' => '',
            ),
            'connect_client_secret' => array(
                'title' => 'PayArc ClientSecret',
                'type' => 'patwc_secret',
                'description' => 'Required before connecting. Enter the ClientSecret from the PayArc dashboard API section. Stored server-side only.',
                'default' => '',
            ),
            'connect_secret_key' => array(
                'title' => 'PayArc SecretKey / API bearer token',
                'type' => 'patwc_secret',
                'description' => 'Required before connecting. Enter the SecretKey/API bearer token from the PayArc dashboard. Used to call PayArc Login and terminal registry; terminal transactions use the Login AccessToken.',
                'default' => '',
            ),
            'callback_bearer_token' => array(
                'title' => 'Callback bearer token',
                'type' => 'patwc_secret',
                'description' => 'PayArc-provided callback secret expected in the callback Authorization header.',
                'default' => '',
            ),
            'connection' => array(
                'title' => 'PayArc connection',
                'type' => 'patwc_connection',
                'description' => 'After entering the PayArc credentials above, connect to PayArc Login to fetch and store the Connect AccessToken and discover terminals.',
                'default' => '',
            ),
            'default_terminal_id' => array(
                'title' => 'Default terminal',
                'type' => 'select',
                'description' => 'Discovered PayArc terminal used for payments. Use Refresh Terminals if the merchant adds or renames terminals in PayArc.',
                'default' => $settings->default_terminal_id(),
                'options' => $terminalOptions,
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
                'description' => 'PayArc receipt flag: 0 none, 1 merchant, 2 customer, 3 both.',
                'default' => '0',
                'options' => array(
                    '0' => '0 - No receipt',
                    '1' => '1 - Merchant receipt',
                    '2' => '2 - Customer receipt',
                    '3' => '3 - Both receipts',
                ),
            ),
            'webhook_url' => array(
                'title' => 'Webhook URL',
                'type' => 'text',
                'description' => 'Give this HTTPS callback URL to PayArc if they need to configure callbacks for the merchant account.',
                'default' => $settings->webhook_url(),
                'custom_attributes' => array(
                    'readonly' => 'readonly',
                ),
            ),
            'diagnostics' => array(
                'title' => 'PayArc diagnostics',
                'type' => 'patwc_diagnostics',
                'description' => 'Safe diagnostics for connection state, callback URL, and terminal discovery. Secrets are never displayed.',
                'default' => '',
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
        $tenantId = self::tenant_id_from_settings($settings);
        $terminalId = self::setting_string($settings, 'default_terminal_id');
        $tenderType = strtoupper(self::setting_string($settings, 'tender_type', 'CREDIT'));
        $printReceipt = self::setting_string($settings, 'print_receipt', '0');

        if ($mode === 'production') {
            $errors[] = 'Production mode cannot be saved until PayArc production Connect URLs are verified.';
        }

        if ($enabled && preg_match('/^[0-9]{12}$/', $tenantId) !== 1) {
            $errors[] = 'PayArc MID must contain at least 12 digits so the tenant ID can be derived when the gateway is enabled.';
        }

        if ($enabled && preg_match('/^[0-9]{10}$/', $terminalId) !== 1) {
            $errors[] = 'Select a discovered PayArc terminal before enabling the gateway.';
        }

        if (!in_array($tenderType, array('CREDIT', 'DEBIT'), true)) {
            $errors[] = 'Tender type must be CREDIT or DEBIT.';
        }

        if (!in_array($printReceipt, array('0', '1', '2', '3'), true)) {
            $errors[] = 'Print receipt must be one of 0, 1, 2, or 3.';
        }

        return $errors;
    }


    /**
     * Performs safe settings diagnostics for the admin validation control.
     *
     * The return payload intentionally contains only generic check names/messages and never echoes
     * submitted secrets or raw tenant/terminal values.
     *
     * @param array<string, mixed> $settings
     * @return array{status:string, errors:string[], checks:array<int, array{key:string, status:string, message:string}>}
     */
    public static function local_settings_diagnostics(array $settings): array
    {
        $checks = array();
        $tenantId = self::tenant_id_from_settings($settings);

        self::append_local_check(
            $checks,
            'connect_secret_key',
            self::setting_configured($settings, 'connect_secret_key_configured', 'connect_secret_key') || self::setting_configured($settings, 'api_bearer_token_configured', 'api_bearer_token'),
            'PayArc SecretKey/API bearer token is configured.',
            'PayArc SecretKey/API bearer token must be configured.'
        );

        self::append_local_check(
            $checks,
            'connect_access_token',
            self::setting_configured($settings, 'connect_access_token_configured', 'connect_access_token'),
            'PayArc Connect AccessToken is configured.',
            'Click Connect using these credentials to fetch a Connect AccessToken.'
        );

        self::append_local_check(
            $checks,
            'callback_bearer_token',
            self::setting_configured($settings, 'callback_bearer_token_configured', 'callback_bearer_token'),
            'Callback bearer token is configured.',
            'Callback bearer token must be configured.'
        );

        self::append_local_check(
            $checks,
            'tenant_id',
            preg_match('/^[0-9]{12}$/', $tenantId) === 1,
            'Tenant ID can be derived from the PayArc MID.',
            'PayArc MID must contain at least 12 digits.'
        );

        self::append_local_check(
            $checks,
            'default_terminal_id',
            preg_match('/^[0-9]{10}$/', self::setting_string($settings, 'default_terminal_id')) === 1,
            'Default terminal is selected.',
            'Connect PayArc and select a discovered terminal.'
        );

        self::append_local_check(
            $checks,
            'webhook_url',
            stripos(self::setting_string($settings, 'webhook_url'), 'https://') === 0,
            'Callback URL is HTTPS.',
            'Callback URL must be HTTPS.'
        );

        self::append_local_check(
            $checks,
            'print_receipt',
            in_array(self::setting_string($settings, 'print_receipt', '0'), array('0', '1', '2', '3'), true),
            'Print receipt value is valid.',
            'Print receipt must be one of 0, 1, 2, or 3.'
        );

        self::append_local_check(
            $checks,
            'tender_type',
            in_array(strtoupper(self::setting_string($settings, 'tender_type', 'CREDIT')), array('CREDIT', 'DEBIT'), true),
            'Tender type is valid.',
            'Tender type must be CREDIT or DEBIT.'
        );

        $errors = array();
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $errors[] = $check['message'];
            }
        }

        return array(
            'status' => count($errors) === 0 ? 'ok' : 'error',
            'errors' => $errors,
            'checks' => $checks,
        );
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

        $connectionState = $this->saved_connection_state();
        $result = $this->process_valid_admin_options();

        if ($result !== false) {
            $this->preserve_connection_state($connectionState);
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function process_payment($order_id): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

        if (!is_object($order)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice('Unable to find the order for this payment.', 'error');
            }

            return array('result' => 'failure');
        }

        if (method_exists($order, 'is_paid') && $order->is_paid()) {
            return array(
                'result' => 'success',
                'redirect' => $this->order_return_url($order),
            );
        }

        if (method_exists($order, 'get_checkout_payment_url')) {
            return array(
                'result' => 'success',
                'redirect' => (string) $order->get_checkout_payment_url(true),
            );
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice('Unable to prepare the payment page for this order.', 'error');
        }

        return array('result' => 'failure');
    }

    public function payment_fields(): void
    {
        $order = $this->current_payment_order();
        $orderId = $order !== null ? $this->order_id($order) : 0;
        $authorized = $order !== null && $this->viewer_can_access_order($order);

        if ($order !== null) {
            $this->enqueue_payment_assets($order, $authorized);
        }

        echo '<div id="patwc-payment-panel" class="patwc-payment-panel" data-patwc-order-id="' . $this->escape_attr((string) $orderId) . '">';
        echo '<div class="patwc-payment-panel__header">';
        echo '<strong>' . $this->escape_html($this->title !== '' ? $this->title : 'PayArc Terminal') . '</strong>';
        echo '<span class="patwc-payment-panel__order">' . $this->escape_html('Order #' . (string) $orderId) . '</span>';
        echo '</div>';
        echo '<p class="patwc-payment-panel__description">' . $this->escape_html('Start the in-person terminal payment, then wait for the terminal result before completing the order.') . '</p>';
        echo '<div id="patwc-payment-status" class="patwc-payment-status" role="status" aria-live="polite">' . $this->escape_html('Ready to start payment.') . '</div>';
        echo '<div class="patwc-payment-actions">';
        echo '<button type="button" id="patwc-start-payment" class="button alt patwc-start-payment"' . (!$authorized ? ' disabled="disabled"' : '') . '>' . $this->escape_html('Start Payment') . '</button>';
        echo '<button type="button" id="patwc-cancel-payment" class="button patwc-cancel-payment" hidden="hidden">' . $this->escape_html('Cancel Payment') . '</button>';
        echo '</div>';
        echo '<div id="patwc-payment-log" class="patwc-payment-log" aria-live="polite" aria-label="' . $this->escape_attr('Payment log') . '"></div>';
        echo '</div>';
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
        $configured = $this->secret_field_configured($key);
        $placeholder = $configured ? 'Configured - enter a new value to replace' : 'Not configured';
        $status = $configured ? 'Configured' : 'Empty';

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc"><label for="' . $this->escape_attr($fieldKey) . '">' . $this->escape_html($title) . '</label></th>';
        $html .= '<td class="forminp"><input class="input-text regular-input" type="password" autocomplete="new-password" id="' . $this->escape_attr($fieldKey) . '" name="' . $this->escape_attr($fieldKey) . '" value="" placeholder="' . $this->escape_attr($placeholder) . '" />';
        $html .= '<p class="description">' . $this->escape_html($status . '. Leave blank to keep the existing value. ' . $description) . '</p>';
        $html .= '</td></tr>';

        return $html;
    }

    /**
     * Render the local diagnostics table and Validate Settings control.
     *
     * @param array<string, mixed> $data
     */
    public function generate_patwc_diagnostics_html(string $key, array $data): string
    {
        $fieldKey = method_exists($this, 'get_field_key') ? $this->get_field_key($key) : 'woocommerce_' . Settings::GATEWAY_ID . '_' . $key;
        $title = $this->field_text($data, 'title', 'PayArc diagnostics');
        $description = $this->field_text($data, 'description', 'Safe diagnostics. Secrets are never displayed.');
        $buttonId = $fieldKey . '_validate';
        $resultId = $fieldKey . '_result';
        $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : 'admin-ajax.php';
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('patwc_validate_settings') : '';
        $savedState = array(
            'connect_secret_key_configured' => $this->gateway_option('connect_secret_key', '') !== '' || $this->gateway_option('api_bearer_token', '') !== '',
            'connect_access_token_configured' => $this->gateway_option('connect_access_token', '') !== '',
            'callback_bearer_token_configured' => $this->gateway_option('callback_bearer_token', '') !== '',
        );

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">' . $this->escape_html($title) . '</th>';
        $html .= '<td class="forminp">';
        $html .= '<p class="description">' . $this->escape_html($description) . '</p>';
        $html .= '<table class="widefat striped patwc-diagnostics" aria-label="' . $this->escape_attr('PayArc diagnostics') . '"><tbody>';

        foreach ($this->diagnostic_rows() as $label => $value) {
            $html .= '<tr><th scope="row">' . $this->escape_html($label) . '</th><td>' . $this->escape_html($value) . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<p><button type="button" class="button patwc-validate-settings" id="' . $this->escape_attr($buttonId) . '" data-action="patwc_validate_settings" data-ajax-url="' . $this->escape_attr($ajaxUrl) . '" data-nonce="' . $this->escape_attr($nonce) . '">' . $this->escape_html('Validate Settings') . '</button></p>';
        $html .= '<div id="' . $this->escape_attr($resultId) . '" class="patwc-validate-settings-result" role="status" aria-live="polite"></div>';
        $html .= $this->validate_settings_script($buttonId, $resultId, $savedState);
        $html .= '</td></tr>';

        return $html;
    }


    /**
     * Render the PayArc Connect controls.
     *
     * @param array<string, mixed> $data
     */
    public function generate_patwc_connection_html(string $key, array $data): string
    {
        $fieldKey = method_exists($this, 'get_field_key') ? $this->get_field_key($key) : 'woocommerce_' . Settings::GATEWAY_ID . '_' . $key;
        $title = $this->field_text($data, 'title', 'PayArc connection');
        $description = $this->field_text($data, 'description', 'After entering the PayArc credentials above, connect to PayArc Login to fetch and store the Connect AccessToken and discover terminals.');
        $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : 'admin-ajax.php';
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('patwc_payarc_connection') : '';
        $resultId = $fieldKey . '_result';

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">' . $this->escape_html($title) . '</th>';
        $html .= '<td class="forminp patwc-connection-panel">';
        $html .= '<p class="description">' . $this->escape_html($description) . '</p>';
        $html .= '<div class="patwc-connection-explainer">';
        $html .= '<p><strong>' . $this->escape_html('What Connect does:') . '</strong> ' . $this->escape_html('This does not fetch your PayArc credentials. It uses the fields above to sign in to PayArc Login, fetch and store the Connect AccessToken, derive the tenant ID from the MID, and discover terminals.') . '</p>';
        $html .= '<ol class="patwc-connection-steps">';
        $html .= '<li>' . $this->escape_html('Enter PayArc login email, merchant MID, ClientSecret, and SecretKey/API bearer token.') . '</li>';
        $html .= '<li>' . $this->escape_html('Click Connect using these credentials.') . '</li>';
        $html .= '<li>' . $this->escape_html('Select a discovered terminal below, then Save changes.') . '</li>';
        $html .= '</ol>';
        $html .= '<p class="description">' . $this->escape_html('MID means merchant ID, not terminal ID. If PayArc rejects the connection, check WooCommerce > Status > Logs and select the payarc-terminal-for-woocommerce source.') . '</p>';
        $html .= '</div>';
        $html .= '<p class="patwc-connection-actions">';
        $html .= '<button type="button" class="button button-primary patwc-connect-payarc" data-action="patwc_connect_payarc" data-ajax-url="' . $this->escape_attr($ajaxUrl) . '" data-nonce="' . $this->escape_attr($nonce) . '" data-result-id="' . $this->escape_attr($resultId) . '">' . $this->escape_html('Connect using these credentials') . '</button> ';
        $html .= '<button type="button" class="button patwc-refresh-payarc-terminals" data-action="patwc_refresh_payarc_terminals" data-ajax-url="' . $this->escape_attr($ajaxUrl) . '" data-nonce="' . $this->escape_attr($nonce) . '" data-result-id="' . $this->escape_attr($resultId) . '">' . $this->escape_html('Refresh Terminals') . '</button> ';
        $html .= '<button type="button" class="button patwc-disconnect-payarc" data-action="patwc_disconnect_payarc" data-ajax-url="' . $this->escape_attr($ajaxUrl) . '" data-nonce="' . $this->escape_attr($nonce) . '" data-result-id="' . $this->escape_attr($resultId) . '">' . $this->escape_html('Disconnect') . '</button>';
        $html .= '</p>';
        $html .= '<div id="' . $this->escape_attr($resultId) . '" class="patwc-connection-result" role="status" aria-live="polite"></div>';
        $html .= '</td></tr>';

        return $html;
    }

    public function validate_patwc_secret_field(string $key, $value): string
    {
        $existing = $this->gateway_option($key, '');
        if ($existing === '' && $key === 'connect_secret_key') {
            $existing = $this->gateway_option('api_bearer_token', '');
        }

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

    private function secret_field_configured(string $key): bool
    {
        if ($this->gateway_option($key, '') !== '') {
            return true;
        }

        if ($key === 'connect_secret_key' && $this->gateway_option('api_bearer_token', '') !== '') {
            return true;
        }

        return false;
    }

    private function secret_field_label(string $key): string
    {
        if ($key === 'api_bearer_token' || $key === 'connect_secret_key') {
            return 'PayArc SecretKey/API bearer token';
        }

        if ($key === 'connect_client_secret') {
            return 'PayArc ClientSecret';
        }

        if ($key === 'callback_bearer_token') {
            return 'Callback bearer token';
        }

        return 'Secret field';
    }

    /**
     * @return array<string, string>
     */
    private function diagnostic_rows(): array
    {
        $settings = new Settings();
        $lastError = $this->last_payarc_error();

        return array(
            'Mode' => $settings->mode(),
            'Connect Login URL' => $settings->connect_login_base_url(),
            'Connect V3 URL' => $settings->connect_base_url(),
            'Merchant API URL' => $settings->merchant_api_base_url(),
            'PayArc login email' => $settings->connect_email() !== '' ? 'Configured' : 'Not configured',
            'PayArc MID' => self::mask_identifier($settings->connect_mid()),
            'Tenant ID' => self::mask_identifier($settings->tenant_id()),
            'Discovered terminals' => (string) count($settings->terminal_registry_options()),
            'Default terminal ID' => self::mask_identifier($settings->default_terminal_id()),
            'Connect AccessToken' => $settings->connect_access_token() !== '' ? 'Configured' : 'Not configured',
            'Webhook URL' => $settings->webhook_url(),
            'Last callback timestamp' => self::diagnostic_text($this->diagnostic_option('patwc_last_callback_timestamp', 'None recorded')),
            'Last PayArc error code' => $lastError['code'],
            'Last PayArc error message' => $lastError['message'],
        );
    }


    /**
     * @return array{code:string, message:string}
     */
    private function last_payarc_error(): array
    {
        $error = $this->diagnostic_option('patwc_last_payarc_error', array());

        if (is_array($error)) {
            $code = array_key_exists('code', $error) ? self::diagnostic_text($error['code']) : '';
            $message = array_key_exists('message', $error) ? self::diagnostic_text($error['message']) : '';

            return array(
                'code' => $code !== '' ? $code : 'None recorded',
                'message' => $message !== '' ? $message : 'None recorded',
            );
        }

        $code = self::diagnostic_text($this->diagnostic_option('patwc_last_payarc_error_code', ''));
        $message = self::diagnostic_text($this->diagnostic_option('patwc_last_payarc_error_message', $error));

        return array(
            'code' => $code !== '' ? $code : 'None recorded',
            'message' => $message !== '' ? $message : 'None recorded',
        );
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function diagnostic_option(string $key, $default)
    {
        if (function_exists('get_option')) {
            return get_option($key, $default);
        }

        return $default;
    }

    private function validate_settings_script(string $buttonId, string $resultId, array $savedState): string
    {
        $fieldPrefix = 'woocommerce_' . Settings::GATEWAY_ID . '_';
        $payload = array(
            'buttonId' => $buttonId,
            'resultId' => $resultId,
            'fieldPrefix' => $fieldPrefix,
            'savedState' => $savedState,
        );
        $encoded = $this->json_encode($payload);

        return '<script>(function(config){' .
            'if(!config){return;}' .
            'var button=document.getElementById(config.buttonId);' .
            'var result=document.getElementById(config.resultId);' .
            'if(!button||!result){return;}' .
            'function fieldValue(key){var el=document.getElementById(config.fieldPrefix+key);return el&&typeof el.value==="string"?el.value.trim():"";}' .
            'function configured(key,flag){return fieldValue(key)!==""||!!config.savedState[flag];}' .
            'function add(errors,condition,message){if(!condition){errors.push(message);}}' .
            'function render(errors){if(errors.length===0){result.textContent="Settings validation passed.";return;}var html="<strong>Settings validation found issues:</strong><ul>";for(var i=0;i<errors.length;i++){html+="<li>"+errors[i].replace(/[&<>]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;"}[c];})+"</li>";}result.innerHTML=html+"</ul>";}' .
            'function localValidate(diagnostics){diagnostics=diagnostics||{};var errors=[];var mid=fieldValue("connect_mid").replace(/\D/g,"");var terminal=fieldValue("default_terminal_id");var secretConfigured=configured("connect_secret_key","connect_secret_key_configured")||!!diagnostics.connect_secret_key_configured;var accessConfigured=!!diagnostics.connect_access_token_configured||!!config.savedState.connect_access_token_configured;var callbackConfigured=configured("callback_bearer_token","callback_bearer_token_configured")||!!diagnostics.callback_bearer_token_configured;add(errors,secretConfigured,"PayArc SecretKey/API bearer token must be configured.");add(errors,accessConfigured,"Press Connect PayArc to fetch a Connect AccessToken.");add(errors,callbackConfigured,"Callback bearer token must be configured.");add(errors,mid.length>=12,"PayArc MID must contain at least 12 digits.");add(errors,/^[0-9]{10}$/.test(terminal),"Select a discovered PayArc terminal.");add(errors,/^https:\/\//i.test(fieldValue("webhook_url")),"Callback URL must be HTTPS.");add(errors,["0","1","2","3"].indexOf(fieldValue("print_receipt"))!==-1,"Print receipt must be one of 0, 1, 2, or 3.");add(errors,["CREDIT","DEBIT"].indexOf(fieldValue("tender_type").toUpperCase())!==-1,"Tender type must be CREDIT or DEBIT.");render(errors);}' .
            'button.addEventListener("click",function(){var action=button.getAttribute("data-action")||"patwc_validate_settings";var nonce=button.getAttribute("data-nonce")||"";var ajaxUrl=button.getAttribute("data-ajax-url")||"admin-ajax.php";result.textContent="Checking saved settings...";if(!window.fetch||!window.FormData){localValidate({});return;}var data=new FormData();data.append("action",action);data.append("_ajax_nonce",nonce);window.fetch(ajaxUrl,{method:"POST",credentials:"same-origin",body:data}).then(function(response){return response.json();}).then(function(body){localValidate(body&&body.diagnostics?body.diagnostics:{});}).catch(function(){localValidate({});});});' .
            '})(' . $encoded . ');</script>';
    }


    /**
     * @param mixed $value
     */
    private function json_encode($value): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value);
        } else {
            $encoded = json_encode($value);
        }

        return is_string($encoded) ? $encoded : 'null';
    }

    private static function mask_identifier(string $value): string
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
     * @param mixed $value
     */
    private static function diagnostic_text($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);
        $text = preg_replace('/[[:cntrl:]]+/', ' ', $text);
        if (!is_string($text)) {
            return '';
        }

        $text = preg_replace('/\\bBearer\\s+[A-Za-z0-9._~+\\/=:-]+/i', 'Bearer [REDACTED]', $text);
        if (!is_string($text)) {
            return '';
        }

        $text = preg_replace('/\\b(api|callback|access|bearer|token|secret)([_ -]?(token|secret|key))?\\b\\s*[:=]?\\s*[A-Za-z0-9._~+\\/=:-]{8,}/i', '$1 [REDACTED]', $text);
        if (!is_string($text)) {
            return '';
        }

        return trim($text);
    }

    /**
     * @param array<int, array{key:string, status:string, message:string}> $checks
     */
    private static function append_local_check(array &$checks, string $key, bool $passed, string $successMessage, string $errorMessage): void
    {
        $checks[] = array(
            'key' => $key,
            'status' => $passed ? 'ok' : 'error',
            'message' => $passed ? $successMessage : $errorMessage,
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function setting_configured(array $settings, string $flagKey, string $secretKey): bool
    {
        if (array_key_exists($secretKey, $settings) && is_scalar($settings[$secretKey]) && trim((string) $settings[$secretKey]) !== '') {
            return true;
        }

        if (!array_key_exists($flagKey, $settings) || !is_scalar($settings[$flagKey])) {
            return false;
        }

        $value = $settings[$flagKey];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'configured'), true);
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

    private function escape_url(string $value): string
    {
        if (function_exists('esc_url')) {
            return esc_url($value);
        }

        return filter_var($value, FILTER_SANITIZE_URL);
    }

    /**
     * @param object $order
     */
    private function enqueue_payment_assets($order, bool $authorized): void
    {
        $version = defined('PATWC_VERSION') ? PATWC_VERSION : '0.1.4';
        $pluginUrl = defined('PATWC_PLUGIN_URL') ? rtrim(PATWC_PLUGIN_URL, '/') . '/' : '';

        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('patwc-payment', $pluginUrl . 'assets/css/payment.css', array(), $version);
        }

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('patwc-payment', $pluginUrl . 'assets/js/payment.js', array('jquery'), $version, true);
        }

        if (function_exists('wp_localize_script')) {
            wp_localize_script('patwc-payment', 'patwcPaymentData', array(
                'ajaxUrl' => function_exists('admin_url') ? $this->escape_url(admin_url('admin-ajax.php')) : 'admin-ajax.php',
                'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('patwc_payment') : '',
                'orderId' => $this->order_id($order),
                'orderToken' => $authorized && class_exists(__NAMESPACE__ . '\\AjaxHandler') ? AjaxHandler::order_token_for($order) : '',
                'pollInterval' => 1500,
                'timeoutMs' => 300000,
                'strings' => array(
                    'ready' => 'Ready to start payment.',
                    'starting' => 'Starting terminal payment...',
                    'waiting' => 'Waiting for the terminal result...',
                    'approved' => 'Payment approved. Completing the order...',
                    'retry' => 'Payment was not approved. Please check the terminal and try again.',
                    'canceling' => 'Cancel requested. Waiting for final terminal status...',
                    'timeout' => 'Payment timed out while waiting for the terminal. Check the terminal before retrying.',
                    'error' => 'Unable to contact the payment service. Please try again.',
                ),
            ));
        }
    }

    /**
     * @return object|null
     */
    private function current_payment_order()
    {
        $orderId = 0;

        if (function_exists('get_query_var')) {
            $queryOrderId = get_query_var('order-pay', 0);
            $orderId = $this->positive_int($queryOrderId);
        }

        if ($orderId <= 0 && isset($_GET['order-pay'])) {
            $orderId = $this->positive_int($_GET['order-pay']);
        }

        if ($orderId <= 0 && isset($_GET['order_id'])) {
            $candidateOrderId = $this->positive_int($_GET['order_id']);
            if ($candidateOrderId > 0 && $this->viewer_has_privileged_order_access($candidateOrderId)) {
                $orderId = $candidateOrderId;
            }
        }

        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);

        return is_object($order) ? $order : null;
    }

    /**
     * @param object $order
     */
    private function viewer_can_access_order($order): bool
    {
        $orderId = $this->order_id($order);

        if ($orderId > 0 && $this->viewer_has_privileged_order_access($orderId)) {
            return true;
        }

        if (!method_exists($order, 'get_order_key')) {
            return false;
        }

        $requestKey = $this->request_order_key();
        if ($requestKey === '') {
            return false;
        }

        $orderKey = (string) $order->get_order_key();

        if (function_exists('hash_equals')) {
            return hash_equals($orderKey, $requestKey);
        }

        return $orderKey === $requestKey;
    }

    private function viewer_has_privileged_order_access(int $orderId): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_order', $orderId);
    }

    private function request_order_key(): string
    {
        $key = '';

        if (function_exists('get_query_var')) {
            $queryKey = get_query_var('key', '');
            if (is_scalar($queryKey)) {
                $key = trim((string) $queryKey);
            }
        }

        if ($key === '' && isset($_GET['key']) && is_scalar($_GET['key'])) {
            $key = trim((string) $_GET['key']);
        }

        return $key;
    }

    /**
     * @param mixed $value
     */
    private function positive_int($value): int
    {
        if (!is_scalar($value)) {
            return 0;
        }

        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @param object $order
     */
    private function order_id($order): int
    {
        if (method_exists($order, 'get_id')) {
            return (int) $order->get_id();
        }

        return 0;
    }

    /**
     * @param object $order
     */
    private function order_return_url($order): string
    {
        if (method_exists($this, 'get_return_url')) {
            return (string) $this->get_return_url($order);
        }

        if (method_exists($order, 'get_checkout_order_received_url')) {
            return (string) $order->get_checkout_order_received_url();
        }

        return '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function tenant_id_from_settings(array $settings): string
    {
        $mid = preg_replace('/\D+/', '', self::setting_string($settings, 'connect_mid'));
        if (is_string($mid) && strlen($mid) >= 12) {
            return substr($mid, -12);
        }

        $manual = self::setting_string($settings, 'tenant_id');
        if (preg_match('/^[0-9]{12}$/', $manual) === 1) {
            return $manual;
        }

        return $manual;
    }

    /**
     * @return array<string, mixed>
     */
    private function saved_connection_state(): array
    {
        if (!function_exists('get_option')) {
            return array();
        }

        $option = 'woocommerce_' . Settings::GATEWAY_ID . '_settings';
        $settings = get_option($option, array());
        if (!is_array($settings)) {
            return array();
        }

        $state = array();
        foreach (array('connect_access_token', 'connect_token_expires_at', 'terminal_registry') as $key) {
            if (array_key_exists($key, $settings)) {
                $state[$key] = $settings[$key];
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $connectionState
     */
    private function preserve_connection_state(array $connectionState): void
    {
        if (count($connectionState) === 0 || !function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $option = 'woocommerce_' . Settings::GATEWAY_ID . '_settings';
        $settings = get_option($option, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        update_option($option, array_merge($settings, $connectionState));
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
