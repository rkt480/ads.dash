<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationNotConfigured.php';

final class ServerHttpClient
{
    public function getJson(string $url, array $headers, int $timeoutSeconds = 25): array
    {
        return $this->send($url, 'GET', $headers, null, $timeoutSeconds);
    }

    public function postJson(string $url, array $headers, array $body): array
    {
        $headers[] = 'Content-Type: application/json';
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new UpstreamApiFailure('request_encoding_failed');
        }

        return $this->send($url, 'POST', $headers, $encoded);
    }

    public function postForm(string $url, array $fields): array
    {
        return $this->send(
            $url,
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function send(string $url, string $method, array $headers, ?string $body, int $timeoutSeconds = 25): array
    {
        if (!function_exists('curl_init')) {
            throw new UpstreamApiFailure('curl_extension_missing');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new UpstreamApiFailure('https_required');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new UpstreamApiFailure('http_client_unavailable');
        }

        $timeoutSeconds = max(1, min(25, $timeoutSeconds));
        $connectTimeoutSeconds = $timeoutSeconds < 25 ? min(4, $timeoutSeconds) : 8;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $raw === false;
        curl_close($handle);

        if ($failed || $status < 200 || $status >= 300 || !is_string($raw)) {
            throw new UpstreamApiFailure('upstream_request_failed');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new UpstreamApiFailure('upstream_response_invalid');
        }

        return $decoded;
    }
}
