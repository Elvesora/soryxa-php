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
    public function validate(string $email): ValidationResult {
        try {
            $body = $this->post('/api/v1/validate', [
                'email' => $email,
            ]);

            return ValidationResult::fromResponse($body);
        } catch (UsageLimitException $e) {
            if ($this->silentOnLimit) {
                return ValidationResult::limitExceeded($email);
            }

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    //  HTTP Transport
    // -------------------------------------------------------------------------

    protected function get(string $path, array $query = []): array {
        $url = $this->url($path);

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $this->send('GET', $url);
    }

    protected function post(string $path, array $data = []): array {
        return $this->send('POST', $this->url($path), $data);
    }

    /**
     * @throws SoryxaException
     */
    protected function send(string $method, string $url, ?array $data = null): array {
        $attempt = 0;
        $maxAttempts = 1 + $this->retries;

        while (true) {
            $attempt++;

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token,
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? []));
            }

            $responseBody = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($errno) {
                throw ConnectionException::fromCurlError($errno, $error);
            }

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

            return $body;
        }
    }

    protected function url(string $path): string {
        return $this->baseUrl . $path;
    }
}
