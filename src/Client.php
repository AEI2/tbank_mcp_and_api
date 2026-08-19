<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\TInvestException;
use Tbank\Invest\Exception\TradingDisabledException;

final class Client
{
    private RateLimiter $limiter;

    private Clock $clock;

    public function __construct(
        public readonly Config $config,
        private readonly Transport $transport = new CurlTransport(),
        ?RateLimiter $rateLimiter = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->limiter = $rateLimiter ?? ($config->rateLimitEnabled
            ? SlidingWindowLimiter::file($config, $this->clock)
            : new NullRateLimiter());
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|list<mixed>
     */
    public function call(
        string $service,
        string $method,
        ?array $body = null,
        bool $normalizeResponse = true,
        bool $allowUnknown = false,
    ): array {
        $spec = $this->lookup($service, $method, $allowUnknown);
        if ($spec->mutating && !$this->config->allowTrading) {
            throw new TradingDisabledException($spec->service, $spec->method);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->config->requireToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-app-name' => $this->config->appName,
        ];
        $payload = Normalize::dropNulls($body ?? []);
        if (!is_array($payload)) {
            $payload = [];
        }

        $url = $this->config->restBaseUrl() . $spec->path();
        $attempt = 0;
        $maxRetries = max(0, $this->config->rateLimitRetries);
        while (true) {
            $this->limiter->acquire($spec->service, $spec->method);
            $response = $this->transport->post($url, $headers, $payload, $this->config->requestTimeout);
            $trackingId = $response->header('x-tracking-id');
            if ($response->status < 400) {
                break;
            }
            if ($attempt < $maxRetries && self::isRateLimitResponse($response)) {
                $attempt++;
                $reset = (float) ($response->header('x-ratelimit-reset') ?? $response->header('Retry-After') ?? 1);
                $this->clock->sleep(max(0.25, $reset));
                continue;
            }
            throw $this->errorFromResponse($response, $trackingId);
        }

        if ($response->body === '') {
            $data = [];
        } else {
            try {
                $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new TInvestException(
                    'T-Invest returned a non-JSON response',
                    $response->status,
                    $response->body,
                    $trackingId,
                );
            }
            if (!is_array($data)) {
                $data = ['value' => $data];
            }
        }

        $normalized = $normalizeResponse ? Normalize::values($data) : $data;

        return is_array($normalized) ? $normalized : ['value' => $normalized];
    }

    private function lookup(string $service, string $method, bool $allowUnknown): MethodSpec
    {
        $service = trim($service);
        $method = trim($method);
        if (str_starts_with($service, Config::PROTO_PREFIX)) {
            $parts = explode('.', $service);
            $service = (string) end($parts);
        }
        try {
            return Catalog::resolve($service, $method);
        } catch (TInvestException $e) {
            if (!$allowUnknown) {
                throw $e;
            }

            return new MethodSpec($service, $method, 'Unlisted T-Invest method', false, $service);
        }
    }

    private function errorFromResponse(HttpResult $response, ?string $trackingId): TInvestException
    {
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = ['message' => $response->body];
        }

        $message = null;
        $code = null;
        if (is_array($payload)) {
            $message = $payload['message'] ?? $payload['error'] ?? $payload['title'] ?? null;
            $code = $payload['code'] ?? $payload['description'] ?? null;
            $details = $payload['details'] ?? null;
            if (is_array($details) && $details !== [] && is_array($details[0] ?? null)) {
                $message = $message ?? ($details[0]['message'] ?? null);
            }
        }
        if (!$message) {
            $message = $response->header('message') ?: ('T-Invest HTTP ' . $response->status);
        }

        return new TInvestException((string) $message, $response->status, $payload, $trackingId, is_scalar($code) ? $code : null);
    }

    private static function isRateLimitResponse(HttpResult $response): bool
    {
        if (in_array($response->status, [429, 503], true)) {
            return true;
        }
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($payload)) {
            return false;
        }
        $code = $payload['code'] ?? $payload['description'] ?? null;

        return $code === 8 || $code === '8';
    }
}
