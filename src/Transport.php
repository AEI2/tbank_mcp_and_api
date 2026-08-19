<?php

declare(strict_types=1);

namespace Tbank\Invest;

final class HttpResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $needle) {
                return $value;
            }
        }

        return null;
    }
}

interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    public function post(string $url, array $headers, array $body, float $timeout): HttpResult;
}

final class CurlTransport implements Transport
{
    public function post(string $url, array $headers, array $body, float $timeout): HttpResult
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception\TInvestException('Failed to init curl', 500, null, null, 'http_error');
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => min(10, (int) ceil($timeout)),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new Exception\TInvestException(
                'T-Invest HTTP request failed: ' . ($error !== '' ? $error : 'unknown curl error'),
                502,
                ['errno' => $errno],
                null,
                'http_error',
            );
        }

        return new HttpResult($status, (string) $raw, $responseHeaders);
    }
}
