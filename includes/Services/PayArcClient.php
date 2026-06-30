<?php

namespace WCPOS\WooCommercePOS\PayArcTerminal\Services;

use RuntimeException;
use WCPOS\WooCommercePOS\PayArcTerminal\Settings;

class PayArcClient
{
    /** @var Settings */
    private $settings;

    /** @var object */
    private $connection_service;

    /**
     * @param object|null $connection_service
     */
    public function __construct(?Settings $settings = null, $connection_service = null)
    {
        $this->settings = $settings === null ? new Settings() : $settings;
        $this->connection_service = $connection_service === null ? new PayArcConnectionService($this->settings) : $connection_service;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sale(array $payload, string $idempotency_key): array
    {
        if (trim($idempotency_key) === '') {
            throw new RuntimeException('PayArc idempotency key is required.');
        }

        return $this->request('POST', '/v3/transactions/sale', $payload, $idempotency_key);
    }

    /**
     * @return array<string, mixed>
     */
    public function get_transaction(string $trace_id): array
    {
        $trace_id = trim($trace_id);

        if ($trace_id === '') {
            throw new RuntimeException('PayArc traceId is required.');
        }

        return $this->request('GET', '/v3/transactions/' . rawurlencode($trace_id));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(string $trace_id, array $payload, string $idempotency_key): array
    {
        $trace_id = trim($trace_id);

        if ($trace_id === '') {
            throw new RuntimeException('PayArc traceId is required.');
        }

        if (trim($idempotency_key) === '') {
            throw new RuntimeException('PayArc idempotency key is required.');
        }

        return $this->request('POST', '/v3/transactions/' . rawurlencode($trace_id) . '/cancel', $payload, $idempotency_key);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, ?string $idempotency_key = null): array
    {
        $baseUrl = rtrim($this->settings->connect_base_url(), '/');
        $token = $this->connect_access_token();

        if ($baseUrl === '') {
            throw new RuntimeException('PayArc Connect base URL is not configured.');
        }

        if ($token === '') {
            throw new RuntimeException('PayArc Connect access token is not configured. Press Connect PayArc in the gateway settings.');
        }

        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        );

        if ($idempotency_key !== null && trim($idempotency_key) !== '') {
            $headers['X-Idempotency-Key'] = $idempotency_key;
        }

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

        $response = wp_remote_request($baseUrl . $path, $args);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            throw new RuntimeException('PayArc request failed before receiving a response.');
        }

        if (!is_array($response)) {
            throw new RuntimeException('PayArc response was not an array.');
        }

        $httpStatus = $this->http_status($response);
        $decoded = $this->decode_response_body($response, $httpStatus);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException($this->failure_message($decoded, $httpStatus));
        }

        if ($this->is_payarc_failure($decoded)) {
            throw new RuntimeException($this->failure_message($decoded, $httpStatus));
        }

        return $decoded;
    }


    private function connect_access_token(): string
    {
        $token = $this->settings->connect_access_token();
        $expiresAt = $this->settings->connect_token_expires_at();
        $now = time();

        if ($token !== '' && ($expiresAt === 0 || $expiresAt > $now + 60)) {
            return $token;
        }

        if (is_object($this->connection_service) && method_exists($this->connection_service, 'ensure_connect_access_token')) {
            $refreshed = $this->connection_service->ensure_connect_access_token();

            return is_scalar($refreshed) ? trim((string) $refreshed) : '';
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function http_status(array $response): int
    {
        if (isset($response['response']) && is_array($response['response']) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decode_response_body(array $response, int $httpStatus): array
    {
        $body = isset($response['body']) ? $response['body'] : '';

        if (!is_string($body) || trim($body) === '') {
            throw new RuntimeException('PayArc response body was empty. HTTP status: ' . $httpStatus . '.');
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('PayArc response was not valid JSON. HTTP status: ' . $httpStatus . '.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function is_payarc_failure(array $decoded): bool
    {
        $response = isset($decoded['response']) && is_array($decoded['response']) ? $decoded['response'] : array();
        $responseStatus = isset($response['status']) && is_scalar($response['status']) ? strtoupper((string) $response['status']) : '';
        $topLevelStatus = isset($decoded['status']) && is_scalar($decoded['status']) ? strtoupper((string) $decoded['status']) : '';

        return $responseStatus === 'FAILURE' || $topLevelStatus === 'FAILURE';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function failure_message(array $decoded, int $httpStatus): string
    {
        $parts = array('PayArc request failed');

        if ($httpStatus > 0) {
            $parts[] = 'HTTP status: ' . $httpStatus;
        }

        if (isset($decoded['traceId']) && is_scalar($decoded['traceId']) && (string) $decoded['traceId'] !== '') {
            $parts[] = 'traceId: ' . (string) $decoded['traceId'];
        }

        $error = $this->error_payload($decoded);

        foreach (array(
            'code' => 'code',
            'message' => 'message',
            'friendlyMessage' => 'friendlyMessage',
        ) as $key => $label) {
            if (isset($error[$key]) && is_scalar($error[$key]) && (string) $error[$key] !== '') {
                $parts[] = $label . ': ' . (string) $error[$key];
            }
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function error_payload(array $decoded): array
    {
        if (isset($decoded['response']) && is_array($decoded['response']) && isset($decoded['response']['error']) && is_array($decoded['response']['error'])) {
            return $decoded['response']['error'];
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            return $decoded['error'];
        }

        return array();
    }
}
