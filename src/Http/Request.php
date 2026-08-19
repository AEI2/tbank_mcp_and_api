<?php

declare(strict_types=1);

namespace Tbank\Invest\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $params
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly array $params = [],
        public readonly array $headers = [],
        public readonly string $rawBody = '',
    ) {
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $params, $this->headers, $this->rawBody);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
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

    public function acceptHeader(): string
    {
        return strtolower($this->header('Accept') ?? '');
    }

    public function wantsSse(): bool
    {
        return str_contains($this->acceptHeader(), 'text/event-stream');
    }

    public function wantsJson(): bool
    {
        $accept = $this->acceptHeader();

        return $accept === ''
            || str_contains($accept, 'application/json')
            || str_contains($accept, '*/*');
    }

    public function mcpSessionId(): ?string
    {
        $value = $this->header('Mcp-Session-Id') ?? $this->header('MCP-Session-Id');

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : self::headersFromServer();
        $raw = file_get_contents('php://input') ?: '';
        $body = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : null;
        }

        return new self($method, $path, $_GET, $body, [], $headers, $raw);
    }

    /** @return array<string, string> */
    private static function headersFromServer(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = (string) $value;
        }

        return $headers;
    }
}
