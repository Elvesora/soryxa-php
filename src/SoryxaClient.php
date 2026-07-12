<?php

namespace Elvesora\SoryxaPHP;

use Elvesora\SoryxaPHP\Exceptions\ConnectionException;
use Elvesora\SoryxaPHP\Exceptions\SoryxaException;
use Elvesora\SoryxaPHP\Exceptions\UsageLimitException;
use Elvesora\SoryxaPHP\Responses\ValidationResult;

class SoryxaClient {
    protected string $baseUrl;
    protected string $token;
    protected int $timeout;
    protected int $retries;
    protected int $retryDelay;
    protected bool $silentOnLimit;

    public function __construct(
        string $token,
        string $baseUrl = 'https://soryxa.elvesora.com',
        int $timeout = 30,
        int $retries = 0,
        int $retryDelay = 100,
        bool $silentOnLimit = false,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->retryDelay = $retryDelay;
        $this->silentOnLimit = $silentOnLimit;
    }

    // -------------------------------------------------------------------------
    //  Validation
    // -------------------------------------------------------------------------

    /**
     * Validate a single email address.
     *
     * @throws SoryxaException
     */
    public function validate(
        string $email,
        ?string $policyKey = null,
        array $headers = [],
    ): ValidationResult {
        try {
            $response = $this->send(
                'POST',
                $this->url('/api/v1/validate'),
                $this->validationPayload($email, $policyKey),
                $headers,
            );

            return ValidationResult::fromResponse($response['body'], $response['headers']);
        } catch (UsageLimitException $e) {
            if ($this->silentOnLimit) {
                return ValidationResult::limitExceeded($email);
            }

            throw $e;
        }
    }

    protected function validationPayload(string $email, ?string $policyKey = null): array {
        $payload = ['email' => $email];

        if ($policyKey !== null) {
            $payload['policy_key'] = $policyKey;
        }

        return $payload;
    }

    // -------------------------------------------------------------------------
    //  HTTP Transport
    // -------------------------------------------------------------------------

    protected function get(string $path, array $query = [], array $headers = []): array {
        $url = $this->url($path);

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $this->send('GET', $url, null, $headers)['body'];
    }

    protected function post(string $path, array $data = [], array $headers = []): array {
        return $this->send('POST', $this->url($path), $data, $headers)['body'];
    }

    /**
     * @throws SoryxaException
     */
    protected function send(string $method, string $url, ?array $data = null, array $headers = []): array {
        $attempt = 0;
        $maxAttempts = 1 + $this->retries;

        while (true) {
            $attempt++;

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? []));
            }

            $rawResponse = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            curl_close($ch);

            if ($errno) {
                throw ConnectionException::fromCurlError($errno, $error);
            }

            $rawResponse = is_string($rawResponse) ? $rawResponse : '';
            $rawHeaders = substr($rawResponse, 0, $headerSize) ?: '';
            $responseBody = substr($rawResponse, $headerSize) ?: '';
            $responseHeaders = $this->parseHeaders($rawHeaders);

            // Retry on 5xx if attempts remain
            if ($statusCode >= 500 && $attempt < $maxAttempts) {
                usleep($this->retryDelay * 1000);
                continue;
            }

            $body = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ConnectionException(
                    message: 'Failed to decode Soryxa API response: ' . json_last_error_msg(),
                    errorCode: 'INVALID_RESPONSE',
                    statusCode: $statusCode,
                );
            }

            if ($statusCode >= 400) {
                throw SoryxaException::fromResponse($statusCode, $body);
            }

            return [
                'body' => $body,
                'headers' => $responseHeaders,
            ];
        }
    }

    protected function buildHeaders(array $headers = []): array {
        $headerLines = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            $headerLines[] = $name . ': ' . $value;
        }

        return $headerLines;
    }

    protected function parseHeaders(string $rawHeaders): array {
        $headers = [];
        $blocks = preg_split("/\r\n\r\n/", trim($rawHeaders));
        $lastBlock = $blocks ? (string) end($blocks) : '';

        foreach (preg_split("/\r\n/", $lastBlock) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }

    protected function url(string $path): string {
        return $this->baseUrl . $path;
    }
}
