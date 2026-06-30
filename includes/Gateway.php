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
        $version = defined('PATWC_VERSION') ? PATWC_VERSION : '0.1.0';
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
