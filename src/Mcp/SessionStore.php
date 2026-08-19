<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

interface SessionStore
{
    public function create(array $data = []): string;

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array;

    public function delete(string $id): void;
}

final class MemorySessionStore implements SessionStore
{
    /** @var array<string, array{expires: int, data: array<string, mixed>}> */
    private array $sessions = [];

    public function __construct(private readonly int $ttlSeconds = 3600)
    {
    }

    public function create(array $data = []): string
    {
        $id = bin2hex(random_bytes(16));
        $this->sessions[$id] = [
            'expires' => time() + $this->ttlSeconds,
            'data' => $data,
        ];

        return $id;
    }

    public function get(string $id): ?array
    {
        $row = $this->sessions[$id] ?? null;
        if ($row === null) {
            return null;
        }
        if ($row['expires'] < time()) {
            unset($this->sessions[$id]);

            return null;
        }

        return $row['data'];
    }

    public function delete(string $id): void
    {
        unset($this->sessions[$id]);
    }
}

final class FileSessionStore implements SessionStore
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 3600,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    public function create(array $data = []): string
    {
        $id = bin2hex(random_bytes(16));
        $this->write($id, $data);

        return $id;
    }

    public function get(string $id): ?array
    {
        if (!$this->validId($id)) {
            return null;
        }
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $expires = (int) ($decoded['expires'] ?? 0);
        if ($expires < time()) {
            @unlink($path);

            return null;
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    public function delete(string $id): void
    {
        if (!$this->validId($id)) {
            return;
        }
        $path = $this->path($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $data */
    private function write(string $id, array $data): void
    {
        $payload = json_encode([
            'expires' => time() + $this->ttlSeconds,
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
        file_put_contents($this->path($id), $payload, LOCK_EX);
    }

    private function path(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
    }
}
