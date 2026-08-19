<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use Tbank\Invest\HttpResult;
use Tbank\Invest\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<HttpResult> */
    private array $responses = [];

    public function queue(int $status, array|string $body, array $headers = []): self
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
        $this->responses[] = new HttpResult($status, $raw, $headers);

        return $this;
    }

    public function post(string $url, array $headers, array $body, float $timeout): HttpResult
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            return new HttpResult(200, '{}');
        }

        return array_shift($this->responses);
    }

    public function lastUrl(): string
    {
        return $this->requests[array_key_last($this->requests)]['url'] ?? '';
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        return $this->requests[array_key_last($this->requests)]['body'] ?? [];
    }
}
