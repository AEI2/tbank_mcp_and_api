<?php

declare(strict_types=1);

namespace Tbank\Invest\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly mixed $body,
        public readonly int $status = 200,
        public readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }

    public static function json(mixed $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function empty(int $status = 204, array $headers = []): self
    {
        return new self(null, $status, $headers);
    }

    public static function sse(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $headers + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (str_contains(strtolower($this->headers['Content-Type'] ?? ''), 'text/event-stream')) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
        if ($this->body === null) {
            return;
        }
        if (is_string($this->body)) {
            echo $this->body;
            flush();

            return;
        }
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
