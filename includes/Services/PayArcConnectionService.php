<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;
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
        $login = $this->login($settings);
        $registry = array();
        $registryWarning = '';

        try {
            $registry = $this->terminal_registry($settings);
        } catch (RuntimeException $exception) {
            $registryWarning = $exception->getMessage();
        }

        $loginTerminals = isset($login['Terminals']) && is_array($login['Terminals']) ? $login['Terminals'] : array();
        $terminals = $this->normalize_terminals(array_merge($loginTerminals, $registry));
        $tokenInfo = isset($login['BearerTokenInfo']) && is_array($login['BearerTokenInfo']) ? $login['BearerTokenInfo'] : array();
        $accessToken = isset($tokenInfo['AccessToken']) && is_scalar($tokenInfo['AccessToken']) ? trim((string) $tokenInfo['AccessToken']) : '';

        if ($accessToken === '') {
            throw new RuntimeException('PayArc Login did not return a Connect access token.');
        }

        $expiresIn = isset($tokenInfo['ExpiresIn']) && is_scalar($tokenInfo['ExpiresIn']) ? (int) $tokenInfo['ExpiresIn'] : 0;
        $expiresAt = $expiresIn > 0 ? $this->now() + max(60, $expiresIn - 60) : 0;
        $tenantId = $settings->tenant_id();
        $defaultTerminal = $this->choose_default_terminal($terminals, $settings->default_terminal_id());
        $updates = $this->credential_updates($settings, $overrides);
        $updates['connect_access_token'] = $accessToken;
        $updates['connect_token_expires_at'] = (string) $expiresAt;
        $updates['tenant_id'] = $tenantId;
        $updates['terminal_registry'] = $terminals;
        if ($defaultTerminal !== '') {
            $updates['default_terminal_id'] = $defaultTerminal;
        }

        $this->persist($updates);

        $result = $this->public_result('connected', 'Connected to PayArc. Select a discovered terminal and save settings.', $tenantId, $defaultTerminal, $terminals);
        if ($registryWarning !== '') {
            $result['warning'] = 'Connected with Login terminals only. Terminal Registry lookup failed. Refresh terminals after confirming Merchant API access.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh_terminals(): array
    {
        $registry = $this->terminal_registry($this->settings);
        $terminals = $this->normalize_terminals($registry);
        $defaultTerminal = $this->choose_default_terminal($terminals, $this->settings->default_terminal_id());
        $updates = array('terminal_registry' => $terminals);
        if ($defaultTerminal !== '') {
            $updates['default_terminal_id'] = $defaultTerminal;
        }
        $this->persist($updates);

        return $this->public_result('connected', 'PayArc terminals refreshed.', $this->settings->tenant_id(), $defaultTerminal, $terminals);
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        $this->persist(array(
            'connect_access_token' => '',
            'connect_token_expires_at' => '0',
            'terminal_registry' => array(),
            'default_terminal_id' => '',
        ));

        return array(
            'status' => 'disconnected',
            'message' => 'Disconnected from PayArc. Saved credentials were left in place so the merchant can reconnect quickly.',
            'terminal_count' => 0,
            'tenant_id_configured' => preg_match('/^[0-9]{12}$/', $this->settings->tenant_id()) === 1,
            'default_terminal_id_configured' => false,
            'terminals' => array(),
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

        return $accessToken;
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
            throw new RuntimeException('PayArc Login failed; ErrorCode: ' . $errorCode . '; ErrorMessage: ' . $this->safe_text($message) . '.');
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
    public function normalize_terminals(array $rawTerminals): array
    {
        $terminals = array();
        $seen = array();

        foreach ($rawTerminals as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $terminalId = $this->field($raw, array('pos_identifier', 'Pos_identifier', 'terminal_id', 'TerminalId'));
            if (preg_match('/^[0-9]{10}$/', $terminalId) !== 1) {
                continue;
            }

            if (isset($seen[$terminalId])) {
                continue;
            }

            $enabled = $this->enabled_field($raw);
            if (!$enabled) {
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

        return $terminals;
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
            foreach ($terminals as $terminal) {
                $terminalId = isset($terminal['terminal_id']) && is_scalar($terminal['terminal_id']) ? trim((string) $terminal['terminal_id']) : '';
                if ($terminalId === $currentDefault) {
                    return $currentDefault;
                }
            }
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
            throw new RuntimeException('PayArc request failed before receiving a response.');
        }
        if (!is_array($response)) {
            throw new RuntimeException('PayArc response was not an array.');
        }

        $status = isset($response['response']) && is_array($response['response']) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        $body = isset($response['body']) ? $response['body'] : '';
        if (!is_string($body) || trim($body) === '') {
            throw new RuntimeException('PayArc response body was empty. HTTP status: ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('PayArc response was not valid JSON. HTTP status: ' . $status . '.');
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->failure_message($decoded, $status));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function failure_message(array $decoded, int $status): string
    {
        $parts = array('PayArc request failed');
        if ($status > 0) {
            $parts[] = 'HTTP status: ' . $status;
        }
        if (isset($decoded['error']) && is_scalar($decoded['error'])) {
            $parts[] = 'error: ' . $this->safe_text((string) $decoded['error']);
        }
        if (isset($decoded['ErrorMessage']) && is_scalar($decoded['ErrorMessage'])) {
            $parts[] = 'ErrorMessage: ' . $this->safe_text((string) $decoded['ErrorMessage']);
        }

        return implode('; ', $parts) . '.';
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
     * @return array<string, mixed>
     */
    private function public_result(string $status, string $message, string $tenantId, string $defaultTerminal, array $terminals): array
    {
        return array(
            'status' => $status,
            'message' => $message,
            'tenant_id' => $tenantId,
            'tenant_id_configured' => preg_match('/^[0-9]{12}$/', $tenantId) === 1,
            'default_terminal_id' => $defaultTerminal,
            'default_terminal_id_configured' => preg_match('/^[0-9]{10}$/', $defaultTerminal) === 1,
            'terminal_count' => count($terminals),
            'terminals' => array_map(static function (array $terminal): array {
                return array(
                    'terminal_id' => (string) $terminal['terminal_id'],
                    'label' => (string) $terminal['label'],
                );
            }, $terminals),
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

    private function now(): int
    {
        return $this->clock === null ? time() : (int) call_user_func($this->clock);
    }

    private function safe_text(string $text): string
    {
        $text = preg_replace('/[[:cntrl:]]+/', ' ', $text);
        if (!is_string($text)) {
            return '';
        }

        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [REDACTED]', $text);
        if (!is_string($text)) {
            return '';
        }

        return trim($text);
    }
}
