<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\PayArcTerminal\Logger;
use WCPOS\WooCommercePOS\PayArcTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

class PayArcConnectionService
{
    /** @var Settings */
    private $settings;

    /** @var callable|null */
    private $option_updater;

    /** @var callable|null */
    private $clock;

    /**
     * @param callable|null $option_updater Receives array<string,mixed> updates.
     */
    public function __construct(?Settings $settings = null, ?callable $option_updater = null, ?callable $clock = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
        $this->option_updater = $option_updater;
        $this->clock = $clock;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function connect(array $overrides = array()): array
    {
        $settings = $this->settings_with_overrides($overrides);
        $this->assert_no_in_flight_payment_attempts();
        Logger::log('PayArc connection attempt started', $this->connection_log_context($settings, array(
            'submitted_override_fields' => $this->submitted_override_fields($overrides),
        )));

        $login = $this->login_selected_mode($settings);
        $registry = array();
        $registryWarning = '';

        try {
            $registry = $this->terminal_registry($settings);
        } catch (RuntimeException $exception) {
            $registryWarning = $exception->getMessage();
            Logger::log('PayArc terminal registry lookup failed during connect', $this->connection_log_context($settings, array(
                'exception_class' => get_class($exception),
                'message' => $this->safe_text($exception->getMessage()),
            )), null, 'warning');
        }

        $loginTerminals = isset($login['Terminals']) && is_array($login['Terminals']) ? $login['Terminals'] : array();
        $partition = $this->partition_terminals(array_merge($loginTerminals, $registry), $settings);
        $terminals = $partition['terminals'];
        $unidentifiedTerminals = $partition['unidentified'];
        $tokenInfo = isset($login['BearerTokenInfo']) && is_array($login['BearerTokenInfo']) ? $login['BearerTokenInfo'] : array();
        $accessToken = isset($tokenInfo['AccessToken']) && is_scalar($tokenInfo['AccessToken']) ? trim((string) $tokenInfo['AccessToken']) : '';

        Logger::log('PayArc Login completed', $this->connection_log_context($settings, array(
            'connect_access_token_returned' => $accessToken !== '',
            'login_terminal_count' => count($loginTerminals),
            'registry_terminal_count' => count($registry),
        )));

        if ($accessToken === '') {
            throw new RuntimeException('PayArc Login did not return a Connect access token.');
        }

        $expiresIn = isset($tokenInfo['ExpiresIn']) && is_scalar($tokenInfo['ExpiresIn']) ? (int) $tokenInfo['ExpiresIn'] : 0;
        $expiresAt = $expiresIn > 0 ? $this->now() + max(60, $expiresIn - 60) : 0;
        $tenantId = $settings->tenant_id();
        $defaultTerminal = $this->choose_default_terminal($terminals, $settings->default_terminal_id());
        $updates = $this->credential_updates($settings, $overrides);
        $updates['connected_mode'] = $settings->mode();
        $updates['connected_fingerprint'] = $settings->connection_fingerprint();
        $updates['connect_access_token'] = $accessToken;
        $updates['connect_token_expires_at'] = (string) $expiresAt;
        $updates['tenant_id'] = $tenantId;
        $updates['terminal_registry'] = $terminals;
        $updates['default_terminal_id'] = $defaultTerminal;

        $this->assert_no_in_flight_payment_attempts();
        $this->persist($updates);

        $result = $this->public_result('connected', 'Connected to PayArc. Enter or confirm the PayArc terminal serial number and save settings.', $tenantId, $defaultTerminal, $terminals, $unidentifiedTerminals);
        if ($registryWarning !== '') {
            $result['warning'] = 'Connected to PayArc. Terminal Registry lookup failed, but registry data is only reporting metadata; confirm the terminal serial number with PayArc before testing a payment.';
        }

        Logger::log('PayArc connection completed', $this->connection_log_context($settings, array(
            'tenant_id_masked' => Settings::mask_identifier($tenantId),
            'default_terminal_id_masked' => Settings::mask_identifier($defaultTerminal),
            'terminal_count' => count($terminals),
            'unidentified_terminal_count' => count($unidentifiedTerminals),
            'terminal_registry_warning' => $registryWarning !== '',
            'default_terminal_in_fetched_list' => $this->terminal_in_list($terminals, $defaultTerminal),
        )));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh_terminals(): array
    {
        $this->assert_no_in_flight_payment_attempts();
        Logger::log('PayArc terminal refresh started', $this->connection_log_context($this->settings));
        $registry = $this->terminal_registry($this->settings);
        $partition = $this->partition_terminals($registry);
        $terminals = $partition['terminals'];
        $unidentifiedTerminals = $partition['unidentified'];
        $defaultTerminal = $this->choose_default_terminal($terminals, $this->settings->default_terminal_id());
        $updates = array(
            'terminal_registry' => $terminals,
            'default_terminal_id' => $defaultTerminal,
        );
        $this->assert_no_in_flight_payment_attempts();
        $this->persist($updates);

        Logger::log('PayArc terminal refresh completed', $this->connection_log_context($this->settings, array(
            'default_terminal_id_masked' => Settings::mask_identifier($defaultTerminal),
            'terminal_count' => count($terminals),
            'unidentified_terminal_count' => count($unidentifiedTerminals),
            'default_terminal_in_fetched_list' => $this->terminal_in_list($terminals, $defaultTerminal),
        )));

        return $this->public_result('connected', 'PayArc terminals refreshed.', $this->settings->tenant_id(), $defaultTerminal, $terminals, $unidentifiedTerminals);
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        $this->assert_no_in_flight_payment_attempts();
        Logger::log('PayArc disconnect requested', $this->connection_log_context($this->settings));
        $this->assert_no_in_flight_payment_attempts();
        $this->persist(array(
            'connected_mode' => '',
            'connected_fingerprint' => '',
            'connect_access_token' => '',
            'connect_token_expires_at' => '0',
            'terminal_registry' => array(),
            'default_terminal_id' => '',
        ));

        return array(
            'status' => 'disconnected',
            'message' => 'Disconnected from PayArc. Saved credentials were left in place so the merchant can reconnect quickly.',
            'terminal_count' => 0,
            'tenant_id_configured' => $this->settings->tenant_id() !== '',
            'default_terminal_id_configured' => false,
            'terminals' => array(),
            'unidentified_terminal_count' => 0,
            'unidentified_terminals' => array(),
        );
    }

    public function ensure_connect_access_token(): string
    {
        $token = $this->settings->connect_access_token();
        $expiresAt = $this->settings->connect_token_expires_at();

        if ($token !== '' && ($expiresAt === 0 || $expiresAt > $this->now() + 60)) {
            return $token;
        }

        $login = $this->login($this->settings);
        $tokenInfo = isset($login['BearerTokenInfo']) && is_array($login['BearerTokenInfo']) ? $login['BearerTokenInfo'] : array();
        $accessToken = isset($tokenInfo['AccessToken']) && is_scalar($tokenInfo['AccessToken']) ? trim((string) $tokenInfo['AccessToken']) : '';
        if ($accessToken === '') {
            throw new RuntimeException('PayArc Login did not return a Connect access token.');
        }

        $expiresIn = isset($tokenInfo['ExpiresIn']) && is_scalar($tokenInfo['ExpiresIn']) ? (int) $tokenInfo['ExpiresIn'] : 0;
        $expiresAt = $expiresIn > 0 ? $this->now() + max(60, $expiresIn - 60) : 0;
        $this->persist(array(
            'connect_access_token' => $accessToken,
            'connect_token_expires_at' => (string) $expiresAt,
        ));

        Logger::log('PayArc Connect AccessToken refreshed', $this->connection_log_context($this->settings, array(
            'connect_access_token_returned' => true,
        )));

        return $accessToken;
    }

    private function assert_no_in_flight_payment_attempts(): void
    {
        if (class_exists(PaymentAttempt::class) && PaymentAttempt::has_in_flight_attempts()) {
            throw new RuntimeException('Wait for in-progress PayArc terminal payments to finish before changing the PayArc connection.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function login_selected_mode(Settings $settings): array
    {
        try {
            return $this->login($settings);
        } catch (RuntimeException $exception) {
            if (!$this->is_login_authentication_failure($exception)) {
                throw $exception;
            }

            $oppositeMode = $this->opposite_mode($settings->mode());
            if ($this->opposite_mode_accepts_credentials($settings, $oppositeMode)) {
                throw new RuntimeException('These look like ' . $this->mode_label($oppositeMode) . ' PayArc credentials. Switch Mode to ' . $this->mode_label($oppositeMode) . ' and click Connect PayArc again.');
            }

            throw $exception;
        }
    }

    private function is_login_authentication_failure(RuntimeException $exception): bool
    {
        return in_array((int) $exception->getCode(), array(1, 401, 403), true);
    }

    private function opposite_mode_accepts_credentials(Settings $settings, string $oppositeMode): bool
    {
        $oppositeSettings = $settings->all();
        $oppositeSettings['mode'] = $oppositeMode;
        unset($oppositeSettings['connect_login_base_url'], $oppositeSettings['connect_base_url'], $oppositeSettings['merchant_api_base_url']);

        try {
            $response = $this->login(new Settings($oppositeSettings));
        } catch (RuntimeException $exception) {
            Logger::log('PayArc opposite environment credential probe did not authenticate', $this->connection_log_context(new Settings($oppositeSettings), array(
                'exception_class' => get_class($exception),
                'message' => $this->safe_text($exception->getMessage()),
            )), null, 'info');

            return false;
        }

        $tokenInfo = isset($response['BearerTokenInfo']) && is_array($response['BearerTokenInfo']) ? $response['BearerTokenInfo'] : array();
        $accessToken = isset($tokenInfo['AccessToken']) && is_scalar($tokenInfo['AccessToken']) ? trim((string) $tokenInfo['AccessToken']) : '';

        Logger::log('PayArc opposite environment credential probe authenticated', $this->connection_log_context(new Settings($oppositeSettings), array(
            'connect_access_token_returned' => $accessToken !== '',
        )), null, 'warning');

        return $accessToken !== '';
    }

    private function opposite_mode(string $mode): string
    {
        return $mode === 'production' ? 'test' : 'production';
    }

    private function mode_label(string $mode): string
    {
        return $mode === 'production' ? 'Live' : 'Test';
    }

    /**
     * @return array<string, mixed>
     */
    public function login(?Settings $settings = null): array
    {
        $settings = $settings === null ? $this->settings : $settings;
        $this->assert_credentials($settings);

        $payload = array(
            'Email' => $settings->connect_email(),
            'MID' => $settings->connect_mid(),
            'ClientSecret' => $settings->connect_client_secret(),
            'SecretKey' => $settings->connect_secret_key(),
        );

        $response = $this->request('POST', $settings->connect_login_base_url() . '/Login', array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $settings->connect_secret_key(),
        ), $payload);

        $errorCode = isset($response['ErrorCode']) && is_scalar($response['ErrorCode']) ? (int) $response['ErrorCode'] : 0;
        if ($errorCode !== 0) {
            $message = isset($response['ErrorMessage']) && is_scalar($response['ErrorMessage']) ? (string) $response['ErrorMessage'] : 'PayArc Login failed.';
            throw new RuntimeException('PayArc Login failed; ErrorCode: ' . $errorCode . '; ErrorMessage: ' . $this->safe_text($message) . '.', $errorCode);
        }

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function terminal_registry(?Settings $settings = null): array
    {
        $settings = $settings === null ? $this->settings : $settings;
        $secret = $settings->connect_secret_key();
        if ($secret === '') {
            throw new RuntimeException('PayArc SecretKey/API bearer token is required to fetch terminals.');
        }

        $response = $this->request('GET', $settings->merchant_api_base_url() . '/v1/terminalregistries', array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $secret,
        ));

        return isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
    }

    /**
     * @param array<int, mixed> $rawTerminals
     * @return array<int, array<string, mixed>>
     */
    public function normalize_terminals(array $rawTerminals, ?Settings $settings = null): array
    {
        return $this->partition_terminals($rawTerminals, $settings)['terminals'];
    }

    /**
     * Splits raw PayArc terminal records into selectable terminals and
     * informational records that PayArc reported without a POS identifier.
     *
     * @param array<int, mixed> $rawTerminals
     * @return array{terminals: array<int, array<string, mixed>>, unidentified: array<int, array<string, string>>}
     */
    private function partition_terminals(array $rawTerminals, ?Settings $settings = null): array
    {
        $settings = $settings === null ? $this->settings : $settings;
        $terminals = array();
        $unidentified = array();
        $seen = array();
        $seenUnidentified = array();

        foreach ($rawTerminals as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $terminalId = $this->field($raw, array('pos_identifier', 'Pos_identifier', 'terminal_id', 'TerminalId'));
            $enabled = $this->enabled_field($raw);
            if (!$enabled) {
                $this->log_dropped_terminal($settings, 'disabled', $terminalId);
                continue;
            }

            if ($terminalId === '') {
                // Informational, not a warning: the Terminal Registry schema
                // makes pos_identifier nullable, and a sale is addressed by the
                // 10-digit terminal serial number instead.
                $this->log_dropped_terminal($settings, 'no_pos_identifier', $terminalId, 'info');
                $name = $this->field($raw, array('terminal', 'Terminal', 'name', 'Name'));
                $type = $this->field($raw, array('type', 'Type'));
                // Dedupe key may use raw device fields because it never leaves
                // this method; the exposed entry carries only name/type.
                $deviceId = $this->field($raw, array('device_id', 'Device_id'));
                $code = $this->field($raw, array('code', 'Code', 'id', 'Id'));
                if ($deviceId !== '') {
                    $key = 'device|' . strtolower($deviceId);
                } elseif ($code !== '') {
                    $key = 'code|' . strtolower($code);
                } else {
                    $key = 'label|' . strtolower($name . '|' . $type) . '|' . count($seenUnidentified);
                }
                if (!isset($seenUnidentified[$key])) {
                    $seenUnidentified[$key] = true;
                    $unidentified[] = array(
                        'label' => Settings::terminal_label($name, $type, ''),
                    );
                }
                continue;
            }

            if (isset($seen[$terminalId])) {
                continue;
            }

            $name = $this->field($raw, array('terminal', 'Terminal', 'name', 'Name'));
            $type = $this->field($raw, array('type', 'Type'));
            $deviceId = $this->field($raw, array('device_id', 'Device_id'));
            $code = $this->field($raw, array('code', 'Code', 'id', 'Id'));
            $seen[$terminalId] = true;
            $terminals[] = array(
                'terminal_id' => $terminalId,
                'label' => Settings::terminal_label($name, $type, $terminalId),
                'enabled' => true,
                'name' => $name,
                'type' => $type,
                'device_id' => $deviceId,
                'code' => $code,
            );
        }

        return array('terminals' => $terminals, 'unidentified' => $unidentified);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function settings_with_overrides(array $overrides): Settings
    {
        $settings = $this->settings->all();
        foreach ($overrides as $key => $value) {
            if (is_scalar($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    $settings[$key] = $string;
                }
            }
        }

        return new Settings($settings);
    }

    private function assert_credentials(Settings $settings): void
    {
        $missing = array();
        if ($settings->connect_email() === '') {
            $missing[] = 'PayArc login email';
        }
        if ($settings->connect_mid() === '') {
            $missing[] = 'PayArc MID';
        }
        if ($settings->connect_client_secret() === '') {
            $missing[] = 'PayArc ClientSecret';
        }
        if ($settings->connect_secret_key() === '') {
            $missing[] = 'PayArc SecretKey/API bearer token';
        }

        if (count($missing) > 0) {
            throw new RuntimeException(implode(', ', $missing) . ' required.');
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function credential_updates(Settings $settings, array $overrides): array
    {
        $updates = array(
            'connect_email' => $settings->connect_email(),
            'connect_mid' => $settings->connect_mid(),
        );

        foreach (array('connect_client_secret', 'connect_secret_key', 'callback_bearer_token') as $key) {
            if (array_key_exists($key, $overrides) && is_scalar($overrides[$key]) && trim((string) $overrides[$key]) !== '') {
                $updates[$key] = trim((string) $overrides[$key]);
            } elseif ($settings->{$key}() !== '') {
                $updates[$key] = $settings->{$key}();
            }
        }

        return $updates;
    }

    /**
     * @param array<int, array<string, mixed>> $terminals
     */
    private function choose_default_terminal(array $terminals, string $currentDefault): string
    {
        $currentDefault = trim($currentDefault);
        if ($currentDefault !== '') {
            return $currentDefault;
        }

        return count($terminals) > 0 ? (string) $terminals[0]['terminal_id'] : '';
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $headers, ?array $payload = null): array
    {
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        if ($payload !== null) {
            $body = json_encode($payload);
            if (!is_string($body)) {
                throw new RuntimeException('Unable to encode PayArc request body.');
            }
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            Logger::log('PayArc request failed before response', $this->request_log_context($method, $url, $payload), null, 'error');
            throw new RuntimeException('PayArc request failed before receiving a response.');
        }
        if (!is_array($response)) {
            Logger::log('PayArc response was not an array', $this->request_log_context($method, $url, $payload), null, 'error');
            throw new RuntimeException('PayArc response was not an array.');
        }

        $status = isset($response['response']) && is_array($response['response']) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        $body = isset($response['body']) ? $response['body'] : '';
        if (!is_string($body) || trim($body) === '') {
            Logger::log('PayArc response body was empty', $this->request_log_context($method, $url, $payload, array('http_status' => $status)), null, 'error');
            throw new RuntimeException('PayArc response body was empty. HTTP status: ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            Logger::log('PayArc response was not valid JSON', $this->request_log_context($method, $url, $payload, array('http_status' => $status)), null, 'error');
            throw new RuntimeException('PayArc response was not valid JSON. HTTP status: ' . $status . '.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $this->failure_message($status);
            Logger::log('PayArc request returned an error status', $this->request_log_context($method, $url, $payload, array(
                'http_status' => $status,
                'message' => $message,
            )), null, 'error');
            throw new RuntimeException($message, $status);
        }

        return $decoded;
    }

    /**
     */
    private function failure_message(int $status): string
    {
        $parts = array('PayArc request failed.');
        if ($status > 0) {
            $parts[] = 'HTTP status: ' . $status . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function persist(array $updates): void
    {
        if ($this->option_updater !== null) {
            call_user_func($this->option_updater, $updates);
            return;
        }

        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $option = 'woocommerce_' . Settings::GATEWAY_ID . '_settings';
        $current = get_option($option, array());
        if (!is_array($current)) {
            $current = array();
        }

        update_option($option, array_merge($current, $updates));
    }

    /**
     * @param array<int, array<string, mixed>> $terminals
     * @param array<int, array<string, string>> $unidentifiedTerminals
     * @return array<string, mixed>
     */
    private function public_result(string $status, string $message, string $tenantId, string $defaultTerminal, array $terminals, array $unidentifiedTerminals = array()): array
    {
        if (count($unidentifiedTerminals) > 0) {
            // pos_identifier is optional in the Terminal Registry schema and is
            // not the value a sale needs, so its absence is normal metadata and
            // must not read as a provisioning fault.
            $message .= ' PayArc also returned ' . count($unidentifiedTerminals) . ' registry record(s) with no POS identifier. That is normal: the terminal serial number below is what PayArc Connect uses to take a payment.';
        }

        return array(
            'status' => $status,
            'message' => $message,
            'tenant_id' => $tenantId,
            'tenant_id_configured' => $tenantId !== '',
            'default_terminal_id' => $defaultTerminal,
            'default_terminal_id_configured' => $defaultTerminal !== '',
            'terminal_count' => count($terminals),
            'terminals' => array_map(static function (array $terminal): array {
                return array(
                    'terminal_id' => (string) $terminal['terminal_id'],
                    'label' => (string) $terminal['label'],
                );
            }, $terminals),
            'unidentified_terminal_count' => count($unidentifiedTerminals),
            'unidentified_terminals' => array_map(static function (array $terminal): array {
                return array(
                    'label' => (string) $terminal['label'],
                );
            }, $unidentifiedTerminals),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param string[] $keys
     */
    private function field(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                return trim((string) $raw[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function enabled_field(array $raw): bool
    {
        foreach (array('is_enabled', 'Is_enabled', 'enabled') as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            if (is_bool($raw[$key])) {
                return $raw[$key];
            }

            if (is_scalar($raw[$key])) {
                return in_array(strtolower(trim((string) $raw[$key])), array('1', 'true', 'yes', 'enabled'), true);
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $terminals
     */
    private function terminal_in_list(array $terminals, string $terminalId): bool
    {
        if ($terminalId === '') {
            return false;
        }

        foreach ($terminals as $terminal) {
            if (isset($terminal['terminal_id']) && is_scalar($terminal['terminal_id']) && trim((string) $terminal['terminal_id']) === $terminalId) {
                return true;
            }
        }

        return false;
    }

    private function log_dropped_terminal(Settings $settings, string $reason, string $terminalId, string $level = 'warning'): void
    {
        try {
            Logger::log('PayArc terminal record not selectable', $this->connection_log_context($settings, array(
                'drop_reason' => $reason,
                'terminal_id_masked' => Settings::mask_identifier($terminalId),
            )), null, $level);
        } catch (\Throwable $exception) {
            // Diagnostic logging must not interrupt terminal discovery.
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function connection_log_context(Settings $settings, array $extra = array()): array
    {
        return array_merge(array(
            'mode' => $settings->mode(),
            'connect_email_configured' => $settings->connect_email() !== '',
            'connect_mid_configured' => $settings->connect_mid() !== '',
            'connect_mid_masked' => Settings::mask_identifier($settings->connect_mid()),
            'tenant_id_masked' => Settings::mask_identifier($settings->tenant_id()),
            'connect_client_secret_configured' => $settings->connect_client_secret() !== '',
            'connect_secret_key_configured' => $settings->connect_secret_key() !== '',
            'callback_bearer_token_configured' => $settings->callback_bearer_token() !== '',
            'connect_login_host' => $this->url_host($settings->connect_login_base_url()),
            'merchant_api_host' => $this->url_host($settings->merchant_api_base_url()),
        ), $extra);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return string[]
     */
    private function submitted_override_fields(array $overrides): array
    {
        $fields = array();

        foreach (array('mode', 'connect_email', 'connect_mid', 'connect_client_secret', 'connect_secret_key', 'callback_bearer_token') as $key) {
            if (array_key_exists($key, $overrides) && is_scalar($overrides[$key]) && trim((string) $overrides[$key]) !== '') {
                $fields[] = $key;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function request_log_context(string $method, string $url, ?array $payload = null, array $extra = array()): array
    {
        return array_merge(array(
            'method' => $method,
            'host' => $this->url_host($url),
            'path' => $this->url_path($url),
            'has_payload' => $payload !== null,
        ), $extra);
    }

    private function url_host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function url_path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function safe_text(string $text): string
    {
        // Untrusted PayArc-returned error text (e.g. ErrorMessage) embedded into
        // exceptions — redacted aggressively via the shared Logger helper.
        return Logger::redact_untrusted_text($text);
    }
}
